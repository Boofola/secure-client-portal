<?php
/**
 * MySQL / MariaDB Database Configuration
 *
 * You can either rename this file to `database.php` and fill in your credentials below,
 * OR visit /install.php in your browser to configure
 * the database and initial administrator account automatically.
 */

if (!defined('SCP_APP_INIT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

define('SCP_DB_HOST', 'localhost');
define('SCP_DB_PORT', '3306');
define('SCP_DB_NAME', 'your_database_name');
define('SCP_DB_USER', 'your_database_user');
define('SCP_DB_PASS', 'your_database_password');
define('SCP_DB_CHARSET', 'utf8mb4');
define('SCP_SITE_URL', 'https://portal.example.com');
