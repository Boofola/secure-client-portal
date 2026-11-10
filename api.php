<?php
/**
 * Stand-Alone JSON AJAX & Stripe Webhook Controller
 * Secure Cloud Portal Stand-Alone v2.0.0
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

$db = SCP_Database::getInstance();
if (!$db->isConfigured()) {
    http_response_code(503);
    echo json_encode(array('success' => false, 'data' => array('message' => 'Database is not configured yet. Please run /install.php first.')));
    exit;
}

$auth           = new SCP_Auth();
$stripe         = new SCP_StripeService();
$namecheap      = new SCP_NamecheapService();
$opensrs        = new SCP_OpenSRSService();
$domainManager  = new SCP_DomainManager($namecheap, $opensrs);
$websiteManager = new SCP_WebsiteManager();

$action = $_REQUEST['action'] ?? '';

function scp_json_success($data = array()) {
    echo json_encode(array('success' => true, 'data' => $data));
    exit;
}

function scp_json_error($message, $code = 200) {
    if ($code !== 200) {
        http_response_code($code);
    }
    echo json_encode(array('success' => false, 'data' => array('message' => $message)));
    exit;
}

// 1. Incoming Stripe Webhook (uses Stripe-Signature header instead of CSRF token)
if ($action === 'stripe_webhook') {
    $rawPayload = file_get_contents('php://input');
    $sigHeader  = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $res = $stripe->handleWebhook($rawPayload, $sigHeader, $domainManager);
    http_response_code($res['code']);
    echo json_encode($res['body']);
    exit;
}

// Verify CSRF token for all interactive POST requests
$csrfCandidate = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !SCP_Security::verifyCsrfToken($csrfCandidate)) {
    scp_json_error('Security session expired. Please refresh the page and try again.');
}

switch ($action) {
    // -------------------------------------------------------------------------
    // Public Actions (Login, Register, Domain Search, Contact Form)
    // -------------------------------------------------------------------------
    case 'scp_login':
        $rateCheck = SCP_Security::checkRateLimit('login', 5, 900);
        if ($rateCheck !== true) {
            scp_json_error($rateCheck);
        }

        $email    = trim($_POST['username'] ?? $_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            scp_json_error('Please enter both your email address and password.');
        }

        $recaptchaCheck = SCP_Security::verifyRecaptcha($_POST['g-recaptcha-response'] ?? '', 'login');
        if ($recaptchaCheck !== true) {
            scp_json_error($recaptchaCheck);
        }

        $res = $auth->login($email, $password, $stripe);
        if (!empty($res['error'])) {
            SCP_Security::recordFailedAttempt('login', 900);
            scp_json_error($res['error']);
        }

        SCP_Security::clearRateLimit('login');
        $redirect = ($res['user']['role'] === 'admin') ? 'index.php?page=admin' : 'index.php?page=portal';
        scp_json_success(array(
            'message'  => 'Signed in successfully! Redirecting to your dashboard...',
            'redirect' => $redirect
        ));
        break;

    case 'scp_register':
        $rateCheck = SCP_Security::checkRateLimit('register', 5, 900);
        if ($rateCheck !== true) {
            scp_json_error($rateCheck);
        }

        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $company   = trim($_POST['company'] ?? '');

        if (empty($email) || empty($password)) {
            scp_json_error('Email address and password are required.');
        }

        $recaptchaCheck = SCP_Security::verifyRecaptcha($_POST['g-recaptcha-response'] ?? '', 'register');
        if ($recaptchaCheck !== true) {
            scp_json_error($recaptchaCheck);
        }

        $res = $auth->register($email, $password, $firstName, $lastName, $company, $stripe, 'client');
        if (!empty($res['error'])) {
            SCP_Security::recordFailedAttempt('register', 900);
            scp_json_error($res['error']);
        }

        SCP_Security::clearRateLimit('register');
        session_regenerate_id(true);
        $_SESSION['scp_user_id'] = (int)$res['user_id'];
        $_SESSION['scp_user_role'] = 'client';

        scp_json_success(array(
            'message'  => 'Account created & synced with Stripe! Redirecting to your portal...',
            'redirect' => 'index.php?page=portal'
        ));
        break;

    case 'scp_contact_submit':
        $rateCheck = SCP_Security::checkRateLimit('contact', 5, 900);
        if ($rateCheck !== true) {
            scp_json_error($rateCheck);
        }

        $name       = str_replace(array("\r", "\n"), ' ', trim($_POST['name'] ?? ''));
        $email      = str_replace(array("\r", "\n"), '', trim($_POST['email'] ?? ''));
        $phone      = str_replace(array("\r", "\n"), ' ', trim($_POST['phone'] ?? ''));
        $department = str_replace(array("\r", "\n"), ' ', trim($_POST['department'] ?? 'General Inquiry'));
        $subject    = str_replace(array("\r", "\n"), ' ', trim($_POST['subject'] ?? ''));
        $message    = trim($_POST['message'] ?? '');

        if (empty($name) || empty($email) || empty($subject) || empty($message)) {
            scp_json_error('Please complete all required fields.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            scp_json_error('Please enter a valid email address.');
        }

        $recaptchaCheck = SCP_Security::verifyRecaptcha($_POST['g-recaptcha-response'] ?? '', 'contact');
        if ($recaptchaCheck !== true) {
            SCP_Security::recordFailedAttempt('contact', 900);
            scp_json_error($recaptchaCheck);
        }

        $stmt = $db->getConnection()->prepare(
            "INSERT INTO scp_contact_messages (name, email, phone, department, subject, message, status, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'unread', ?, NOW())"
        );
        $stmt->execute(array($name, $email, $phone, $department, $subject, $message, SCP_Security::getClientIp()));

        // Optional email notification to support_email
        $supportEmail = $db->getSetting('support_email', 'support@example.com');
        $portalName   = $db->getSetting('company_name', 'Cloud Portal');
        $hostDomain   = parse_url(SCP_Security::getBaseUrl(), PHP_URL_HOST) ?: 'localhost';
        @mail(
            $supportEmail,
            '[' . $portalName . ' Inquiry] ' . $subject,
            "Name: $name\nEmail: $email\nPhone: $phone\nDepartment: $department\n\nMessage:\n$message",
            "From: no-reply@$hostDomain\r\nReply-To: $email"
        );

        scp_json_success(array('message' => 'Thank you! Your message has been received by our team and we will respond shortly.'));
        break;

    case 'scp_search_domain':
        $query = trim($_POST['domain_query'] ?? '');
        if (empty($query)) {
            scp_json_error('Please enter a domain name to search.');
        }
        $results = $domainManager->searchDomain($query);
        scp_json_success(array('results' => $results));
        break;

    // -------------------------------------------------------------------------
    // Authenticated Client Portal Actions
    // -------------------------------------------------------------------------
    case 'scp_update_profile':
        if (!$auth->isLoggedIn()) {
            scp_json_error('Please sign in first.', 401);
        }
        $res = $auth->updateProfile($auth->getCurrentUserId(), $_POST, $stripe);
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        scp_json_success(array('message' => 'Your profile and Stripe billing information have been saved.'));
        break;

    case 'scp_change_password':
        if (!$auth->isLoggedIn()) {
            scp_json_error('Please sign in first.', 401);
        }
        $userId = $auth->getCurrentUserId();
        $rateCheck = SCP_Security::checkRateLimit('pw_change_' . $userId, 5, 900);
        if ($rateCheck !== true) {
            scp_json_error($rateCheck);
        }

        $res = $auth->changePassword($userId, $_POST['current_password'] ?? '', $_POST['new_password'] ?? '');
        if (!empty($res['error'])) {
            SCP_Security::recordFailedAttempt('pw_change_' . $userId, 900);
            scp_json_error($res['error']);
        }
        SCP_Security::clearRateLimit('pw_change_' . $userId);
        scp_json_success(array('message' => 'Your password has been updated successfully.'));
        break;

    case 'scp_checkout_domain':
        if (!$auth->isLoggedIn()) {
            scp_json_error('Please sign in or create an account to register domains.');
        }
        $userId     = $auth->getCurrentUserId();
        $domainName = $domainManager->validateDomainName($_POST['domain_name'] ?? '');
        if (!$domainName) {
            scp_json_error('Please provide a valid domain name.');
        }

        $domAction = ($_POST['domain_action'] ?? 'register') === 'renew' ? 'renew' : 'register';
        if ($domAction === 'renew') {
            $stmt = $db->getConnection()->prepare("SELECT id FROM scp_client_domains WHERE domain_name = ? AND user_id = ? LIMIT 1");
            $stmt->execute(array($domainName, $userId));
            if (!$stmt->fetch()) {
                scp_json_error('You can only renew domains registered under your account.');
            }
        }

        $years = max(1, min(10, (int)($_POST['years'] ?? 1)));
        $priceCents = $domainManager->calculateDomainCheckoutPrice($domainName, $years);
        if (is_array($priceCents) && isset($priceCents['error'])) {
            scp_json_error($priceCents['error']);
        }

        $session = $stripe->createDomainCheckoutSession($userId, $domainName, $domAction, $years, $priceCents);
        if (!empty($session['error']) || empty($session['url'])) {
            scp_json_error($session['error'] ?? 'Could not initialize Stripe checkout.');
        }
        scp_json_success(array('checkout_url' => $session['url']));
        break;

    case 'scp_update_nameservers':
        if (!$auth->isLoggedIn()) {
            scp_json_error('Please sign in first.', 401);
        }
        $domainName = trim($_POST['domain_name'] ?? '');
        $nsList     = array_filter(array_map('trim', explode("\n", $_POST['nameservers'] ?? '')));
        $res = $domainManager->updateNameservers($auth->getCurrentUserId(), $domainName, $nsList);
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        scp_json_success(array('message' => 'Nameservers updated successfully. DNS propagation may take 24-48 hours.'));
        break;

    case 'scp_stripe_checkout':
        $priceId = trim($_POST['price_id'] ?? '');
        $mode    = trim($_POST['mode'] ?? 'subscription');
        if (preg_match('#^https?://#i', $priceId)) {
            $validated = $stripe->validateCatalogCheckoutUrl($priceId);
            if (!empty($validated['error'])) {
                scp_json_error($validated['error']);
            }
            $checkoutUrl = $validated['url'];
            // Only append client_reference_id & prefilled_email when redirecting to an official Stripe domain
            if ($auth->isLoggedIn() && !empty($validated['is_stripe_hosted'])) {
                $currUser = $auth->getCurrentUser();
                $sep = (strpos($checkoutUrl, '?') === false) ? '?' : '&';
                $params = array('client_reference_id' => (int)$auth->getCurrentUserId());
                if (!empty($currUser['email']) && strpos($checkoutUrl, 'prefilled_email=') === false) {
                    $params['prefilled_email'] = $currUser['email'];
                }
                $checkoutUrl .= $sep . http_build_query($params);
            }
            scp_json_success(array('checkout_url' => $checkoutUrl));
        }
        if (!$auth->isLoggedIn()) {
            scp_json_error('Please sign in or create an account to subscribe.');
        }
        $session = $stripe->createCheckoutSession($auth->getCurrentUserId(), $priceId, $mode);
        if (!empty($session['error']) || empty($session['url'])) {
            scp_json_error($session['error'] ?? 'Could not create Stripe checkout session.');
        }
        scp_json_success(array('checkout_url' => $session['url']));
        break;

    case 'scp_stripe_portal':
        if (!$auth->isLoggedIn()) {
            scp_json_error('Please sign in first.', 401);
        }
        $customerId = $stripe->createOrGetCustomer($auth->getCurrentUserId());
        if (is_array($customerId) && isset($customerId['error'])) {
            scp_json_error($customerId['error']);
        }
        $session = $stripe->createCustomerPortalSession($customerId);
        if (!empty($session['error']) || empty($session['url'])) {
            scp_json_error($session['error'] ?? 'Could not open Stripe customer portal.');
        }
        scp_json_success(array('portal_url' => $session['url']));
        break;

    case 'scp_cancel_subscription':
        if (!$auth->isLoggedIn()) {
            scp_json_error('Please sign in first.', 401);
        }
        $subId = trim($_POST['subscription_id'] ?? '');
        $res = $stripe->cancelSubscription($subId, $auth->getCurrentUserId());
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        scp_json_success(array('message' => 'Subscription scheduled for cancellation at the end of the current billing period.'));
        break;

    case 'scp_sync_stripe':
        if (!$auth->isLoggedIn()) {
            scp_json_error('Please sign in first.', 401);
        }
        $userId = $auth->getCurrentUserId();
        $customerId = $stripe->createOrGetCustomer($userId);
        if (is_array($customerId) && isset($customerId['error'])) {
            scp_json_error($customerId['error']);
        }
        $stripe->syncCustomerDataFromStripe($userId);
        scp_json_success(array('message' => 'Synced all billing details, subscriptions, invoices, and quotes with Stripe!'));
        break;

    case 'scp_generate_sso_url':
        if (!$auth->isLoggedIn()) {
            scp_json_error('Please sign in first.', 401);
        }
        $siteId = (int)($_POST['site_id'] ?? 0);
        $res = $websiteManager->generateSsoUrlForSite($siteId, $auth->getCurrentUserId());
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        scp_json_success(array('sso_url' => $res['sso_url']));
        break;

    // -------------------------------------------------------------------------
    // Administrator Backend Actions
    // -------------------------------------------------------------------------
    case 'admin_save_settings':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $allowedKeys = array(
            'company_name', 'site_title', 'support_email', 'support_phone',
            'stripe_mode', 'stripe_currency',
            'active_registrar', 'nc_enabled', 'os_fallback_enabled',
            'price_markup_percent', 'default_nameservers',
            'nc_sandbox', 'nc_client_ip', 'os_environment'
        );
        foreach ($allowedKeys as $k) {
            if (isset($_POST[$k])) {
                $db->setSetting($k, trim($_POST[$k]));
            }
        }
        // Keep active_registrar aligned with Namecheap Primary + OpenSRS Fallback settings
        $ncEnabled = ($_POST['nc_enabled'] ?? 'yes') !== 'no';
        $osMode    = $_POST['os_fallback_enabled'] ?? 'yes';
        $db->setSetting('active_registrar', (!$ncEnabled || $osMode === 'primary') ? 'opensrs' : 'namecheap');
        if (!empty($_POST['stripe_webhook_secret'])) {
            $db->setSetting('stripe_webhook_secret', trim($_POST['stripe_webhook_secret']));
        }
        $db->logActivity($auth->getCurrentUserId(), 'admin_settings_saved', 'Administrator updated portal & API settings.');
        scp_json_success(array('message' => 'Portal & API settings saved successfully.'));
        break;

    case 'admin_upload_logo':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        if (empty($_FILES['logo']) || !isset($_FILES['logo']['error']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            $errCode = $_FILES['logo']['error'] ?? 'no_file';
            scp_json_error('Logo file upload failed (Error Code: ' . $errCode . ').', 400);
        }

        $file = $_FILES['logo'];
        $maxBytes = 2 * 1024 * 1024; // 2MB
        if ($file['size'] > $maxBytes) {
            scp_json_error('Logo image must be smaller than 2 MB.', 400);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = array('png', 'jpg', 'jpeg', 'svg', 'webp');
        if (!in_array($ext, $allowedExts, true)) {
            scp_json_error('Invalid image type. Supported formats: SVG, PNG, JPG, WebP.', 400);
        }

        // Additional MIME check
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) { finfo_close($finfo); }
        $allowedMimes = array(
            'image/png', 'image/jpeg', 'image/webp', 'image/svg+xml', 'text/plain', 'text/xml', 'image/svg'
        );
        if ($mime && !in_array($mime, $allowedMimes, true)) {
            scp_json_error('File content does not match a valid image format.', 400);
        }

        // Extra SVG sanitization if SVG
        if ($ext === 'svg') {
            $svgContent = file_get_contents($file['tmp_name']);
            if (preg_match('/<script|javascript:|onload=|onerror=/i', $svgContent)) {
                scp_json_error('Uploaded SVG file contains disallowed script elements.', 400);
            }
        }

        $uploadDir = SCP_ROOT_DIR . '/assets/uploads';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }
        // Protect uploads directory with .htaccess against direct script execution
        $htaccessPath = $uploadDir . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            @file_put_contents($htaccessPath, "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phps)$\">\n    Deny from all\n</FilesMatch>\nOptions -ExecCGI\n");
        }

        // Remove old custom logo if stored in assets/uploads
        $oldLogo = $db->getSetting('site_logo_url', '');
        if (!empty($oldLogo) && strpos($oldLogo, 'assets/uploads/') === 0) {
            $oldPath = SCP_ROOT_DIR . '/' . $oldLogo;
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        $fileName = 'portal-logo-' . time() . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
        $destPath = $uploadDir . '/' . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            scp_json_error('Failed to save uploaded logo file to destination directory.', 500);
        }

        $logoUrl = 'assets/uploads/' . $fileName;
        $db->setSetting('site_logo_url', $logoUrl);
        $db->logActivity($auth->getCurrentUserId(), 'admin_logo_uploaded', 'Administrator uploaded custom portal logo: ' . $fileName);

        scp_json_success(array(
            'message'  => 'Portal logo uploaded and updated successfully.',
            'logo_url' => $logoUrl
        ));
        break;

    case 'admin_remove_logo':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $currentLogo = $db->getSetting('site_logo_url', '');
        if (!empty($currentLogo) && strpos($currentLogo, 'assets/uploads/') === 0) {
            $filePath = SCP_ROOT_DIR . '/' . $currentLogo;
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
        $db->setSetting('site_logo_url', '');
        $defaultLogo = file_exists(SCP_ROOT_DIR . '/assets/logo.svg') ? 'assets/logo.svg' : 'assets/secure-cloud-portal-logo.svg';
        $db->logActivity($auth->getCurrentUserId(), 'admin_logo_removed', 'Administrator reset portal logo to default.');
        scp_json_success(array(
            'message'      => 'Portal logo reset to default.',
            'default_logo' => $defaultLogo
        ));
        break;

    case 'admin_add_website':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $clientUserId = (int)($_POST['user_id'] ?? 0);
        if ($clientUserId <= 0) {
            scp_json_error('Please select a client account.');
        }
        $res = $websiteManager->addWebsite($clientUserId, $_POST);
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        $db->logActivity($auth->getCurrentUserId(), 'admin_website_added', sprintf('Assigned website %s to user #%d.', $_POST['site_name'] ?? '', $clientUserId));
        scp_json_success(array('message' => 'Client website assigned successfully.'));
        break;

    case 'admin_delete_website':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $siteId = (int)($_POST['site_id'] ?? 0);
        $websiteManager->deleteWebsite($siteId);
        scp_json_success(array('message' => 'Website assignment removed.'));
        break;

    case 'admin_add_domain':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $res = $domainManager->adminAddDomain(
            (int)($_POST['user_id'] ?? 0),
            trim($_POST['domain_name'] ?? ''),
            trim($_POST['registrar'] ?? 'namecheap'),
            trim($_POST['expiration_date'] ?? ''),
            trim($_POST['nameservers'] ?? '')
        );
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        scp_json_success(array('message' => 'Domain assigned to client successfully.'));
        break;

    case 'admin_sync_client_stripe':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $cid = $stripe->createOrGetCustomer($targetUserId);
        if (is_array($cid) && isset($cid['error'])) {
            scp_json_error($cid['error']);
        }
        $stripe->syncCustomerDataFromStripe($targetUserId);
        $stripe->pushProfileToStripe($targetUserId);
        scp_json_success(array('message' => 'Client synced with Stripe (' . $cid . ').'));
        break;

    case 'admin_import_all_stripe':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $res = $stripe->importAllFromStripe($auth->getCurrentUserId());
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        scp_json_success(array(
            'message' => $res['message'],
            'stats'   => $res['stats'] ?? array()
        ));
        break;

    case 'admin_create_user':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $extraProfile = array(
            'phone'     => trim($_POST['phone'] ?? ''),
            'address_1' => trim($_POST['address_1'] ?? ''),
            'address_2' => trim($_POST['address_2'] ?? ''),
            'city'      => trim($_POST['city'] ?? ''),
            'state'     => trim($_POST['state'] ?? ''),
            'postcode'  => trim($_POST['postcode'] ?? ''),
            'country'   => trim($_POST['country'] ?? 'US') ?: 'US',
        );
        $res = $auth->register(
            trim($_POST['email'] ?? ''),
            $_POST['password'] ?? '',
            trim($_POST['first_name'] ?? ''),
            trim($_POST['last_name'] ?? ''),
            trim($_POST['company'] ?? ''),
            $stripe,
            ($_POST['role'] ?? 'client') === 'admin' ? 'admin' : 'client',
            $extraProfile
        );
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        $cidMsg = !empty($res['stripe_customer_id']) ? ' (Stripe ID: ' . $res['stripe_customer_id'] . ')' : '';
        scp_json_success(array('message' => 'User account created and synchronized with Stripe' . $cidMsg . '.'));
        break;

    case 'admin_update_inquiry':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $msgId = (int)($_POST['message_id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', array('read', 'replied', 'unread'), true) ? $_POST['status'] : 'read';
        $stmt = $db->getConnection()->prepare("UPDATE scp_contact_messages SET status = ? WHERE id = ?");
        $stmt->execute(array($status, $msgId));
        scp_json_success(array('message' => 'Inquiry status updated.'));
        break;

    case 'admin_save_category':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $catId       = (int)($_POST['category_id'] ?? 0);
        $catName     = trim($_POST['name'] ?? '');
        $catDesc     = trim($_POST['description'] ?? '');
        $sortOrder   = (int)($_POST['sort_order'] ?? 0);
        $assignPrice = trim($_POST['assign_price_id'] ?? '');
        $noteIcon    = trim($_POST['bottom_note_icon'] ?? 'green-exclamation');
        $noteTitle   = trim($_POST['bottom_note_title'] ?? '');
        $noteText    = trim($_POST['bottom_note_text'] ?? '');
        $notesJson   = $_POST['bottom_notes_json'] ?? null;

        $res = $stripe->saveCategory($catId, $catName, $catDesc, $sortOrder, $noteIcon, $noteTitle, $noteText, $notesJson);
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        if (!empty($assignPrice) && !empty($res['category_id'])) {
            $stripe->toggleProductCategory($assignPrice, (int)$res['category_id'], true);
        }
        $db->logActivity($auth->getCurrentUserId(), 'admin_category_saved', sprintf('Saved product category "%s".', $catName));
        scp_json_success(array(
            'message'     => !empty($assignPrice) ? sprintf('Category "%s" saved and assigned to product!', $catName) : sprintf('Category "%s" saved successfully.', $catName),
            'category_id' => $res['category_id'],
            'name'        => $res['name'],
            'slug'        => $res['slug']
        ));
        break;

    case 'admin_delete_category':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $catId = (int)($_POST['category_id'] ?? 0);
        $res = $stripe->deleteCategory($catId);
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        $db->logActivity($auth->getCurrentUserId(), 'admin_category_deleted', sprintf('Deleted product category #%d.', $catId));
        scp_json_success(array('message' => 'Category deleted successfully.'));
        break;

    case 'admin_toggle_product_visibility':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $priceId = trim($_POST['price_id'] ?? '');
        $visible = !empty($_POST['visible']) && $_POST['visible'] !== '0' ? 1 : 0;
        $res = $stripe->setProductVisibility($priceId, $visible);
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        scp_json_success(array(
            'message'        => $visible ? 'Product enabled on website & client portal.' : 'Product hidden from website & client portal.',
            'portal_visible' => $visible
        ));
        break;

    case 'admin_toggle_product_category':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $priceId = trim($_POST['price_id'] ?? '');
        $catId   = (int)($_POST['category_id'] ?? 0);
        $assign  = !empty($_POST['assign']) && $_POST['assign'] !== '0';
        $res = $stripe->toggleProductCategory($priceId, $catId, $assign);
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        scp_json_success(array(
            'message'  => $assign ? 'Category assigned to product.' : 'Category removed from product.',
            'assigned' => $assign ? 1 : 0
        ));
        break;

    case 'admin_get_client_billing':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $res = $stripe->getClientBillingHistory($targetUserId);
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        scp_json_success($res);
        break;

    case 'admin_save_catalog_product':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $res = $stripe->saveCatalogProduct($_POST);
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        $db->logActivity($auth->getCurrentUserId(), 'admin_catalog_product_saved', sprintf('Saved custom catalog product #%d ("%s").', (int)$res['product_id'], trim($_POST['name'] ?? '')));
        scp_json_success($res);
        break;

    case 'admin_delete_catalog_product':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $prodId = (int)($_POST['product_id'] ?? 0);
        $res = $stripe->deleteCatalogProduct($prodId);
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        $db->logActivity($auth->getCurrentUserId(), 'admin_catalog_product_deleted', sprintf('Deleted custom catalog product #%d.', $prodId));
        scp_json_success(array('message' => 'Product deleted from catalog.'));
        break;

    case 'admin_split_term_to_product':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $prodId  = (int)($_POST['product_id'] ?? 0);
        $termIdx = (int)($_POST['term_index'] ?? -1);
        if ($termIdx < 0) {
            scp_json_error('Invalid price-point / term index.');
        }
        $res = $stripe->splitTermToNewProduct($prodId, $termIdx, $_POST);
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        $db->logActivity(
            $auth->getCurrentUserId(),
            'admin_catalog_term_split',
            sprintf('Split term #%d from product #%d into new catalog product #%d.', $termIdx, (int)$res['source_product_id'], (int)$res['new_product_id'])
        );
        scp_json_success($res);
        break;

    case 'admin_import_stripe_to_catalog':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $res = $stripe->importStripeProductsToCatalog();
        $cnt = (int)($res['imported'] ?? 0);
        scp_json_success(array(
            'message'  => $cnt > 0 ? sprintf('Imported %d Stripe product(s) into the custom product builder!', $cnt) : 'All active Stripe products are already in your custom catalog.',
            'imported' => $cnt,
        ));
        break;

    // -------------------------------------------------------------------------
    // Administrator Email Client (Titan SMTP / IMAP)
    // -------------------------------------------------------------------------
    case 'admin_email_send':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $rateCheck = SCP_Security::checkRateLimit('admin_email_send_' . $auth->getCurrentUserId(), 60, 3600);
        if ($rateCheck !== true) {
            scp_json_error($rateCheck);
        }
        $mailer = new SCP_EmailService();
        $res = $mailer->sendClientEmail(array(
            'to_email'       => str_replace(array("\r", "\n"), '', trim($_POST['to_email'] ?? '')),
            'subject'        => str_replace(array("\r", "\n"), ' ', trim($_POST['subject'] ?? '')),
            'message'        => (string)($_POST['message'] ?? ''),
            'attention_name' => str_replace(array("\r", "\n"), ' ', trim($_POST['attention_name'] ?? '')),
            'user_id'        => (int)($_POST['user_id'] ?? 0),
            'reply_to_id'    => (int)($_POST['reply_to_id'] ?? 0),
            'inquiry_id'     => (int)($_POST['inquiry_id'] ?? 0),
            'include_quoted' => !empty($_POST['include_quoted']),
        ));
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        SCP_Security::recordFailedAttempt('admin_email_send_' . $auth->getCurrentUserId(), 3600);
        $db->logActivity($auth->getCurrentUserId(), 'admin_email_sent', sprintf('Sent email "%s" to %s.', trim($_POST['subject'] ?? ''), $res['to_email']));
        $res['counts'] = $mailer->getFolderCounts();
        scp_json_success($res);
        break;

    case 'admin_email_preview':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $mailer = new SCP_EmailService();
        $info = $mailer->resolveClientRecipient(trim($_POST['to_email'] ?? ''), (int)($_POST['user_id'] ?? 0), trim($_POST['attention_name'] ?? ''));
        $html = $mailer->renderClientEmailTemplate(
            trim($_POST['subject'] ?? '') ?: '(No Subject)',
            $info['attention_name'],
            (string)($_POST['message'] ?? ''),
            $info['email']
        );
        scp_json_success(array('html' => $html));
        break;

    case 'admin_email_list':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $mailer = new SCP_EmailService();
        scp_json_success(array(
            'messages' => $mailer->getMessages(trim($_POST['folder'] ?? 'inbox'), trim($_POST['search'] ?? ''), (int)($_POST['user_id'] ?? 0)),
            'counts'   => $mailer->getFolderCounts(),
        ));
        break;

    case 'admin_email_get':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $mailer = new SCP_EmailService();
        $res = $mailer->getMessageDetailWithThread((int)($_POST['email_id'] ?? 0), true);
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        scp_json_success($res);
        break;

    case 'admin_email_action':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $ids = $_POST['ids'] ?? array();
        if (!is_array($ids)) {
            $ids = array_filter(explode(',', (string)$ids));
        }
        $mailer = new SCP_EmailService();
        $res = $mailer->updateMessagesStatus($ids, trim($_POST['email_action'] ?? ''));
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        scp_json_success($res);
        break;

    case 'admin_email_sync':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        @set_time_limit(120);
        $mailer = new SCP_EmailService();
        $res = $mailer->syncFromImap(60);
        if (!empty($res['error'])) {
            scp_json_error($res['error']);
        }
        $res['counts'] = $mailer->getFolderCounts();
        scp_json_success($res);
        break;

    case 'admin_email_save_settings':
        if (!$auth->isAdmin()) {
            scp_json_error('Unauthorized administrator action.', 403);
        }
        $mailKeys = array('mail_email', 'mail_from_name', 'mail_imap_host', 'mail_imap_port', 'mail_smtp_host', 'mail_smtp_port', 'mail_encryption');
        foreach ($mailKeys as $k) {
            if (isset($_POST[$k])) {
                $db->setSetting($k, str_replace(array("\r", "\n"), '', trim($_POST[$k])));
            }
        }
        if (!empty($_POST['mail_password'])) {
            $db->setSetting('mail_password', (string)$_POST['mail_password']);
        }
        $db->logActivity($auth->getCurrentUserId(), 'admin_email_settings_saved', 'Administrator updated email server settings.');
        scp_json_success(array('message' => 'Email server settings saved.'));
        break;

    default:
        scp_json_error('Unknown API action requested.', 400);
}
