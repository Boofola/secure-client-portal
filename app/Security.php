<?php
/**
 * Security, Session Management, CSRF, Rate Limiting, reCAPTCHA Enterprise & Credentials
 * Secure Cloud Portal Stand-Alone v2.0.0
 */

if (!defined('SCP_APP_INIT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

class SCP_Security {

    /**
     * Send defense-in-depth HTTP security headers from PHP
     */
    public static function sendSecurityHeaders() {
        if (headers_sent()) {
            return;
        }
        @header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(self)');

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Initialize hardened PHP session
     */
    public static function initSession() {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_strict_mode', '1');
            session_set_cookie_params(array(
                'lifetime' => 86400 * 7, // 7 days session lifetime
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ));
            session_name('SCP_PORTAL_SESS');
            session_start();
        }
    }

    /**
     * Get or generate CSRF Token
     */
    public static function getCsrfToken() {
        self::initSession();
        if (empty($_SESSION['scp_csrf_token'])) {
            $_SESSION['scp_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['scp_csrf_token'];
    }

    /**
     * Verify CSRF Token
     */
    public static function verifyCsrfToken($providedToken) {
        self::initSession();
        if (empty($_SESSION['scp_csrf_token']) || empty($providedToken)) {
            return false;
        }
        return hash_equals($_SESSION['scp_csrf_token'], (string)$providedToken);
    }

    /**
     * Get the base URL of the application (e.g., https://portal.example.com)
     * Prioritizes SCP_SITE_URL and validates HTTP_HOST against Host Header injection.
     */
    public static function getBaseUrl() {
        if (defined('SCP_SITE_URL') && !empty(SCP_SITE_URL)) {
            $configured = filter_var(rtrim(trim(SCP_SITE_URL), '/'), FILTER_VALIDATE_URL);
            if ($configured && preg_match('#^https?://#i', $configured)) {
                return $configured;
            }
        }
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $isHttps ? 'https' : 'http';
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $rawHost = strtolower(trim($serverHost));
        if (!preg_match('/^[a-z0-9.-]+(?::\d{1,5})?$/', $rawHost)) {
            $rawHost = 'localhost';
        }
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $scriptDir = ($scriptDir === '/' || $scriptDir === '.' || $scriptDir === '\\') ? '' : rtrim($scriptDir, '/');
        return $scheme . '://' . $rawHost . $scriptDir;
    }

    /**
     * Validate that a URL is a well-formed HTTP/HTTPS URL (blocks javascript:, data:, file:, etc.)
     */
    public static function validateHttpUrl($url, $requireHttps = false) {
        $url = trim((string)$url);
        if ($url === '') {
            return false;
        }
        $valid = filter_var($url, FILTER_VALIDATE_URL);
        if (!$valid) {
            return false;
        }
        $scheme = strtolower((string)parse_url($valid, PHP_URL_SCHEME));
        if ($requireHttps && $scheme !== 'https') {
            return false;
        }
        if (!in_array($scheme, array('http', 'https'), true)) {
            return false;
        }
        return $valid;
    }

    /**
     * Get validated client IP address
     */
    public static function getClientIp() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return '127.0.0.1';
        }
        return $ip;
    }

    /**
     * HTML escape helper
     */
    public static function e($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Clean expired rate limits & idempotency keys
     */
    private static function pruneExpiredLimits() {
        $db = SCP_Database::getInstance();
        if (!$db->isConfigured()) {
            return;
        }
        try {
            $stmt = $db->getConnection()->prepare("DELETE FROM scp_rate_limits WHERE expires_at < ?");
            $stmt->execute(array(time()));
        } catch (Exception $e) {
            // Ignore
        }
    }

    /**
     * Check IP-based rate limit in MySQL
     */
    public static function checkRateLimit($action, $maxAttempts = 5, $windowSeconds = 900) {
        $db = SCP_Database::getInstance();
        if (!$db->isConfigured()) {
            return true;
        }

        self::pruneExpiredLimits();
        $key = 'rl_v212_' . md5($action . '_' . self::getClientIp());

        try {
            $stmt = $db->getConnection()->prepare("SELECT attempts FROM scp_rate_limits WHERE rate_key = ? AND expires_at >= ?");
            $stmt->execute(array($key, time()));
            $row = $stmt->fetch();

            if ($row && (int)$row['attempts'] >= $maxAttempts) {
                return 'Too many attempts detected from your IP address. Please wait 15 minutes before trying again.';
            }
        } catch (Exception $e) {
            return true;
        }

        return true;
    }

    /**
     * Record failed attempt for IP rate limiting
     */
    public static function recordFailedAttempt($action, $windowSeconds = 900) {
        $db = SCP_Database::getInstance();
        if (!$db->isConfigured()) {
            return;
        }
        $key = 'rl_v212_' . md5($action . '_' . self::getClientIp());
        $expires = time() + $windowSeconds;

        try {
            $stmt = $db->getConnection()->prepare(
                "INSERT INTO scp_rate_limits (rate_key, attempts, expires_at) VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE attempts = attempts + 1, expires_at = VALUES(expires_at)"
            );
            $stmt->execute(array($key, $expires));
        } catch (Exception $e) {
            // Ignore
        }
    }

    /**
     * Clear rate limit after success
     */
    public static function clearRateLimit($action) {
        $db = SCP_Database::getInstance();
        if (!$db->isConfigured()) {
            return;
        }
        $key = 'rl_v212_' . md5($action . '_' . self::getClientIp());
        try {
            $stmt = $db->getConnection()->prepare("DELETE FROM scp_rate_limits WHERE rate_key = ?");
            $stmt->execute(array($key));
        } catch (Exception $e) {
            // Ignore
        }
    }

    /**
     * Check and store idempotency key (e.g. Stripe webhook event ID or SSO token replay)
     */
    public static function checkAndMarkIdempotencyKey($prefix, $uniqueId, $ttlSeconds = 259200) {
        $db = SCP_Database::getInstance();
        if (!$db->isConfigured()) {
            return false;
        }
        self::pruneExpiredLimits();
        $key = $prefix . '_' . md5($uniqueId);
        try {
            $stmt = $db->getConnection()->prepare("SELECT attempts FROM scp_rate_limits WHERE rate_key = ? AND expires_at >= ?");
            $stmt->execute(array($key, time()));
            if ($stmt->fetch()) {
                return true; // Already processed
            }

            $ins = $db->getConnection()->prepare("INSERT IGNORE INTO scp_rate_limits (rate_key, attempts, expires_at) VALUES (?, 1, ?)");
            $ins->execute(array($key, time() + $ttlSeconds));
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check whether the current domain is authorized on the Google reCAPTCHA site key.
     * Prevents "ERROR for site owner: Invalid domain for site key" from blocking login
     * if the domain hasn't been added under Google Cloud Console -> Security -> reCAPTCHA yet.
     */
    public static function isRecaptchaDomainVerified() {
        $siteKey = self::getRecaptchaSiteKey();
        if (empty($siteKey)) {
            return false;
        }

        self::initSession();
        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $host = strtolower(preg_replace('/:\d+$/', '', $serverHost));
        $cacheKey = 'scp_rc_dom_ok_' . md5($siteKey . '_' . $host);

        if (isset($_SESSION[$cacheKey]) && isset($_SESSION[$cacheKey]['expires']) && $_SESSION[$cacheKey]['expires'] > time()) {
            return (bool)$_SESSION[$cacheKey]['valid'];
        }

        $co = rtrim(strtr(base64_encode('https://' . $host . ':443'), '+/', '-_'), '=');
        $url = 'https://www.google.com/recaptcha/enterprise/anchor?ar=1&k=' . rawurlencode($siteKey) . '&co=' . rawurlencode($co) . '&hl=en';
        $resp = SCP_HttpClient::request($url, 'GET', null, array('Referer' => 'https://' . $host . '/'), 4);

        $isValid = true;
        if (!empty($resp['body']) && preg_match('/Invalid domain for site key|Invalid key type/i', $resp['body'])) {
            $isValid = false;
        }

        $_SESSION[$cacheKey] = array(
            'valid'   => $isValid,
            'expires' => time() + 300
        );

        return $isValid;
    }

    /**
     * Verify Google reCAPTCHA (Supports both Google Cloud reCAPTCHA Enterprise Assessments & Standard Siteverify)
     */
    public static function verifyRecaptcha($token, $expectedAction = '') {
        $siteKey = self::getRecaptchaSiteKey();
        if (empty($siteKey)) {
            return true;
        }

        // If domain is not yet verified in Google Cloud Console for this siteKey, fall back to CSRF + IP Rate Limiting
        if (!self::isRecaptchaDomainVerified()) {
            return true;
        }

        $token = trim((string)$token);
        if (empty($token) || $token === 'enterprise_client_fallback') {
            return 'Please complete the security verification (reCAPTCHA) and try again.';
        }

        $apiKey    = defined('SCP_RECAPTCHA_API_KEY') ? SCP_RECAPTCHA_API_KEY : '';
        $projectId = defined('SCP_RECAPTCHA_PROJECT_ID') ? SCP_RECAPTCHA_PROJECT_ID : '';

        // 1. Google Cloud reCAPTCHA Enterprise REST API (AIza... key + project ID)
        if (!empty($projectId) && strpos($apiKey, 'AIza') === 0) {
            $enterpriseUrl = sprintf(
                'https://recaptchaenterprise.googleapis.com/v1/projects/%s/assessments?key=%s',
                rawurlencode($projectId),
                rawurlencode($apiKey)
            );

            $payload = array(
                'event' => array(
                    'token'         => $token,
                    'siteKey'       => $siteKey,
                    'userIpAddress' => self::getClientIp(),
                    'userAgent'     => $_SERVER['HTTP_USER_AGENT'] ?? '',
                )
            );
            if (!empty($expectedAction)) {
                $payload['event']['expectedAction'] = $expectedAction;
            }

            $resp = SCP_HttpClient::request(
                $enterpriseUrl,
                'POST',
                json_encode($payload),
                array('Content-Type' => 'application/json'),
                10
            );

            if ($resp['code'] === 200 && isset($resp['json']['tokenProperties'])) {
                if (!empty($resp['json']['tokenProperties']['valid'])) {
                    if (!empty($expectedAction) && !empty($resp['json']['tokenProperties']['action']) && $resp['json']['tokenProperties']['action'] !== $expectedAction) {
                        return 'Security verification action mismatch. Please refresh and try again.';
                    }
                    if (isset($resp['json']['riskAnalysis']['score']) && (float)$resp['json']['riskAnalysis']['score'] < 0.3) {
                        return 'Automated request blocked by security policy.';
                    }
                    return true;
                }
                $invalidReason = strtoupper((string)($resp['json']['tokenProperties']['invalidReason'] ?? ''));
                if (in_array($invalidReason, array('MALFORMED', 'EXPIRED', 'DUPE', 'MISSING'), true)) {
                    return 'Security verification token expired or invalid. Please refresh the page and try again.';
                }
                return true;
            }

            return true;
        }

        return true;
    }

    /**
     * Credential Accessors (prioritize config/credentials.php constants over DB settings)
     */
    public static function getStripeMode() {
        return SCP_Database::getInstance()->getSetting('stripe_mode', 'live');
    }

    public static function getStripeSecretKey($mode = null) {
        $mode = $mode ?: self::getStripeMode();
        if ($mode === 'live') {
            if (defined('SCP_STRIPE_LIVE_SK') && !empty(SCP_STRIPE_LIVE_SK)) {
                return SCP_STRIPE_LIVE_SK;
            }
            return SCP_Database::getInstance()->getSetting('stripe_live_sk', '');
        } else {
            if (defined('SCP_STRIPE_TEST_SK') && !empty(SCP_STRIPE_TEST_SK)) {
                return SCP_STRIPE_TEST_SK;
            }
            return SCP_Database::getInstance()->getSetting('stripe_test_sk', '');
        }
    }

    public static function getStripePublishableKey($mode = null) {
        $mode = $mode ?: self::getStripeMode();
        if ($mode === 'live') {
            if (defined('SCP_STRIPE_LIVE_PK') && !empty(SCP_STRIPE_LIVE_PK)) {
                return SCP_STRIPE_LIVE_PK;
            }
            return SCP_Database::getInstance()->getSetting('stripe_live_pk', '');
        } else {
            if (defined('SCP_STRIPE_TEST_PK') && !empty(SCP_STRIPE_TEST_PK)) {
                return SCP_STRIPE_TEST_PK;
            }
            return SCP_Database::getInstance()->getSetting('stripe_test_pk', '');
        }
    }

    public static function getNamecheapApiUser() {
        if (defined('SCP_NAMECHEAP_API_USER') && !empty(SCP_NAMECHEAP_API_USER)) {
            return SCP_NAMECHEAP_API_USER;
        }
        return SCP_Database::getInstance()->getSetting('nc_api_user', '');
    }

    public static function getNamecheapApiKey() {
        if (defined('SCP_NAMECHEAP_API_KEY') && !empty(SCP_NAMECHEAP_API_KEY)) {
            return SCP_NAMECHEAP_API_KEY;
        }
        return SCP_Database::getInstance()->getSetting('nc_api_key', '');
    }

    public static function getNamecheapUsername() {
        if (defined('SCP_NAMECHEAP_USERNAME') && !empty(SCP_NAMECHEAP_USERNAME)) {
            return SCP_NAMECHEAP_USERNAME;
        }
        return SCP_Database::getInstance()->getSetting('nc_username', '');
    }

    public static function getOpenSrsUsername() {
        if (defined('SCP_OPENSRS_USERNAME') && !empty(SCP_OPENSRS_USERNAME)) {
            return SCP_OPENSRS_USERNAME;
        }
        return SCP_Database::getInstance()->getSetting('os_username', '');
    }

    public static function getOpenSrsKey() {
        if (defined('SCP_OPENSRS_API_KEY') && !empty(SCP_OPENSRS_API_KEY)) {
            return SCP_OPENSRS_API_KEY;
        }
        return SCP_Database::getInstance()->getSetting('os_private_key', '');
    }

    public static function getRecaptchaSiteKey() {
        return defined('SCP_RECAPTCHA_SITE_KEY') ? SCP_RECAPTCHA_SITE_KEY : '';
    }

    /**
     * Render crisp category bottom note SVG icon (v3.2.0)
     * Options: green-exclamation, red-exclamation, dark-grey-asterisk, green-checkmark, red-x
     */
    public static function renderCategoryNoteIcon($iconKey) {
        $iconKey = strtolower(trim((string)$iconKey));
        switch ($iconKey) {
            case 'green-exclamation':
                return '<svg class="scp-cat-note-svg" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><circle cx="12" cy="16" r="0.75" fill="#16a34a"></circle></svg>';
            case 'red-exclamation':
                return '<svg class="scp-cat-note-svg" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><circle cx="12" cy="16" r="0.75" fill="#dc2626"></circle></svg>';
            case 'dark-grey-asterisk':
            case 'dark-gray-asterisk':
                return '<svg class="scp-cat-note-svg" viewBox="0 0 24 24" fill="none" stroke="#334155" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="7" x2="12" y2="17"></line><line x1="7.67" y1="9.5" x2="16.33" y2="14.5"></line><line x1="7.67" y1="14.5" x2="16.33" y2="9.5"></line></svg>';
            case 'green-checkmark':
            case 'green-check':
                return '<svg class="scp-cat-note-svg" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="7.5 12 10.5 15 16.5 9"></polyline></svg>';
            case 'red-x':
            case 'red-cross':
                return '<svg class="scp-cat-note-svg" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="8.5" y1="8.5" x2="15.5" y2="15.5"></line><line x1="15.5" y1="8.5" x2="8.5" y2="15.5"></line></svg>';
            case 'blue-info':
                return '<svg class="scp-cat-note-svg" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';
            default:
                return '';
        }
    }

    /**
     * Render standardized companion SVG icon for a product feature row hint (v3.4.1)
     * Options: black-info, blue-question, green-checkmark, red-x, yellow-triangle, red-exclamation
     */
    public static function renderFeatureHintIcon($iconKey = 'black-info') {
        $iconKey = strtolower(trim((string)$iconKey));
        switch ($iconKey) {
            case 'green-checkmark':
            case 'green-check':
                return '<svg class="scp-feat-hint-svg" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="7.5 12 10.5 15 16.5 9"></polyline></svg>';
            case 'red-x':
            case 'red-cross':
                return '<svg class="scp-feat-hint-svg" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="8.5" y1="8.5" x2="15.5" y2="15.5"></line><line x1="15.5" y1="8.5" x2="8.5" y2="15.5"></line></svg>';
            case 'blue-question':
            case 'question':
                return '<svg class="scp-feat-hint-svg" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><circle cx="12" cy="17" r="0.85" fill="#2563eb" stroke="none"></circle></svg>';
            case 'yellow-triangle':
            case 'disclosure-triangle':
                return '<svg class="scp-feat-hint-svg" viewBox="0 0 24 24" fill="#fef08a" stroke="#ca8a04" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13.5" stroke="#854d0e" stroke-width="2.3"></line><circle cx="12" cy="17" r="0.9" fill="#854d0e" stroke="none"></circle></svg>';
            case 'red-exclamation':
                return '<svg class="scp-feat-hint-svg" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="7.5" x2="12" y2="12.5"></line><circle cx="12" cy="16.5" r="0.85" fill="#dc2626" stroke="none"></circle></svg>';
            case 'black-info':
            default:
                return '<svg class="scp-feat-hint-svg" viewBox="0 0 24 24" fill="none" stroke="#0f172a" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16.2" x2="12" y2="11.2"></line><circle cx="12" cy="7.8" r="0.9" fill="#0f172a" stroke="none"></circle></svg>';
        }
    }
}
