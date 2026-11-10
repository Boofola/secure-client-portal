<?php
/**
 * Application Bootstrap Loader
 * Secure Cloud Portal Stand-Alone v2.0.0
 */

define('SCP_APP_INIT', true);
define('SCP_VERSION', '3.5.0');
define('SCP_ROOT_DIR', __DIR__);

if (file_exists(SCP_ROOT_DIR . '/config/credentials.php')) {
    require_once SCP_ROOT_DIR . '/config/credentials.php';
} elseif (file_exists(SCP_ROOT_DIR . '/config/credentials.sample.php')) {
    require_once SCP_ROOT_DIR . '/config/credentials.sample.php';
}

if (file_exists(SCP_ROOT_DIR . '/config/mail.php')) {
    require_once SCP_ROOT_DIR . '/config/mail.php';
} elseif (file_exists(SCP_ROOT_DIR . '/config/mail.sample.php')) {
    require_once SCP_ROOT_DIR . '/config/mail.sample.php';
}
require_once SCP_ROOT_DIR . '/app/HttpClient.php';
require_once SCP_ROOT_DIR . '/app/Database.php';
require_once SCP_ROOT_DIR . '/app/Security.php';
require_once SCP_ROOT_DIR . '/app/Auth.php';
require_once SCP_ROOT_DIR . '/app/StripeService.php';
require_once SCP_ROOT_DIR . '/app/NamecheapService.php';
require_once SCP_ROOT_DIR . '/app/OpenSRSService.php';
require_once SCP_ROOT_DIR . '/app/DomainManager.php';
require_once SCP_ROOT_DIR . '/app/WebsiteManager.php';
require_once SCP_ROOT_DIR . '/app/EmailService.php';

SCP_Security::sendSecurityHeaders();
SCP_Security::initSession();
