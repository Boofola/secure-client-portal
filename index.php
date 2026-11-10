<?php
/**
 * Main Front Controller & Router
 * Secure Cloud Portal Stand-Alone v2.0.0
 */

require_once __DIR__ . '/bootstrap.php';

$db = SCP_Database::getInstance();

// If database is not configured yet, redirect to the 1-Click Setup Wizard
if (!$db->isConfigured()) {
    header('Location: install.php');
    exit;
}

$auth           = new SCP_Auth();
$stripe         = new SCP_StripeService();
$namecheap      = new SCP_NamecheapService();
$opensrs        = new SCP_OpenSRSService();
$domainManager  = new SCP_DomainManager($namecheap, $opensrs);
$websiteManager = new SCP_WebsiteManager();

$page = strtolower(trim($_GET['page'] ?? 'home'));

// Handle Logout
if ($page === 'logout') {
    $auth->logout();
    header('Location: index.php?page=login&logged_out=1');
    exit;
}

// Handle Companion WordPress SSO Plugin Download
if ($page === 'download-sso-companion') {
    $siteId    = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
    $ssoSecret = '';
    $siteName  = '';

    if ($siteId > 0 && $auth->isLoggedIn() && $auth->isAdmin()) {
        $site = $websiteManager->getWebsiteById($siteId);
        if (!empty($site['sso_secret'])) {
            $ssoSecret = $site['sso_secret'];
            $siteName  = $site['site_name'] ?? '';
        }
    }

    $sourceCode = $websiteManager->getCompanionPluginSource($ssoSecret, $siteName);
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="secure-cloud-portal-client-sso.php"');
    header('Content-Length: ' . strlen($sourceCode));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $sourceCode;
    exit;
}

// Route Guards for Protected Backend Pages
if ($page === 'portal') {
    if (!$auth->isLoggedIn()) {
        header('Location: index.php?page=login');
        exit;
    }
} elseif ($page === 'admin') {
    if (!$auth->isLoggedIn()) {
        header('Location: index.php?page=login');
        exit;
    }
    if (!$auth->isAdmin()) {
        header('Location: index.php?page=portal');
        exit;
    }
} elseif ($page === 'login' || $page === 'register') {
    if ($auth->isLoggedIn()) {
        header('Location: index.php?page=' . ($auth->isAdmin() ? 'admin' : 'portal'));
        exit;
    }
}

$currentUser = $auth->getCurrentUser();
$companyName = $db->getSetting('company_name', 'Cloud Portal');
$siteTitle = $db->getSetting('site_title', 'Enterprise Cloud Hosting, Domains & Client Portal');
$siteLogoUrl = $db->getSetting('site_logo_url', '');
if (empty($siteLogoUrl)) {
    if (file_exists(SCP_ROOT_DIR . '/assets/logo.svg')) {
        $siteLogoUrl = 'assets/logo.svg';
    } elseif (file_exists(SCP_ROOT_DIR . '/assets/secure-cloud-portal-logo.svg')) {
        $siteLogoUrl = 'assets/secure-cloud-portal-logo.svg';
    }
}
$supportEmail = $db->getSetting('support_email', 'support@example.com');
$supportPhone = $db->getSetting('support_phone', '+1 (800) 555-0199');
$csrfToken = SCP_Security::getCsrfToken();
$recaptchaSiteKey = SCP_Security::getRecaptchaSiteKey();

// Map page title
$titles = array(
    'home'          => $siteTitle,
    'about'         => 'About Us & Infrastructure',
    'contact'       => 'Contact Support & Sales',
    'privacy'       => 'Privacy Policy',
    'terms'         => 'Terms of Service',
    'refund-policy' => 'Billing & Refund Policy',
    'login'         => 'Client Sign In',
    'register'      => 'Create Client Account',
    'portal'        => 'Client Portal Dashboard',
    'admin'         => 'Admin Control Center',
);
$pageTitle = ($titles[$page] ?? $siteTitle) . ' — ' . $companyName;

require __DIR__ . '/views/partials/header.php';

switch ($page) {
    case 'home':
        $catalog            = $stripe->getProductsAndPrices();
        $categorizedCatalog = $stripe->getCategorizedCatalog();
        require __DIR__ . '/views/public/home.php';
        break;

    case 'about':
        require __DIR__ . '/views/public/about.php';
        break;

    case 'contact':
        require __DIR__ . '/views/public/contact.php';
        break;

    case 'privacy':
    case 'terms':
    case 'refund-policy':
        $policyType = $page;
        require __DIR__ . '/views/public/policies.php';
        break;

    case 'login':
    case 'register':
        $initialAuthMode = ($page === 'register') ? 'register' : 'login';
        require __DIR__ . '/views/public/login.php';
        break;

    case 'portal':
        $userId = $auth->getCurrentUserId();
        $customerId = $currentUser['stripe_customer_id'] ?? '';
        if (empty($customerId)) {
            $cid = $stripe->createOrGetCustomer($userId);
            if (is_string($cid)) {
                $customerId = $cid;
                $currentUser = $auth->getCurrentUser(true);
            }
        }
        // Immediately verify & provision any completed Stripe Checkout Session on redirect return
        if (!empty($_GET['session_id'])) {
            $stripe->verifyAndProcessCheckoutSession($_GET['session_id'], $userId, $domainManager);
        }
        $subscriptions      = $stripe->getActiveSubscriptions($customerId);
        $invoices           = $stripe->getInvoices($customerId);
        $quotes             = $stripe->getQuotes($customerId);
        $payments           = $stripe->getPayments($customerId);
        $catalog            = $stripe->getProductsAndPrices();
        $categorizedCatalog = $stripe->getCategorizedCatalog();
        $domains            = $domainManager->getUserDomains($userId);
        $websites           = $websiteManager->getUserWebsites($userId);
        require __DIR__ . '/views/portal/dashboard.php';
        break;

    case 'admin':
        if (defined('SCP_STRIPE_WEBHOOK_SECRET') && !empty(SCP_STRIPE_WEBHOOK_SECRET) && empty($db->getSetting('stripe_webhook_secret', ''))) {
            try {
                $db->setSetting('stripe_webhook_secret', SCP_STRIPE_WEBHOOK_SECRET);
            } catch (Exception $ignore) {}
        }
        $inquiries = array();
        $logs      = array();
        try {
            $db->installSchema();
            // Run automatic initial Stripe full import if never run before
            if (empty($db->getSetting('last_full_stripe_sync', ''))) {
                $stripe->importAllFromStripe($auth->getCurrentUserId());
            }
            $inquiries = $db->getConnection()->query("SELECT * FROM scp_contact_messages ORDER BY id DESC LIMIT 100")->fetchAll();
            $logs      = $db->getConnection()->query(
                "SELECT l.*, u.email AS user_email
                 FROM scp_activity_log l
                 LEFT JOIN scp_users u ON l.user_id = u.id
                 ORDER BY l.id DESC LIMIT 100"
            )->fetchAll();
        } catch (Exception $e) {
            // Ignore schema errors
        }
        $allUsers              = $auth->getAllUsers();
        $allWebsites           = $websiteManager->getAllWebsites();
        $platformTypes         = SCP_WebsiteManager::getPlatformTypes();
        $allDomains            = $domainManager->getAllDomains();
        $settings              = $db->getAllSettings();
        $importedProducts      = $stripe->getImportedProducts();
        $catalogProducts       = $stripe->getCatalogProducts(false);
        $productCategories     = $stripe->getCategories();
        $importedSubscriptions = $stripe->getImportedSubscriptions();
        $importedInvoices      = $stripe->getImportedInvoices();
        $importedPayments      = $stripe->getImportedPayments();
        $importedQuotes        = $stripe->getImportedQuotes();
        $billingSummary        = $stripe->getExecutiveBillingSummary($importedInvoices, $importedPayments, $importedSubscriptions, $importedQuotes);
        $emailCounts           = array('inbox' => 0, 'inbox_unread' => 0, 'unread' => 0, 'starred' => 0, 'sent' => 0, 'trash' => 0);
        $mailConfig            = array();
        try {
            $mailer      = new SCP_EmailService();
            $emailCounts = $mailer->getFolderCounts();
            $mailConfig  = $mailer->getConfig();
            unset($mailConfig['password']);
        } catch (Exception $e) {
            // Email tables not ready yet
        }
        require __DIR__ . '/views/admin/dashboard.php';
        break;

    default:
        $catalog            = $stripe->getProductsAndPrices();
        $categorizedCatalog = $stripe->getCategorizedCatalog();
        require __DIR__ . '/views/public/home.php';
        break;
}

require __DIR__ . '/views/partials/footer.php';
