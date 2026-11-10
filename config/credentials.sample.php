<?php
/**
 * Sample Credentials Store for Cloud Portal
 *
 * Copy this file to `config/credentials.php` and provide your API credentials.
 * This file is protected by config/.htaccess and the SCP_APP_INIT constant check.
 */

if (!defined('SCP_APP_INIT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

// -----------------------------------------------------------------------------
// 1. Stripe API Credentials
// -----------------------------------------------------------------------------
if (!defined('SCP_STRIPE_TEST_PK')) {
    define('SCP_STRIPE_TEST_PK', 'pk_test_YOUR_STRIPE_TEST_PUBLISHABLE_KEY');
}
if (!defined('SCP_STRIPE_TEST_SK')) {
    define('SCP_STRIPE_TEST_SK', 'sk_test_YOUR_STRIPE_TEST_SECRET_KEY');
}
if (!defined('SCP_STRIPE_LIVE_PK')) {
    define('SCP_STRIPE_LIVE_PK', 'pk_live_YOUR_STRIPE_LIVE_PUBLISHABLE_KEY');
}
if (!defined('SCP_STRIPE_LIVE_SK')) {
    define('SCP_STRIPE_LIVE_SK', 'sk_live_YOUR_STRIPE_LIVE_SECRET_KEY');
}
if (!defined('SCP_STRIPE_WEBHOOK_SECRET')) {
    define('SCP_STRIPE_WEBHOOK_SECRET', 'whsec_YOUR_STRIPE_WEBHOOK_SECRET');
}

// -----------------------------------------------------------------------------
// 2. OpenSRS API Credentials (Optional - Tucows Registrar Fallback)
// -----------------------------------------------------------------------------
if (!defined('SCP_OPENSRS_USERNAME')) {
    define('SCP_OPENSRS_USERNAME', 'your_opensrs_username');
}
if (!defined('SCP_OPENSRS_PASSWORD')) {
    define('SCP_OPENSRS_PASSWORD', 'your_opensrs_password');
}
if (!defined('SCP_OPENSRS_API_KEY')) {
    define('SCP_OPENSRS_API_KEY', 'your_opensrs_api_key');
}

// -----------------------------------------------------------------------------
// 3. Namecheap API Credentials (Optional - Default Primary Registrar)
// -----------------------------------------------------------------------------
if (!defined('SCP_NAMECHEAP_USERNAME')) {
    define('SCP_NAMECHEAP_USERNAME', 'your_namecheap_username');
}
if (!defined('SCP_NAMECHEAP_API_USER')) {
    define('SCP_NAMECHEAP_API_USER', 'your_namecheap_api_user');
}
if (!defined('SCP_NAMECHEAP_API_KEY')) {
    define('SCP_NAMECHEAP_API_KEY', 'your_namecheap_api_key');
}

// -----------------------------------------------------------------------------
// 4. Google reCAPTCHA Enterprise & Google Cloud OAuth (Optional)
// -----------------------------------------------------------------------------
if (!defined('SCP_RECAPTCHA_SITE_KEY')) {
    define('SCP_RECAPTCHA_SITE_KEY', '');
}
if (!defined('SCP_RECAPTCHA_API_KEY')) {
    define('SCP_RECAPTCHA_API_KEY', '');
}
if (!defined('SCP_RECAPTCHA_PROJECT_ID')) {
    define('SCP_RECAPTCHA_PROJECT_ID', '');
}
if (!defined('SCP_GOOGLE_OAUTH_CLIENT_ID')) {
    define('SCP_GOOGLE_OAUTH_CLIENT_ID', '');
}
if (!defined('SCP_GOOGLE_OAUTH_CLIENT_SECRET')) {
    define('SCP_GOOGLE_OAUTH_CLIENT_SECRET', '');
}
