<?php
/**
 * Sample Email (IMAP / POP / SMTP) Server Configuration
 *
 * Copy this file to `config/mail.php` and configure your mail server settings.
 * Values here may also be configured directly via Admin -> API & Portal Settings.
 */

if (!defined('SCP_APP_INIT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

if (!defined('SCP_MAIL_EMAIL'))      { define('SCP_MAIL_EMAIL', 'support@example.com'); }
if (!defined('SCP_MAIL_PASSWORD'))   { define('SCP_MAIL_PASSWORD', ''); }
if (!defined('SCP_MAIL_FROM_NAME'))  { define('SCP_MAIL_FROM_NAME', 'Cloud Portal'); }
if (!defined('SCP_MAIL_IMAP_HOST'))  { define('SCP_MAIL_IMAP_HOST', 'imap.example.com'); }
if (!defined('SCP_MAIL_IMAP_PORT'))  { define('SCP_MAIL_IMAP_PORT', 993); }
if (!defined('SCP_MAIL_POP_HOST'))   { define('SCP_MAIL_POP_HOST', 'pop.example.com'); }
if (!defined('SCP_MAIL_POP_PORT'))   { define('SCP_MAIL_POP_PORT', 995); }
if (!defined('SCP_MAIL_SMTP_HOST'))  { define('SCP_MAIL_SMTP_HOST', 'smtp.example.com'); }
if (!defined('SCP_MAIL_SMTP_PORT'))  { define('SCP_MAIL_SMTP_PORT', 465); }
if (!defined('SCP_MAIL_ENCRYPTION')) { define('SCP_MAIL_ENCRYPTION', 'ssl'); }
