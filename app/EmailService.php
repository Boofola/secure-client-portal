<?php
/**
 * Titan Email (IMAP / POP / SMTP) Client & Branded HTML Template Engine
 * Secure Cloud Portal Stand-Alone v3.1.0
 */

if (!defined('SCP_APP_INIT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

class SCP_EmailService {
    private $db;
    private $imapStream = null;
    private $imapTagCounter = 0;

    public function __construct() {
        $this->db = SCP_Database::getInstance();
        $this->ensureTables();
    }

    /**
     * Ensure email tables exist in database
     */
    private function ensureTables() {
        if (!$this->db->isConfigured()) {
            return;
        }
        try {
            $pdo = $this->db->getConnection();
            $pdo->exec("CREATE TABLE IF NOT EXISTS scp_email_messages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED DEFAULT NULL,
                inquiry_id INT UNSIGNED DEFAULT NULL,
                folder VARCHAR(32) NOT NULL DEFAULT 'inbox',
                direction ENUM('inbound', 'outbound') NOT NULL DEFAULT 'inbound',
                imap_uid INT UNSIGNED DEFAULT NULL,
                imap_folder VARCHAR(64) DEFAULT 'INBOX',
                message_id VARCHAR(255) DEFAULT '',
                in_reply_to VARCHAR(255) DEFAULT '',
                from_email VARCHAR(191) NOT NULL,
                from_name VARCHAR(191) DEFAULT '',
                to_email VARCHAR(191) NOT NULL,
                to_name VARCHAR(191) DEFAULT '',
                attention_name VARCHAR(191) DEFAULT '',
                subject VARCHAR(500) NOT NULL DEFAULT '',
                snippet VARCHAR(500) DEFAULT '',
                body_text LONGTEXT,
                body_html LONGTEXT,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                is_starred TINYINT(1) NOT NULL DEFAULT 0,
                replied_at DATETIME DEFAULT NULL,
                email_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_folder_date (folder, email_date),
                INDEX idx_user_id (user_id),
                INDEX idx_from_email (from_email),
                INDEX idx_to_email (to_email),
                INDEX idx_imap_uid (imap_folder, imap_uid),
                INDEX idx_message_id (message_id(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            $pdo->exec("CREATE TABLE IF NOT EXISTS scp_email_deleted_uids (
                imap_folder VARCHAR(64) NOT NULL,
                imap_uid INT UNSIGNED NOT NULL,
                deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (imap_folder, imap_uid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Exception $e) {
            // Ignore during initial setup
        }
    }

    /**
     * Get resolved email server configuration
     */
    public function getConfig() {
        $defaultHost = parse_url(SCP_Security::getBaseUrl(), PHP_URL_HOST) ?: 'localhost';
        $email = trim($this->db->getSetting('mail_email', defined('SCP_MAIL_EMAIL') ? SCP_MAIL_EMAIL : ''));
        $pass  = trim($this->db->getSetting('mail_password', ''));
        if ($pass === '' && defined('SCP_MAIL_PASSWORD')) {
            $pass = SCP_MAIL_PASSWORD;
        }
        $fromName = trim($this->db->getSetting('mail_from_name', defined('SCP_MAIL_FROM_NAME') ? SCP_MAIL_FROM_NAME : $this->db->getSetting('company_name', 'Cloud Portal')));
        $imapHost = trim($this->db->getSetting('mail_imap_host', defined('SCP_MAIL_IMAP_HOST') ? SCP_MAIL_IMAP_HOST : ''));
        $imapPort = (int)$this->db->getSetting('mail_imap_port', defined('SCP_MAIL_IMAP_PORT') ? SCP_MAIL_IMAP_PORT : 993);
        $popHost  = trim($this->db->getSetting('mail_pop_host', defined('SCP_MAIL_POP_HOST') ? SCP_MAIL_POP_HOST : ''));
        $popPort  = (int)$this->db->getSetting('mail_pop_port', defined('SCP_MAIL_POP_PORT') ? SCP_MAIL_POP_PORT : 995);
        $smtpHost = trim($this->db->getSetting('mail_smtp_host', defined('SCP_MAIL_SMTP_HOST') ? SCP_MAIL_SMTP_HOST : ''));
        $smtpPort = (int)$this->db->getSetting('mail_smtp_port', defined('SCP_MAIL_SMTP_PORT') ? SCP_MAIL_SMTP_PORT : 465);
        $enc      = strtolower(trim($this->db->getSetting('mail_encryption', defined('SCP_MAIL_ENCRYPTION') ? SCP_MAIL_ENCRYPTION : 'ssl')));

        return array(
            'email'      => $email ?: ('support@' . $defaultHost),
            'password'   => $pass,
            'from_name'  => $fromName ?: 'Cloud Portal',
            'imap_host'  => $imapHost,
            'imap_port'  => $imapPort > 0 ? $imapPort : 993,
            'pop_host'   => $popHost,
            'pop_port'   => $popPort > 0 ? $popPort : 995,
            'smtp_host'  => $smtpHost,
            'smtp_port'  => $smtpPort > 0 ? $smtpPort : 465,
            'encryption' => $enc ?: 'ssl',
        );
    }

    /**
     * Load raw HTML email template
     */
    public function getHtmlTemplateRaw() {
        $templateFile = SCP_ROOT_DIR . '/views/emails/client-notification-template.html';
        if (file_exists($templateFile)) {
            $html = file_get_contents($templateFile);
            if ($html !== false && trim($html) !== '') {
                return $html;
            }
        }
        return '<!DOCTYPE html><html><body><div><h2>Account Notification</h2><h3>{EMAIL_SUBJECT}</h3><p>Dear {EMAIL_ATTENTION},</p><div>{EMAIL_MESSAGE}</div><p>Message Info: {EMAIL_DATE} at {EMAIL_TIME} | Sent From: {PORTAL_SUPPORT_EMAIL}</p></div></body></html>';
    }

    /**
     * Format plain-text or lightweight HTML message body for {EMAIL_MESSAGE}
     */
    public function formatMessageBodyForTemplate($rawMessage) {
        $msg = trim((string)$rawMessage);
        if ($msg === '') {
            return '';
        }
        // Check if message contains intentional safe HTML formatting or is plain text
        $stripped = strip_tags($msg);
        if ($stripped === $msg) {
            // Pure plain text: escape HTML entities and convert newlines to <br />
            $escaped = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
            // Auto-link bare https:// URLs
            $escaped = preg_replace(
                '#(https?://[^\s<>"\']+)#i',
                '<a href="$1" target="_blank" rel="noopener" style="color:#019BDB;text-decoration:underline;">$1</a>',
                $escaped
            );
            return nl2br($escaped);
        }

        // Allow safe formatting tags while stripping scripts/iframes/handlers
        $allowed = '<p><br><br/><b><strong><i><em><u><a><ul><ol><li><span><div><blockquote><hr><h3><h4><table><thead><tbody><tr><th><td>';
        $clean = strip_tags($msg, $allowed);
        $clean = preg_replace('/\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
        $clean = preg_replace('/javascript\s*:/i', '', $clean);
        // If there are no block/line-break tags, convert newlines to <br />
        if (stripos($clean, '<br') === false && stripos($clean, '<p') === false) {
            $clean = nl2br($clean);
        }
        return $clean;
    }

    /**
     * Render the official HTML Email Template with {EMAIL_*} variables populated
     *
     * Supported template variables:
     *   {EMAIL_SUBJECT}   = Email subject entered manually by admin
     *   {EMAIL_ATTENTION} = Client's name that the email is being sent to
     *   {EMAIL_MESSAGE}   = Email message entered manually by admin
     *   {EMAIL_DATE}      = Date that the email is sent
     *   {EMAIL_TIME}      = Time that the email is sent
     *   {EMAIL_SENDTO}    = The email of the client that the email is being sent to
     */
    public function renderClientEmailTemplate($subject, $attentionName, $messageBody, $sendToEmail, $timestamp = null, $quotedHtml = '') {
        if ($timestamp === null) {
            $timestamp = time();
        }
        $dateStr = date('F j, Y', $timestamp);
        $timeStr = date('g:i A T', $timestamp);

        $attentionClean = trim((string)$attentionName);
        if ($attentionClean === '') {
            $attentionClean = 'Valued Client';
        }

        $formattedMessage = $this->formatMessageBodyForTemplate($messageBody);
        if ($quotedHtml !== '') {
            $formattedMessage .= '<div style="margin-top:18px;padding-top:12px;border-top:1px dashed #cbd5e1;color:#475569;font-size:11.5px;">' . $quotedHtml . '</div>';
        }

        // Allow variables inside the message text itself as well
        $inlineVars = array(
            '{EMAIL_SUBJECT}'   => htmlspecialchars((string)$subject, ENT_QUOTES, 'UTF-8'),
            '{EMAIL_ATTENTION}' => htmlspecialchars($attentionClean, ENT_QUOTES, 'UTF-8'),
            '{EMAIL_DATE}'      => htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8'),
            '{EMAIL_TIME}'      => htmlspecialchars($timeStr, ENT_QUOTES, 'UTF-8'),
            '{EMAIL_SENDTO}'    => htmlspecialchars((string)$sendToEmail, ENT_QUOTES, 'UTF-8'),
        );
        $formattedMessage = strtr($formattedMessage, $inlineVars);

        $baseUrl = SCP_Security::getBaseUrl();
        $companyName = $this->db->getSetting('company_name', 'Cloud Portal');
        $supportEmail = $this->db->getSetting('support_email', 'support@example.com');
        $supportPhone = $this->db->getSetting('support_phone', '');
        $siteLogoUrl = $this->db->getSetting('site_logo_url', '');
        if (!empty($siteLogoUrl) && strpos($siteLogoUrl, 'http') !== 0) {
            $headerImageUrl = rtrim($baseUrl, '/') . '/' . ltrim($siteLogoUrl, '/');
        } elseif (!empty($siteLogoUrl)) {
            $headerImageUrl = $siteLogoUrl;
        } else {
            $headerImageUrl = rtrim($baseUrl, '/') . '/assets/secure-cloud-portal-email-header.png';
        }

        $replacements = array(
            '{EMAIL_SUBJECT}'          => htmlspecialchars((string)$subject, ENT_QUOTES, 'UTF-8'),
            '{EMAIL_ATTENTION}'        => htmlspecialchars($attentionClean, ENT_QUOTES, 'UTF-8'),
            '{EMAIL_MESSAGE}'          => $formattedMessage,
            '{EMAIL_DATE}'             => htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8'),
            '{EMAIL_TIME}'             => htmlspecialchars($timeStr, ENT_QUOTES, 'UTF-8'),
            '{EMAIL_SENDTO}'           => htmlspecialchars((string)$sendToEmail, ENT_QUOTES, 'UTF-8'),
            '{PORTAL_NAME}'            => htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'),
            '{PORTAL_URL}'             => htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'),
            '{PORTAL_LOGIN_URL}'       => htmlspecialchars($baseUrl . '/index.php?page=login', ENT_QUOTES, 'UTF-8'),
            '{PORTAL_CONTACT_URL}'     => htmlspecialchars($baseUrl . '/index.php?page=contact', ENT_QUOTES, 'UTF-8'),
            '{PORTAL_PRIVACY_URL}'     => htmlspecialchars($baseUrl . '/index.php?page=privacy', ENT_QUOTES, 'UTF-8'),
            '{PORTAL_TERMS_URL}'       => htmlspecialchars($baseUrl . '/index.php?page=terms', ENT_QUOTES, 'UTF-8'),
            '{PORTAL_SUPPORT_EMAIL}'   => htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'),
            '{PORTAL_SUPPORT_PHONE}'   => htmlspecialchars($supportPhone, ENT_QUOTES, 'UTF-8'),
            '{PORTAL_HEADER_IMAGE_URL}'=> htmlspecialchars($headerImageUrl, ENT_QUOTES, 'UTF-8'),
        );

        $template = $this->getHtmlTemplateRaw();
        return strtr($template, $replacements);
    }

    /**
     * Build plain-text companion version of the email
     */
    public function renderClientEmailPlainText($subject, $attentionName, $messageBody, $sendToEmail, $timestamp = null) {
        if ($timestamp === null) {
            $timestamp = time();
        }
        $dateStr = date('F j, Y', $timestamp);
        $timeStr = date('g:i A T', $timestamp);
        $attentionClean = trim((string)$attentionName) ?: 'Valued Client';
        $plainMsg = trim(strip_tags((string)$messageBody));

        $baseUrl = SCP_Security::getBaseUrl();
        $companyName = $this->db->getSetting('company_name', 'Cloud Portal');
        $supportEmail = $this->db->getSetting('support_email', 'support@example.com');
        $supportPhone = $this->db->getSetting('support_phone', '');

        $lines = array(
            "ACCOUNT NOTIFICATION — " . strtoupper($companyName),
            "------------------------------------------------------------",
            "Subject: " . $subject,
            "",
            "Dear " . $attentionClean . ",",
            "",
            $plainMsg,
            "",
            "Take Me to the Portal: " . $baseUrl . "/index.php?page=login",
            "",
            "------------------------------------------------------------",
            $companyName . " | Email: " . $supportEmail . ($supportPhone ? " | Phone: " . $supportPhone : ""),
            "Message Info: " . $dateStr . " at " . $timeStr . " | Sent From: " . $supportEmail,
            "Recipient: " . $sendToEmail,
            "Attention: No one from " . $companyName . " will ever ask you for your password or any other sensitive information."
        );
        return implode("\r\n", $lines);
    }

    /**
     * Lookup client info by email or user_id
     */
    public function resolveClientRecipient($toEmail, $userId = 0, $attentionName = '') {
        $pdo = $this->db->getConnection();
        $userRow = null;

        if ($userId > 0) {
            $stmt = $pdo->prepare("SELECT id, email, first_name, last_name, company FROM scp_users WHERE id = ? LIMIT 1");
            $stmt->execute(array((int)$userId));
            $userRow = $stmt->fetch();
        }
        if (!$userRow && $toEmail !== '') {
            $stmt = $pdo->prepare("SELECT id, email, first_name, last_name, company FROM scp_users WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->execute(array(trim($toEmail)));
            $userRow = $stmt->fetch();
        }

        $resolvedUserId = $userRow ? (int)$userRow['id'] : null;
        $resolvedEmail  = trim($toEmail) ?: ($userRow['email'] ?? '');
        $resolvedName   = trim($attentionName);

        if ($resolvedName === '' && $userRow) {
            $fullName = trim(($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? ''));
            $resolvedName = $fullName ?: ($userRow['company'] ?: '');
        }
        if ($resolvedName === '' && $resolvedEmail !== '') {
            $parts = explode('@', $resolvedEmail);
            $resolvedName = ucfirst(preg_replace('/[._-]+/', ' ', $parts[0]));
        }
        if ($resolvedName === '') {
            $resolvedName = 'Valued Client';
        }

        return array(
            'user_id'        => $resolvedUserId,
            'email'          => $resolvedEmail,
            'attention_name' => $resolvedName,
        );
    }

    /**
     * Send an email to a client using the HTML template via Titan SMTP (smtp.titan.email:465 SSL/TLS)
     * and record it in scp_email_messages (and optionally append to IMAP Sent folder)
     */
    public function sendClientEmail($params) {
        $toEmail       = trim((string)($params['to_email'] ?? ''));
        $subject       = trim((string)($params['subject'] ?? ''));
        $messageBody   = trim((string)($params['message'] ?? ''));
        $attentionName = trim((string)($params['attention_name'] ?? ''));
        $userId        = (int)($params['user_id'] ?? 0);
        $replyToId     = (int)($params['reply_to_id'] ?? 0);
        $inquiryId     = (int)($params['inquiry_id'] ?? 0);
        $includeQuoted = !empty($params['include_quoted']);

        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return array('error' => 'Please provide a valid recipient email address.');
        }
        if ($subject === '') {
            return array('error' => 'Please enter an email subject.');
        }
        if ($messageBody === '') {
            return array('error' => 'Please enter an email message.');
        }

        $clientInfo    = $this->resolveClientRecipient($toEmail, $userId, $attentionName);
        $resolvedUid   = $clientInfo['user_id'];
        $resolvedEmail = $clientInfo['email'];
        $resolvedAttn  = $clientInfo['attention_name'];

        $inReplyToHeader = '';
        $referencesHeader = '';
        $quotedHtml = '';

        if ($replyToId > 0) {
            $orig = $this->getMessageById($replyToId);
            if ($orig) {
                if (!empty($orig['message_id'])) {
                    $inReplyToHeader = $orig['message_id'];
                    $referencesHeader = trim(($orig['in_reply_to'] ? $orig['in_reply_to'] . ' ' : '') . $orig['message_id']);
                }
                $origCounterpart = ($orig['direction'] === 'inbound') ? $orig['from_email'] : $orig['to_email'];
                if (!$resolvedUid && !empty($orig['user_id']) && strcasecmp((string)$origCounterpart, $resolvedEmail) === 0) {
                    $resolvedUid = (int)$orig['user_id'];
                }
                if ($includeQuoted) {
                    $origDate = !empty($orig['email_date']) ? date('M j, Y g:i A', strtotime($orig['email_date'])) : '';
                    $origSender = htmlspecialchars(($orig['from_name'] ? $orig['from_name'] . ' <' . $orig['from_email'] . '>' : $orig['from_email']), ENT_QUOTES, 'UTF-8');
                    $origText = htmlspecialchars(trim((string)($orig['body_text'] ?: strip_tags((string)$orig['body_html']))), ENT_QUOTES, 'UTF-8');
                    $quotedHtml = '<strong>On ' . htmlspecialchars($origDate, ENT_QUOTES, 'UTF-8') . ', ' . $origSender . ' wrote:</strong><br /><blockquote style="margin:8px 0 0 0;padding-left:10px;border-left:2px solid #94a3b8;color:#64748b;">' . nl2br($origText) . '</blockquote>';
                }
            }
        }

        $nowTs     = time();
        $htmlBody  = $this->renderClientEmailTemplate($subject, $resolvedAttn, $messageBody, $resolvedEmail, $nowTs, $quotedHtml);
        $plainBody = $this->renderClientEmailPlainText($subject, $resolvedAttn, $messageBody, $resolvedEmail, $nowTs);

        $cfg       = $this->getConfig();
        $fromEmail = $cfg['email'];
        $fromName  = $cfg['from_name'];
        $domainPart = parse_url(SCP_Security::getBaseUrl(), PHP_URL_HOST) ?: 'localhost';
        $msgId     = sprintf('<%s.%s@%s>', bin2hex(random_bytes(8)), $nowTs, $domainPart);

        $rawMime = $this->buildMultipartMimeMessage(
            $fromEmail,
            $fromName,
            $resolvedEmail,
            $resolvedAttn,
            $subject,
            $plainBody,
            $htmlBody,
            $msgId,
            $inReplyToHeader,
            $referencesHeader,
            $nowTs
        );

        // Send over SMTP SSL/TLS (smtp.titan.email:465)
        $smtpRes = $this->sendRawViaSmtp($fromEmail, $resolvedEmail, $rawMime);
        if (!empty($smtpRes['error'])) {
            return array('error' => $smtpRes['error']);
        }

        // Best-effort append to IMAP Sent folder so external mail clients also see the sent email
        $this->appendSentMessageToImap($rawMime);

        // Save to local database scp_email_messages
        $pdo = $this->db->getConnection();
        $snippet = mb_substr(preg_replace('/\s+/', ' ', strip_tags($messageBody)), 0, 220);

        $stmt = $pdo->prepare(
            "INSERT INTO scp_email_messages
                (user_id, inquiry_id, folder, direction, imap_uid, imap_folder, message_id, in_reply_to,
                 from_email, from_name, to_email, to_name, attention_name, subject, snippet,
                 body_text, body_html, is_read, is_starred, email_date, created_at)
             VALUES (?, ?, 'sent', 'outbound', NULL, 'Sent', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, NOW())"
        );
        $stmt->execute(array(
            $resolvedUid,
            $inquiryId > 0 ? $inquiryId : null,
            $msgId,
            $inReplyToHeader,
            $fromEmail,
            $fromName,
            $resolvedEmail,
            $resolvedAttn,
            $resolvedAttn,
            $subject,
            $snippet,
            $messageBody,
            $htmlBody,
            date('Y-m-d H:i:s', $nowTs)
        ));
        $insertedId = (int)$pdo->lastInsertId();

        if ($replyToId > 0) {
            $upd = $pdo->prepare("UPDATE scp_email_messages SET is_read = 1, replied_at = NOW() WHERE id = ?");
            $upd->execute(array($replyToId));
        }

        if ($inquiryId > 0) {
            $updInq = $pdo->prepare("UPDATE scp_contact_messages SET status = 'replied' WHERE id = ?");
            $updInq->execute(array($inquiryId));
        }

        return array(
            'success'    => true,
            'email_id'   => $insertedId,
            'message_id' => $msgId,
            'to_email'   => $resolvedEmail,
            'attention'  => $resolvedAttn,
            'message'    => sprintf('Email "%s" sent to %s (%s) successfully!', $subject, $resolvedAttn, $resolvedEmail),
        );
    }

    /**
     * Construct a standards-compliant RFC 5322 multipart/alternative MIME message
     */
    private function buildMultipartMimeMessage($fromEmail, $fromName, $toEmail, $toName, $subject, $plainBody, $htmlBody, $messageId, $inReplyTo = '', $references = '', $timestamp = null) {
        if ($timestamp === null) {
            $timestamp = time();
        }
        $boundary = '=_SCP_Boundary_' . bin2hex(random_bytes(12));

        $encodedFromName = $this->encodeMimeHeader($fromName);
        $encodedToName   = $this->encodeMimeHeader($toName);
        $encodedSubject  = $this->encodeMimeHeader($subject);

        $headers = array();
        $headers[] = 'Date: ' . date('r', $timestamp);
        $headers[] = sprintf('From: %s <%s>', $encodedFromName, $fromEmail);
        $headers[] = sprintf('To: %s <%s>', $encodedToName, $toEmail);
        $headers[] = sprintf('Reply-To: %s <%s>', $encodedFromName, $fromEmail);
        $headers[] = 'Subject: ' . $encodedSubject;
        $headers[] = 'Message-ID: ' . $messageId;
        if ($inReplyTo !== '') {
            $headers[] = 'In-Reply-To: ' . $inReplyTo;
        }
        if ($references !== '') {
            $headers[] = 'References: ' . $references;
        }
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: CloudPortal/' . (defined('SCP_VERSION') ? SCP_VERSION : '3.5.0');
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $normPlain = str_replace(array("\r\n", "\r"), "\n", $plainBody);
        $normPlain = str_replace("\n", "\r\n", $normPlain);

        $normHtml = str_replace(array("\r\n", "\r"), "\n", $htmlBody);
        $normHtml = str_replace("\n", "\r\n", $normHtml);

        $bodyParts = array();
        $bodyParts[] = '--' . $boundary;
        $bodyParts[] = 'Content-Type: text/plain; charset=UTF-8';
        $bodyParts[] = 'Content-Transfer-Encoding: base64';
        $bodyParts[] = '';
        $bodyParts[] = chunk_split(base64_encode($normPlain), 76, "\r\n");

        $bodyParts[] = '--' . $boundary;
        $bodyParts[] = 'Content-Type: text/html; charset=UTF-8';
        $bodyParts[] = 'Content-Transfer-Encoding: base64';
        $bodyParts[] = '';
        $bodyParts[] = chunk_split(base64_encode($normHtml), 76, "\r\n");
        $bodyParts[] = '--' . $boundary . '--';
        $bodyParts[] = '';

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $bodyParts);
    }

    /**
     * Encode header value using UTF-8 Base64 if non-ASCII characters or special chars exist
     */
    private function encodeMimeHeader($str) {
        $clean = str_replace(array("\r", "\n"), ' ', trim((string)$str));
        if ($clean === '') {
            return '';
        }
        if (preg_match('/[^\x20-\x7E]/', $clean)) {
            return '=?UTF-8?B?' . base64_encode($clean) . '?=';
        }
        // Quote display names if they contain special RFC 5322 characters
        if (preg_match('/[()<>\[\]:;@\\\\,"]/', $clean)) {
            return '"' . addcslashes($clean, '"\\') . '"';
        }
        return $clean;
    }

    /**
     * Send raw MIME message over SMTP with SSL/TLS (smtp.titan.email:465)
     */
    private function sendRawViaSmtp($fromEmail, $toEmail, $rawMime) {
        $cfg  = $this->getConfig();
        $host = $cfg['smtp_host'];
        $port = (int)$cfg['smtp_port'];
        $user = $cfg['email'];
        $pass = $cfg['password'];
        $enc  = $cfg['encryption'];

        $remote = ($enc === 'ssl' || $port === 465) ? 'ssl://' . $host . ':' . $port : 'tcp://' . $host . ':' . $port;
        $context = stream_context_create(array(
            'ssl' => array(
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            )
        ));

        $errno  = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            return array('error' => sprintf('SMTP connection to %s:%d failed: %s (%d)', $host, $port, $errstr, $errno));
        }

        stream_set_timeout($fp, 20);

        $greeting = $this->readSmtpResponse($fp);
        if (strpos($greeting, '220') !== 0) {
            fclose($fp);
            return array('error' => 'Unexpected SMTP greeting: ' . trim($greeting));
        }

        $ehloHost = parse_url(SCP_Security::getBaseUrl(), PHP_URL_HOST) ?: 'localhost';
        fwrite($fp, "EHLO " . $ehloHost . "\r\n");
        $ehlo = $this->readSmtpResponse($fp);
        if (strpos($ehlo, '250') !== 0) {
            fclose($fp);
            return array('error' => 'SMTP EHLO failed: ' . trim($ehlo));
        }

        if ($enc === 'tls' && $port !== 465) {
            fwrite($fp, "STARTTLS\r\n");
            $tlsResp = $this->readSmtpResponse($fp);
            if (strpos($tlsResp, '220') !== 0) {
                fclose($fp);
                return array('error' => 'SMTP STARTTLS failed: ' . trim($tlsResp));
            }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp);
                return array('error' => 'Failed to negotiate TLS encryption with SMTP server.');
            }
            fwrite($fp, "EHLO " . $ehloHost . "\r\n");
            $this->readSmtpResponse($fp);
        }

        // Authenticate using AUTH LOGIN
        fwrite($fp, "AUTH LOGIN\r\n");
        $authStep1 = $this->readSmtpResponse($fp);
        if (strpos($authStep1, '334') !== 0) {
            fclose($fp);
            return array('error' => 'SMTP AUTH LOGIN not accepted: ' . trim($authStep1));
        }

        fwrite($fp, base64_encode($user) . "\r\n");
        $authStep2 = $this->readSmtpResponse($fp);
        if (strpos($authStep2, '334') !== 0) {
            fclose($fp);
            return array('error' => 'SMTP username rejected: ' . trim($authStep2));
        }

        fwrite($fp, base64_encode($pass) . "\r\n");
        $authStep3 = $this->readSmtpResponse($fp);
        if (strpos($authStep3, '235') !== 0) {
            fclose($fp);
            return array('error' => 'SMTP authentication failed: ' . trim($authStep3));
        }

        fwrite($fp, sprintf("MAIL FROM:<%s>\r\n", $fromEmail));
        $mailFromResp = $this->readSmtpResponse($fp);
        if (strpos($mailFromResp, '250') !== 0) {
            fclose($fp);
            return array('error' => 'SMTP MAIL FROM rejected: ' . trim($mailFromResp));
        }

        fwrite($fp, sprintf("RCPT TO:<%s>\r\n", $toEmail));
        $rcptResp = $this->readSmtpResponse($fp);
        if (strpos($rcptResp, '250') !== 0 && strpos($rcptResp, '251') !== 0) {
            fclose($fp);
            return array('error' => 'SMTP RCPT TO rejected: ' . trim($rcptResp));
        }

        fwrite($fp, "DATA\r\n");
        $dataResp = $this->readSmtpResponse($fp);
        if (strpos($dataResp, '354') !== 0) {
            fclose($fp);
            return array('error' => 'SMTP DATA command rejected: ' . trim($dataResp));
        }

        // Dot-stuff lines starting with "." per RFC 5321
        $stuffedMime = preg_replace('/^\./m', '..', $rawMime);
        fwrite($fp, $stuffedMime . "\r\n.\r\n");
        $sendResp = $this->readSmtpResponse($fp);
        if (strpos($sendResp, '250') !== 0) {
            fclose($fp);
            return array('error' => 'SMTP message transmission failed: ' . trim($sendResp));
        }

        fwrite($fp, "QUIT\r\n");
        fclose($fp);

        return array('success' => true);
    }

    /**
     * Read full multi-line SMTP response
     */
    private function readSmtpResponse($fp) {
        $response = '';
        while (!feof($fp)) {
            $line = fgets($fp, 1024);
            if ($line === false) {
                break;
            }
            $response .= $line;
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }
        return $response;
    }

    /**
     * Append outbound message to IMAP Sent folder (best effort)
     */
    private function appendSentMessageToImap($rawMime) {
        try {
            if (!$this->imapConnect()) {
                return false;
            }
            $size = strlen($rawMime);
            $tag  = $this->nextImapTag();
            fwrite($this->imapStream, sprintf("%s APPEND \"Sent\" (\\Seen) {%d}\r\n", $tag, $size));
            $cont = fgets($this->imapStream, 2048);
            if ($cont !== false && strpos(trim($cont), '+') === 0) {
                fwrite($this->imapStream, $rawMime . "\r\n");
                $this->imapReadUntilTag($tag);
            }
            $this->imapDisconnect();
            return true;
        } catch (Exception $e) {
            $this->imapDisconnect();
            return false;
        }
    }

    // =========================================================================
    // Native SSL/TLS IMAP4rev1 Client (imap.titan.email:993)
    // =========================================================================

    private function nextImapTag() {
        $this->imapTagCounter++;
        return sprintf('A%04d', $this->imapTagCounter);
    }

    private function imapQuoteString($str) {
        return '"' . addcslashes((string)$str, '\\"') . '"';
    }

    private function imapConnect(&$errorOut = null) {
        if (is_resource($this->imapStream)) {
            return true;
        }
        $cfg  = $this->getConfig();
        $host = $cfg['imap_host'];
        $port = (int)$cfg['imap_port'];
        $user = $cfg['email'];
        $pass = $cfg['password'];

        $remote = 'ssl://' . $host . ':' . $port;
        $context = stream_context_create(array(
            'ssl' => array(
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            )
        ));

        $errno  = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            $errorOut = sprintf('IMAP connection to %s:%d failed: %s (%d)', $host, $port, $errstr, $errno);
            return false;
        }

        stream_set_timeout($fp, 20);
        $banner = fgets($fp, 4096);
        if ($banner === false || strpos($banner, '* OK') === false) {
            fclose($fp);
            $errorOut = 'Invalid IMAP server greeting: ' . trim((string)$banner);
            return false;
        }

        $this->imapStream = $fp;
        $this->imapTagCounter = 0;

        // Send LOGIN using literal or quoted string for password containing special characters
        $tag = $this->nextImapTag();
        $passLen = strlen($pass);
        fwrite($this->imapStream, sprintf("%s LOGIN %s {%d}\r\n", $tag, $this->imapQuoteString($user), $passLen));
        $cont = fgets($this->imapStream, 2048);
        if ($cont !== false && strpos(trim($cont), '+') === 0) {
            fwrite($this->imapStream, $pass . "\r\n");
            $loginResp = $this->imapReadUntilTag($tag);
        } else {
            // Fallback to quoted LOGIN if server didn't send continuation
            $tag = $this->nextImapTag();
            fwrite($this->imapStream, sprintf("%s LOGIN %s %s\r\n", $tag, $this->imapQuoteString($user), $this->imapQuoteString($pass)));
            $loginResp = $this->imapReadUntilTag($tag);
        }

        if (!$loginResp['ok']) {
            $errorOut = 'IMAP authentication failed for ' . $user . ': ' . ($loginResp['status_line'] ?? 'Unknown error');
            $this->imapDisconnect();
            return false;
        }

        return true;
    }

    private function imapDisconnect() {
        if (is_resource($this->imapStream)) {
            $tag = $this->nextImapTag();
            @fwrite($this->imapStream, $tag . " LOGOUT\r\n");
            @fclose($this->imapStream);
        }
        $this->imapStream = null;
    }

    private function imapSendCommand($command) {
        if (!is_resource($this->imapStream)) {
            return array('ok' => false, 'raw' => '', 'status_line' => 'Not connected');
        }
        $tag = $this->nextImapTag();
        fwrite($this->imapStream, $tag . ' ' . $command . "\r\n");
        return $this->imapReadUntilTag($tag);
    }

    private function imapReadUntilTag($tag) {
        $raw = '';
        $statusLine = '';
        $ok = false;

        while (!feof($this->imapStream)) {
            $line = fgets($this->imapStream, 8192);
            if ($line === false) {
                break;
            }
            $raw .= $line;

            // Check if line ends with a literal byte count `{12345}\r\n`
            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $literalBytes = (int)$m[1];
                $remaining = $literalBytes;
                while ($remaining > 0 && !feof($this->imapStream)) {
                    $chunk = fread($this->imapStream, min(32768, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $raw .= $chunk;
                    $remaining -= strlen($chunk);
                }
                continue;
            }

            if (strpos($line, $tag . ' ') === 0) {
                $statusLine = trim($line);
                if (stripos($statusLine, $tag . ' OK') === 0) {
                    $ok = true;
                }
                break;
            }
        }

        return array(
            'ok'          => $ok,
            'raw'         => $raw,
            'status_line' => $statusLine,
        );
    }

    /**
     * Synchronize incoming emails from Titan IMAP server (imap.titan.email:993) into scp_email_messages
     */
    public function syncFromImap($maxMessages = 60) {
        $err = null;
        if (!$this->imapConnect($err)) {
            return array('error' => $err ?: 'Could not connect to IMAP server.');
        }

        $pdo = $this->db->getConnection();

        // Build lookup map of client emails -> user_id
        $emailToUserId = array();
        $uStmt = $pdo->query("SELECT id, email FROM scp_users");
        while ($uRow = $uStmt->fetch()) {
            $em = strtolower(trim((string)$uRow['email']));
            if ($em !== '') {
                $emailToUserId[$em] = (int)$uRow['id'];
            }
        }

        // Load tombstones so deleted UIDs are never re-imported
        $deletedUids = array();
        $dStmt = $pdo->query("SELECT imap_folder, imap_uid FROM scp_email_deleted_uids");
        while ($dRow = $dStmt->fetch()) {
            $deletedUids[$dRow['imap_folder'] . ':' . (int)$dRow['imap_uid']] = true;
        }

        // Load existing UIDs in scp_email_messages
        $existingUids = array();
        $eStmt = $pdo->query("SELECT id, imap_folder, imap_uid, message_id FROM scp_email_messages WHERE imap_uid IS NOT NULL");
        while ($eRow = $eStmt->fetch()) {
            $existingUids[$eRow['imap_folder'] . ':' . (int)$eRow['imap_uid']] = (int)$eRow['id'];
        }

        $existingMsgIds = array();
        $mStmt = $pdo->query("SELECT id, message_id FROM scp_email_messages WHERE message_id != ''");
        while ($mRow = $mStmt->fetch()) {
            $existingMsgIds[$mRow['message_id']] = (int)$mRow['id'];
        }

        $importedCount = 0;

        // Sync INBOX
        $sel = $this->imapSendCommand('SELECT "INBOX"');
        if ($sel['ok']) {
            $search = $this->imapSendCommand('UID SEARCH ALL');
            if ($search['ok'] && preg_match('/^\* SEARCH(.*)$/m', $search['raw'], $sm)) {
                $uidList = array_values(array_filter(array_map('intval', preg_split('/\s+/', trim($sm[1])))));
                if (!empty($uidList)) {
                    // Take the newest $maxMessages UIDs
                    $recentUids = array_slice($uidList, -$maxMessages);
                    // Process newest first
                    $recentUids = array_reverse($recentUids);

                    foreach ($recentUids as $uid) {
                        $key = 'INBOX:' . $uid;
                        if (isset($deletedUids[$key]) || isset($existingUids[$key])) {
                            continue;
                        }

                        $fetch = $this->imapSendCommand(sprintf('UID FETCH %d (UID FLAGS INTERNALDATE BODY.PEEK[])', $uid));
                        if (!$fetch['ok'] || empty($fetch['raw'])) {
                            continue;
                        }

                        $parsed = $this->parseImapFetchResponse($fetch['raw']);
                        if (!$parsed || empty($parsed['raw_message'])) {
                            continue;
                        }

                        $msgData = $this->parseRawMimeEmail($parsed['raw_message']);
                        $msgId   = $msgData['message_id'];
                        if ($msgId !== '' && isset($existingMsgIds[$msgId])) {
                            // Link the UID to existing row if already saved
                            $updUid = $pdo->prepare("UPDATE scp_email_messages SET imap_uid = ?, imap_folder = 'INBOX' WHERE id = ?");
                            $updUid->execute(array($uid, $existingMsgIds[$msgId]));
                            $existingUids[$key] = $existingMsgIds[$msgId];
                            continue;
                        }

                        $fromEmail = strtolower(trim($msgData['from_email']));
                        $toEmail   = strtolower(trim($msgData['to_email'])) ?: strtolower($this->getConfig()['email']);
                        $matchedUserId = $emailToUserId[$fromEmail] ?? ($emailToUserId[$toEmail] ?? null);

                        $isSeen    = in_array('\\Seen', $parsed['flags'], true) ? 1 : 0;
                        $isFlagged = in_array('\\Flagged', $parsed['flags'], true) ? 1 : 0;
                        $emailDate = $msgData['date_sql'] ?: ($parsed['internal_date_sql'] ?: date('Y-m-d H:i:s'));

                        $snippetText = trim($msgData['body_text'] ?: strip_tags($msgData['body_html']));
                        $snippet     = mb_substr(preg_replace('/\s+/', ' ', $snippetText), 0, 220);

                        $ins = $pdo->prepare(
                            "INSERT INTO scp_email_messages
                                (user_id, folder, direction, imap_uid, imap_folder, message_id, in_reply_to,
                                 from_email, from_name, to_email, to_name, attention_name, subject, snippet,
                                 body_text, body_html, is_read, is_starred, email_date, created_at)
                             VALUES (?, 'inbox', 'inbound', ?, 'INBOX', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                        );
                        $ins->execute(array(
                            $matchedUserId,
                            $uid,
                            $msgId,
                            $msgData['in_reply_to'],
                            $msgData['from_email'] ?: 'unknown@sender',
                            $msgData['from_name'],
                            $msgData['to_email'] ?: $this->getConfig()['email'],
                            $msgData['to_name'],
                            $msgData['from_name'] ?: $msgData['from_email'],
                            $msgData['subject'] !== '' ? $msgData['subject'] : '(No Subject)',
                            $snippet,
                            $msgData['body_text'],
                            $msgData['body_html'],
                            $isSeen,
                            $isFlagged,
                            $emailDate
                        ));

                        $newId = (int)$pdo->lastInsertId();
                        $existingUids[$key] = $newId;
                        if ($msgId !== '') {
                            $existingMsgIds[$msgId] = $newId;
                        }
                        $importedCount++;
                    }
                }
            }
        }

        $this->imapDisconnect();
        $this->db->setSetting('last_imap_sync_ts', (string)time());

        return array(
            'success'  => true,
            'imported' => $importedCount,
            'synced_at'=> date('M j, Y g:i:s A'),
            'message'  => $importedCount > 0
                ? sprintf('Synced mailbox with Titan IMAP: %d new email(s) received!', $importedCount)
                : 'Mailbox is up to date with Titan IMAP (no new emails).',
        );
    }

    /**
     * Extract FLAGS, INTERNALDATE, and literal BODY[] from a UID FETCH response
     */
    private function parseImapFetchResponse($rawFetch) {
        $flags = array();
        if (preg_match('/FLAGS\s*\(([^)]*)\)/i', $rawFetch, $fm)) {
            $flags = array_values(array_filter(preg_split('/\s+/', trim($fm[1]))));
        }

        $internalDateSql = null;
        if (preg_match('/INTERNALDATE\s*"([^"]+)"/i', $rawFetch, $dm)) {
            $ts = strtotime($dm[1]);
            if ($ts) {
                $internalDateSql = date('Y-m-d H:i:s', $ts);
            }
        }

        // Locate BODY[] literal `{length}\r\n`
        $rawMessage = '';
        if (preg_match('/BODY\[[^\]]*\]\s*\{(\d+)\}\r?\n/i', $rawFetch, $bm, PREG_OFFSET_CAPTURE)) {
            $byteLen   = (int)$bm[1][0];
            $startPos  = $bm[0][1] + strlen($bm[0][0]);
            $rawMessage = substr($rawFetch, $startPos, $byteLen);
        }

        return array(
            'flags'             => $flags,
            'internal_date_sql' => $internalDateSql,
            'raw_message'       => $rawMessage,
        );
    }

    /**
     * Parse raw RFC 5322 email (headers + single or multipart MIME body)
     */
    public function parseRawMimeEmail($rawEmail) {
        $parts = preg_split("/\r?\n\r?\n/", $rawEmail, 2);
        $rawHeaders = $parts[0] ?? '';
        $rawBody    = $parts[1] ?? '';

        $headers = $this->parseHeadersBlock($rawHeaders);

        $subject   = $this->decodeMimeString($headers['subject'] ?? '(No Subject)');
        $fromRaw   = $this->decodeMimeString($headers['from'] ?? '');
        $toRaw     = $this->decodeMimeString($headers['to'] ?? '');
        $msgId     = trim($headers['message-id'] ?? '');
        $inReplyTo = trim($headers['in-reply-to'] ?? '');
        $dateRaw   = trim($headers['date'] ?? '');

        $fromParsed = $this->parseEmailAddressHeader($fromRaw);
        $toParsed   = $this->parseEmailAddressHeader($toRaw);

        $dateSql = null;
        if ($dateRaw !== '') {
            $cleanDate = preg_replace('/\s*\([^)]*\)\s*$/', '', $dateRaw);
            $ts = strtotime($cleanDate);
            if ($ts && $ts > 0) {
                $dateSql = date('Y-m-d H:i:s', $ts);
            }
        }

        $extracted = $this->extractMimeBodies($headers, $rawBody);
        $bodyText  = trim($extracted['text']);
        $bodyHtml  = trim($extracted['html']);

        if ($bodyText === '' && $bodyHtml !== '') {
            $bodyText = trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/tr|\/li)\b[^>]*>/i', "\n", $bodyHtml)), ENT_QUOTES, 'UTF-8'));
        }
        if ($bodyHtml === '' && $bodyText !== '') {
            $bodyHtml = '<div style="font-family:Inter,sans-serif;font-size:14px;line-height:1.6;color:#1e293b;white-space:pre-wrap;">' . htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        return array(
            'subject'     => $subject,
            'from_email'  => $fromParsed['email'],
            'from_name'   => $fromParsed['name'],
            'to_email'    => $toParsed['email'],
            'to_name'     => $toParsed['name'],
            'message_id'  => $msgId,
            'in_reply_to' => $inReplyTo,
            'date_sql'    => $dateSql,
            'body_text'   => $bodyText,
            'body_html'   => $this->sanitizeEmailHtmlForViewer($bodyHtml),
        );
    }

    /**
     * Parse RFC 5322 header lines (unfolding multi-line headers)
     */
    private function parseHeadersBlock($rawHeaders) {
        $unfolded = preg_replace("/\r?\n[ \t]+/", ' ', $rawHeaders);
        $lines    = preg_split("/\r?\n/", $unfolded);
        $headers  = array();
        foreach ($lines as $line) {
            $colonPos = strpos($line, ':');
            if ($colonPos !== false) {
                $name  = strtolower(trim(substr($line, 0, $colonPos)));
                $value = trim(substr($line, $colonPos + 1));
                if (!isset($headers[$name])) {
                    $headers[$name] = $value;
                }
            }
        }
        return $headers;
    }

    /**
     * Decode RFC 2047 encoded words (=?charset?B/Q?...?=)
     */
    private function decodeMimeString($str) {
        if ($str === '' || strpos($str, '=?') === false) {
            return trim($str);
        }
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($str, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false && $decoded !== '') {
                return trim($decoded);
            }
        }
        return trim(preg_replace_callback('/=\?([^?]+)\?([BQbq])\?([^?]*)\?=/', function ($m) {
            $charset  = strtoupper($m[1]);
            $encoding = strtoupper($m[2]);
            $text     = $m[3];
            $decoded  = ($encoding === 'B')
                ? base64_decode($text)
                : quoted_printable_decode(str_replace('_', ' ', $text));
            if ($charset !== 'UTF-8' && $charset !== 'US-ASCII' && function_exists('mb_convert_encoding')) {
                $decoded = @mb_convert_encoding($decoded, 'UTF-8', $charset);
            }
            return $decoded;
        }, $str));
    }

    /**
     * Extract display name and email address from `Name <user@domain.com>`
     */
    private function parseEmailAddressHeader($raw) {
        $raw = trim((string)$raw);
        if (preg_match('/^(.*?)<([^>]+)>/', $raw, $m)) {
            $name  = trim($m[1], " \t\n\r\0\x0B\"'");
            $email = trim($m[2]);
            return array('name' => $name ?: $email, 'email' => $email);
        }
        if (preg_match('/([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/', $raw, $m)) {
            return array('name' => $m[1], 'email' => $m[1]);
        }
        return array('name' => $raw, 'email' => $raw);
    }

    /**
     * Recursively extract text/plain and text/html bodies from MIME entities
     */
    private function extractMimeBodies($headers, $rawBody) {
        $contentType = $headers['content-type'] ?? 'text/plain; charset=UTF-8';
        $transferEnc = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));

        if (preg_match('/^multipart\//i', $contentType)) {
            $boundary = '';
            if (preg_match('/boundary\s*=\s*"([^"]+)"/i', $contentType, $bm)) {
                $boundary = $bm[1];
            } elseif (preg_match('/boundary\s*=\s*([^\s;]+)/i', $contentType, $bm)) {
                $boundary = trim($bm[1], "\"'");
            }

            if ($boundary !== '') {
                $textOut = '';
                $htmlOut = '';
                $segments = explode('--' . $boundary, $rawBody);
                // First segment is preamble, last starting with `--` is epilogue
                array_shift($segments);
                foreach ($segments as $seg) {
                    if (strpos($seg, '--') === 0) {
                        break;
                    }
                    $seg = ltrim($seg, "\r\n");
                    $subParts = preg_split("/\r?\n\r?\n/", $seg, 2);
                    $subHeaders = $this->parseHeadersBlock($subParts[0] ?? '');
                    $subBody    = $subParts[1] ?? '';

                    // Skip attachment dispositions unless inline text/html
                    $disp = strtolower($subHeaders['content-disposition'] ?? '');
                    if (strpos($disp, 'attachment') === 0) {
                        continue;
                    }

                    $child = $this->extractMimeBodies($subHeaders, $subBody);
                    if ($child['text'] !== '' && $textOut === '') {
                        $textOut = $child['text'];
                    }
                    if ($child['html'] !== '' && $htmlOut === '') {
                        $htmlOut = $child['html'];
                    }
                }
                return array('text' => $textOut, 'html' => $htmlOut);
            }
        }

        // Decode transfer encoding
        $decoded = $rawBody;
        if ($transferEnc === 'base64') {
            $decoded = base64_decode(preg_replace('/\s+/', '', $rawBody));
        } elseif ($transferEnc === 'quoted-printable') {
            $decoded = quoted_printable_decode($rawBody);
        }

        // Convert charset to UTF-8 if needed
        if (preg_match('/charset\s*=\s*"?([^"\s;]+)"?/i', $contentType, $cm)) {
            $charset = strtoupper(trim($cm[1]));
            if ($charset !== 'UTF-8' && $charset !== 'US-ASCII' && function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
                if ($converted !== false) {
                    $decoded = $converted;
                }
            }
        }

        if (stripos($contentType, 'text/html') === 0) {
            return array('text' => '', 'html' => $decoded);
        }
        if (stripos($contentType, 'text/plain') === 0 || stripos($contentType, 'text/') === 0) {
            return array('text' => $decoded, 'html' => '');
        }
        return array('text' => '', 'html' => '');
    }

    /**
     * Sanitize HTML email content so scripts/event handlers are stripped before viewing
     */
    public function sanitizeEmailHtmlForViewer($html) {
        $html = (string)$html;
        if ($html === '') {
            return '';
        }
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<iframe\b[^>]*>.*?</iframe>#is', '', $html);
        $html = preg_replace('#<object\b[^>]*>.*?</object>#is', '', $html);
        $html = preg_replace('#<embed\b[^>]*>.*?</embed>#is', '', $html);
        $html = preg_replace('/\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace('/javascript\s*:/i', 'nojs:', $html);
        return $html;
    }

    // =========================================================================
    // Database Queries & Email Client Operations
    // =========================================================================

    /**
     * Get folder message counts (Inbox, Unread, Starred, Sent, Trash)
     */
    public function getFolderCounts() {
        $counts = array(
            'inbox'        => 0,
            'inbox_unread' => 0,
            'unread'       => 0,
            'starred'      => 0,
            'sent'         => 0,
            'trash'        => 0,
        );
        if (!$this->db->isConfigured()) {
            return $counts;
        }
        try {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->query(
                "SELECT
                    SUM(CASE WHEN folder = 'inbox' THEN 1 ELSE 0 END) AS inbox_total,
                    SUM(CASE WHEN folder = 'inbox' AND is_read = 0 THEN 1 ELSE 0 END) AS inbox_unread,
                    SUM(CASE WHEN folder != 'trash' AND is_read = 0 THEN 1 ELSE 0 END) AS unread_total,
                    SUM(CASE WHEN folder != 'trash' AND is_starred = 1 THEN 1 ELSE 0 END) AS starred_total,
                    SUM(CASE WHEN folder = 'sent' THEN 1 ELSE 0 END) AS sent_total,
                    SUM(CASE WHEN folder = 'trash' THEN 1 ELSE 0 END) AS trash_total
                 FROM scp_email_messages"
            );
            $row = $stmt->fetch();
            if ($row) {
                $counts['inbox']        = (int)($row['inbox_total'] ?? 0);
                $counts['inbox_unread'] = (int)($row['inbox_unread'] ?? 0);
                $counts['unread']       = (int)($row['unread_total'] ?? 0);
                $counts['starred']      = (int)($row['starred_total'] ?? 0);
                $counts['sent']         = (int)($row['sent_total'] ?? 0);
                $counts['trash']        = (int)($row['trash_total'] ?? 0);
            }
        } catch (Exception $e) {
            // Ignore
        }
        return $counts;
    }

    /**
     * List email messages for the Admin Email Client
     */
    public function getMessages($folder = 'inbox', $search = '', $userId = 0, $limit = 150) {
        if (!$this->db->isConfigured()) {
            return array();
        }
        $pdo    = $this->db->getConnection();
        $where  = array();
        $params = array();

        $folder = strtolower(trim($folder));
        if ($folder === 'unread') {
            $where[] = "m.folder != 'trash' AND m.is_read = 0";
        } elseif ($folder === 'starred') {
            $where[] = "m.folder != 'trash' AND m.is_starred = 1";
        } elseif (in_array($folder, array('inbox', 'sent', 'trash'), true)) {
            $where[] = "m.folder = ?";
            $params[] = $folder;
        } else {
            $where[] = "m.folder = 'inbox'";
        }

        if ($userId > 0) {
            $where[] = "m.user_id = ?";
            $params[] = (int)$userId;
        }

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = "(m.subject LIKE ? OR m.from_email LIKE ? OR m.from_name LIKE ? OR m.to_email LIKE ? OR m.to_name LIKE ? OR m.body_text LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = max(1, min(300, (int)$limit));

        $sql = "SELECT
                    m.id, m.user_id, m.inquiry_id, m.folder, m.direction, m.imap_uid, m.imap_folder,
                    m.message_id, m.in_reply_to, m.from_email, m.from_name, m.to_email, m.to_name,
                    m.attention_name, m.subject, m.snippet, m.is_read, m.is_starred, m.replied_at,
                    m.email_date, m.created_at,
                    u.first_name AS client_first_name,
                    u.last_name AS client_last_name,
                    u.company AS client_company
                FROM scp_email_messages m
                LEFT JOIN scp_users u ON m.user_id = u.id
                $whereSql
                ORDER BY m.email_date DESC, m.id DESC
                LIMIT $limit";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['id']         = (int)$r['id'];
            $r['user_id']    = $r['user_id'] ? (int)$r['user_id'] : null;
            $r['is_read']    = (int)$r['is_read'];
            $r['is_starred'] = (int)$r['is_starred'];
            $r['date_formatted'] = !empty($r['email_date']) ? date('M j, Y g:i A', strtotime($r['email_date'])) : '';
            $r['date_short']     = !empty($r['email_date'])
                ? (date('Y-m-d', strtotime($r['email_date'])) === date('Y-m-d')
                    ? date('g:i A', strtotime($r['email_date']))
                    : date('M j', strtotime($r['email_date'])))
                : '';
            $cName = trim(($r['client_first_name'] ?? '') . ' ' . ($r['client_last_name'] ?? ''));
            $r['client_display_name'] = $cName ?: ($r['client_company'] ?? '');
        }
        unset($r);

        return $rows;
    }

    /**
     * Get single email by ID
     */
    public function getMessageById($id) {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare(
            "SELECT m.*,
                    u.first_name AS client_first_name,
                    u.last_name AS client_last_name,
                    u.company AS client_company,
                    u.email AS client_account_email,
                    u.phone AS client_phone
             FROM scp_email_messages m
             LEFT JOIN scp_users u ON m.user_id = u.id
             WHERE m.id = ?
             LIMIT 1"
        );
        $stmt->execute(array((int)$id));
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['id']             = (int)$row['id'];
        $row['user_id']        = $row['user_id'] ? (int)$row['user_id'] : null;
        $row['is_read']        = (int)$row['is_read'];
        $row['is_starred']     = (int)$row['is_starred'];
        $row['date_formatted'] = !empty($row['email_date']) ? date('F j, Y \a\t g:i A', strtotime($row['email_date'])) : '';
        $cName = trim(($row['client_first_name'] ?? '') . ' ' . ($row['client_last_name'] ?? ''));
        $row['client_display_name'] = $cName ?: ($row['client_company'] ?? '');
        return $row;
    }

    /**
     * Get full email message + mark as read + load thread history
     */
    public function getMessageDetailWithThread($id, $markRead = true) {
        $msg = $this->getMessageById($id);
        if (!$msg) {
            return array('error' => 'Email message not found.');
        }

        if ($markRead && empty($msg['is_read'])) {
            $this->updateMessagesStatus(array((int)$id), 'mark_read');
            $msg['is_read'] = 1;
        }

        // Find related conversation messages (same normalized subject & counterpart email, or linked message_id/in_reply_to)
        $pdo = $this->db->getConnection();
        $normSubject = trim(preg_replace('/^(re|fwd|fw)\s*:\s*/i', '', (string)$msg['subject']));
        $counterpartEmail = ($msg['direction'] === 'inbound') ? $msg['from_email'] : $msg['to_email'];

        $thread = array();
        if ($counterpartEmail !== '') {
            $tStmt = $pdo->prepare(
                "SELECT id, direction, folder, from_email, from_name, to_email, to_name, subject, body_text, body_html, email_date
                 FROM scp_email_messages
                 WHERE id != ?
                   AND folder != 'trash'
                   AND (LOWER(from_email) = LOWER(?) OR LOWER(to_email) = LOWER(?))
                 ORDER BY email_date ASC, id ASC
                 LIMIT 30"
            );
            $tStmt->execute(array((int)$id, $counterpartEmail, $counterpartEmail));
            $candidates = $tStmt->fetchAll();
            foreach ($candidates as $cand) {
                $candNorm = trim(preg_replace('/^(re|fwd|fw)\s*:\s*/i', '', (string)$cand['subject']));
                if (
                    ($normSubject !== '' && strcasecmp($candNorm, $normSubject) === 0) ||
                    (!empty($msg['in_reply_to']) && $cand['message_id'] === $msg['in_reply_to']) ||
                    (!empty($msg['message_id']) && $cand['in_reply_to'] === $msg['message_id'])
                ) {
                    $cand['id'] = (int)$cand['id'];
                    $cand['date_formatted'] = !empty($cand['email_date']) ? date('M j, Y g:i A', strtotime($cand['email_date'])) : '';
                    $thread[] = $cand;
                }
            }
        }

        return array(
            'message' => $msg,
            'thread'  => $thread,
            'counts'  => $this->getFolderCounts(),
        );
    }

    /**
     * Perform single or bulk status / folder updates on email messages
     * Actions: mark_read, mark_unread, star, unstar, trash, restore, delete_permanent, empty_trash
     */
    public function updateMessagesStatus($ids, $action) {
        $pdo = $this->db->getConnection();
        $action = strtolower(trim($action));

        if ($action === 'empty_trash') {
            $stmt = $pdo->query("SELECT id, imap_folder, imap_uid FROM scp_email_messages WHERE folder = 'trash'");
            $trashRows = $stmt->fetchAll();
            $trashIds = array();
            foreach ($trashRows as $tr) {
                $trashIds[] = (int)$tr['id'];
                if (!empty($tr['imap_uid'])) {
                    $insDel = $pdo->prepare("INSERT IGNORE INTO scp_email_deleted_uids (imap_folder, imap_uid, deleted_at) VALUES (?, ?, NOW())");
                    $insDel->execute(array($tr['imap_folder'] ?: 'INBOX', (int)$tr['imap_uid']));
                }
            }
            $pdo->exec("DELETE FROM scp_email_messages WHERE folder = 'trash'");
            return array(
                'success' => true,
                'message' => sprintf('Emptied Trash (%d message(s) permanently deleted).', count($trashIds)),
                'counts'  => $this->getFolderCounts(),
            );
        }

        $cleanIds = array_values(array_unique(array_filter(array_map('intval', (array)$ids), function ($v) {
            return $v > 0;
        })));

        if (empty($cleanIds)) {
            return array('error' => 'No email messages selected.');
        }

        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));

        // Fetch IMAP UIDs for any IMAP flag sync
        $sStmt = $pdo->prepare("SELECT id, direction, imap_folder, imap_uid FROM scp_email_messages WHERE id IN ($placeholders)");
        $sStmt->execute($cleanIds);
        $targetRows = $sStmt->fetchAll();

        switch ($action) {
            case 'mark_read':
                $u = $pdo->prepare("UPDATE scp_email_messages SET is_read = 1 WHERE id IN ($placeholders)");
                $u->execute($cleanIds);
                $this->syncImapFlagsBestEffort($targetRows, '+FLAGS.SILENT (\\Seen)');
                $msg = 'Marked as read.';
                break;

            case 'mark_unread':
                $u = $pdo->prepare("UPDATE scp_email_messages SET is_read = 0 WHERE id IN ($placeholders)");
                $u->execute($cleanIds);
                $this->syncImapFlagsBestEffort($targetRows, '-FLAGS.SILENT (\\Seen)');
                $msg = 'Marked as unread.';
                break;

            case 'star':
                $u = $pdo->prepare("UPDATE scp_email_messages SET is_starred = 1 WHERE id IN ($placeholders)");
                $u->execute($cleanIds);
                $this->syncImapFlagsBestEffort($targetRows, '+FLAGS.SILENT (\\Flagged)');
                $msg = 'Message(s) starred.';
                break;

            case 'unstar':
                $u = $pdo->prepare("UPDATE scp_email_messages SET is_starred = 0 WHERE id IN ($placeholders)");
                $u->execute($cleanIds);
                $this->syncImapFlagsBestEffort($targetRows, '-FLAGS.SILENT (\\Flagged)');
                $msg = 'Star removed.';
                break;

            case 'trash':
                $u = $pdo->prepare("UPDATE scp_email_messages SET folder = 'trash' WHERE id IN ($placeholders)");
                $u->execute($cleanIds);
                $msg = count($cleanIds) === 1 ? 'Message moved to Trash.' : sprintf('%d messages moved to Trash.', count($cleanIds));
                break;

            case 'restore':
                foreach ($targetRows as $tr) {
                    $destFolder = ($tr['direction'] === 'outbound') ? 'sent' : 'inbox';
                    $u = $pdo->prepare("UPDATE scp_email_messages SET folder = ? WHERE id = ?");
                    $u->execute(array($destFolder, (int)$tr['id']));
                }
                $msg = count($cleanIds) === 1 ? 'Message restored from Trash.' : sprintf('%d messages restored from Trash.', count($cleanIds));
                break;

            case 'delete_permanent':
                foreach ($targetRows as $tr) {
                    if (!empty($tr['imap_uid'])) {
                        $insDel = $pdo->prepare("INSERT IGNORE INTO scp_email_deleted_uids (imap_folder, imap_uid, deleted_at) VALUES (?, ?, NOW())");
                        $insDel->execute(array($tr['imap_folder'] ?: 'INBOX', (int)$tr['imap_uid']));
                    }
                }
                $this->syncImapFlagsBestEffort($targetRows, '+FLAGS.SILENT (\\Deleted)', true);
                $d = $pdo->prepare("DELETE FROM scp_email_messages WHERE id IN ($placeholders)");
                $d->execute($cleanIds);
                $msg = count($cleanIds) === 1 ? 'Message permanently deleted.' : sprintf('%d messages permanently deleted.', count($cleanIds));
                break;

            default:
                return array('error' => 'Unsupported email action.');
        }

        return array(
            'success' => true,
            'message' => $msg,
            'counts'  => $this->getFolderCounts(),
        );
    }

    /**
     * Best-effort sync of flags (\Seen, \Flagged, \Deleted) back to Titan IMAP server
     */
    private function syncImapFlagsBestEffort($rows, $flagAction, $expunge = false) {
        $uids = array();
        foreach ($rows as $r) {
            if (!empty($r['imap_uid']) && ($r['imap_folder'] ?? 'INBOX') === 'INBOX') {
                $uids[] = (int)$r['imap_uid'];
            }
        }
        if (empty($uids)) {
            return;
        }
        try {
            if (!$this->imapConnect()) {
                return;
            }
            $sel = $this->imapSendCommand('SELECT "INBOX"');
            if ($sel['ok']) {
                $uidSet = implode(',', $uids);
                $this->imapSendCommand(sprintf('UID STORE %s %s', $uidSet, $flagAction));
                if ($expunge) {
                    $this->imapSendCommand('EXPUNGE');
                }
            }
            $this->imapDisconnect();
        } catch (Exception $e) {
            $this->imapDisconnect();
        }
    }
}
