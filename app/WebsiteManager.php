<?php
/**
 * Stand-Alone Client Websites Manager & One-Click HMAC-SHA256 WordPress SSO
 * Secure Cloud Portal Stand-Alone v2.0.0
 */

if (!defined('SCP_APP_INIT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

class SCP_WebsiteManager {
    private $db;

    public function __construct() {
        $this->db = SCP_Database::getInstance();
    }

    /**
     * Get supported hosted platform / service types
     */
    public static function getPlatformTypes() {
        return array(
            'wordpress'  => array(
                'key'         => 'wordpress',
                'label'       => 'WordPress (with 1-Click SSO)',
                'short_label' => 'WordPress',
                'badge_bg'    => '#e0f2fe',
                'badge_color' => '#0369a1',
                'default_plan'=> 'Managed Cloud WordPress',
                'login_hint'  => '/wp-login.php',
            ),
            'ad_server'  => array(
                'key'         => 'ad_server',
                'label'       => 'Ad Server',
                'short_label' => 'Ad Server',
                'badge_bg'    => '#fef3c7',
                'badge_color' => '#b45309',
                'default_plan'=> 'Managed Ad Server Cluster',
                'login_hint'  => '/admin',
            ),
            'mass_email' => array(
                'key'         => 'mass_email',
                'label'       => 'Mass Emailing Service',
                'short_label' => 'Mass Emailing',
                'badge_bg'    => '#f3e8ff',
                'badge_color' => '#7e22ce',
                'default_plan'=> 'Dedicated Mass Emailing Server',
                'login_hint'  => '/login',
            ),
            'cloud_vps'  => array(
                'key'         => 'cloud_vps',
                'label'       => 'Cloud / VPS Server or Custom Web Application',
                'short_label' => 'Cloud / VPS App',
                'badge_bg'    => '#dcfce7',
                'badge_color' => '#15803d',
                'default_plan'=> 'Managed Cloud & VPS Hosting',
                'login_hint'  => '',
            ),
        );
    }

    /**
     * Enrich a website/platform row with platform metadata and links
     */
    private function enrichSiteRow($site) {
        $types = self::getPlatformTypes();
        $pType = strtolower(trim($site['platform_type'] ?? 'wordpress'));
        if (!isset($types[$pType])) {
            $pType = 'wordpress';
        }
        $meta = $types[$pType];
        $safeSiteUrl  = SCP_Security::validateHttpUrl($site['site_url'] ?? '') ?: '#';
        $safeLoginUrl = !empty($site['login_url']) ? (SCP_Security::validateHttpUrl($site['login_url']) ?: '') : '';
        $site['site_url']             = $safeSiteUrl;
        $site['login_url']            = $safeLoginUrl;
        $site['platform_type']        = $pType;
        $site['platform_label']       = $meta['label'];
        $site['platform_short_label'] = $meta['short_label'];
        $site['platform_badge_bg']    = $meta['badge_bg'];
        $site['platform_badge_color'] = $meta['badge_color'];
        $site['visit_link']           = $safeSiteUrl;
        if (!empty($safeLoginUrl)) {
            $site['login_link'] = $safeLoginUrl;
        } elseif ($pType === 'wordpress' && $safeSiteUrl !== '#') {
            $site['login_link'] = rtrim($safeSiteUrl, '/') . '/wp-login.php';
        } else {
            $site['login_link'] = $safeSiteUrl;
        }
        return $site;
    }

    /**
     * Get websites & hosted platforms assigned to a client user
     */
    public function getUserWebsites($userId) {
        $stmt = $this->db->getConnection()->prepare("SELECT * FROM scp_client_websites WHERE user_id = ? ORDER BY site_name ASC");
        $stmt->execute(array((int)$userId));
        $sites = $stmt->fetchAll();

        foreach ($sites as &$site) {
            $site = $this->enrichSiteRow($site);
        }
        return $sites;
    }

    /**
     * Admin: Get all websites & hosted platforms across all clients
     */
    public function getAllWebsites() {
        $stmt = $this->db->getConnection()->query(
            "SELECT w.*, u.email AS user_email, u.first_name, u.last_name
             FROM scp_client_websites w
             LEFT JOIN scp_users u ON w.user_id = u.id
             ORDER BY w.id DESC"
        );
        $sites = $stmt->fetchAll();
        foreach ($sites as &$site) {
            $site = $this->enrichSiteRow($site);
        }
        return $sites;
    }

    /**
     * Generate an on-demand, ownership-verified 60-second single-use SSO URL for a client website
     */
    public function generateSsoUrlForSite($siteId, $userId) {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM scp_client_websites WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute(array((int)$siteId, (int)$userId));
        $site = $stmt->fetch();

        if (!$site) {
            return array('error' => 'Website not found or not assigned to your account.');
        }

        if (empty($site['enable_auto_login']) || empty($site['sso_secret'])) {
            return array('error' => 'One-click SSO login is not enabled for this website.');
        }

        $uStmt = $pdo->prepare("SELECT email FROM scp_users WHERE id = ? LIMIT 1");
        $uStmt->execute(array((int)$userId));
        $user = $uStmt->fetch();
        if (!$user) {
            return array('error' => 'User account not found.');
        }

        $targetEmail = !empty($site['wp_admin_email']) ? $site['wp_admin_email'] : $user['email'];
        $expires     = time() + 60; // 60 seconds validity
        $nonce       = bin2hex(random_bytes(12));

        // Signature: HMAC-SHA256(site_id | email | expires | nonce, sso_secret)
        $payload   = $site['id'] . '|' . $targetEmail . '|' . $expires . '|' . $nonce;
        $signature = hash_hmac('sha256', $payload, $site['sso_secret']);

        $sep = (strpos($site['site_url'], '?') === false) ? '?' : '&';
        $ssoUrl = rtrim($site['site_url'], '/') . '/' . $sep . http_build_query(array(
            'scp_sso' => '1',
            'site_id' => $site['id'],
            'email'   => $targetEmail,
            'expires' => $expires,
            'nonce'   => $nonce,
            'sig'     => $signature,
        ));

        $this->db->logActivity($userId, 'website_sso_launch', sprintf('Generated one-time SSO login token for %s (%s).', $site['site_name'], $site['site_url']));

        return array('success' => true, 'sso_url' => $ssoUrl);
    }

    /**
     * Admin: Add new client website or hosted platform assignment
     */
    public function addWebsite($userId, $data) {
        $ssoSecret = !empty($data['sso_secret']) ? trim($data['sso_secret']) : bin2hex(random_bytes(24));
        $siteUrl   = SCP_Security::validateHttpUrl($data['site_url'] ?? '');
        if (!$siteUrl) {
            return array('error' => 'Please enter a valid HTTP/HTTPS Website or Platform URL (including https://).');
        }
        $rawLoginUrl = trim($data['login_url'] ?? '');
        $loginUrl    = '';
        if ($rawLoginUrl !== '') {
            $loginUrl = SCP_Security::validateHttpUrl($rawLoginUrl);
            if (!$loginUrl) {
                return array('error' => 'Please enter a valid HTTP/HTTPS Control Panel / Login URL (including https://).');
            }
        }

        $types = self::getPlatformTypes();
        $platformType = strtolower(trim($data['platform_type'] ?? 'wordpress'));
        if (!isset($types[$platformType])) {
            $platformType = 'wordpress';
        }
        $defaultPlan = $types[$platformType]['default_plan'];

        // Ensure platform_type column exists before inserting
        try {
            $this->db->installSchema();
        } catch (Exception $e) {}

        $stmt = $this->db->getConnection()->prepare(
            "INSERT INTO scp_client_websites (user_id, platform_type, site_name, site_url, login_url, enable_auto_login, sso_secret, wp_admin_email, hosting_plan, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute(array(
            (int)$userId,
            $platformType,
            trim($data['site_name'] ?? 'Client Platform'),
            $siteUrl,
            $loginUrl,
            ($platformType === 'wordpress' && !empty($data['enable_auto_login'])) ? 1 : 0,
            $ssoSecret,
            trim($data['wp_admin_email'] ?? ''),
            trim($data['hosting_plan'] ?? '') ?: $defaultPlan,
            trim($data['notes'] ?? '')
        ));

        return array('success' => true, 'id' => (int)$this->db->getConnection()->lastInsertId());
    }

    /**
     * Admin: Delete website assignment
     */
    public function deleteWebsite($siteId) {
        $stmt = $this->db->getConnection()->prepare("DELETE FROM scp_client_websites WHERE id = ?");
        $stmt->execute(array((int)$siteId));
        return true;
    }

    /**
     * Admin: Get a single website record by ID
     */
    public function getWebsiteById($siteId) {
        $stmt = $this->db->getConnection()->prepare("SELECT * FROM scp_client_websites WHERE id = ? LIMIT 1");
        $stmt->execute(array((int)$siteId));
        return $stmt->fetch();
    }

    /**
     * Generate the downloadable WordPress MU-Plugin PHP source code
     */
    public function getCompanionPluginSource($ssoSecret = '', $siteName = '') {
        $secretVal = !empty($ssoSecret) ? addslashes(trim($ssoSecret)) : 'PASTE_YOUR_SHARED_SECRET_HERE';
        $siteHeaderNote = !empty($siteName) ? " * Pre-configured for: " . str_replace(array("\r", "\n"), '', $siteName) . "\n" : '';
        $portalUrl = class_exists('SCP_Security') ? SCP_Security::getBaseUrl() : 'https://localhost';
        $portalName = class_exists('SCP_Database') ? SCP_Database::getInstance()->getSetting('company_name', 'Cloud Portal') : 'Cloud Portal';

        return <<<PHP
<?php
/**
 * Plugin Name: {$portalName} - Client Site SSO Receiver
 * Plugin URI: {$portalUrl}
 * Description: Enables secure one-click single sign-on (SSO) login directly from {$portalName} into a client WordPress website. Copy to wp-content/mu-plugins/secure-cloud-portal-client-sso.php on the client site.
 * Version: 2.3.3
 * Author: {$portalName}
{$siteHeaderNote} */

if (!defined('ABSPATH')) {
    exit;
}

// Shared 64-character SSO Secret Key matching Secure Cloud Portal -> Client Websites & SSO
if (!defined('SCP_CLIENT_SSO_SECRET')) {
    define('SCP_CLIENT_SSO_SECRET', '{$secretVal}');
}

add_action('init', function () {
    if (!empty(\$_GET['scp_sso']) && !empty(\$_GET['sig']) && !empty(\$_GET['email']) && !empty(\$_GET['expires']) && !empty(\$_GET['nonce'])) {
        \$secret = '';
        if (defined('SCP_CLIENT_SSO_SECRET') && SCP_CLIENT_SSO_SECRET !== 'PASTE_YOUR_SHARED_SECRET_HERE') {
            \$secret = SCP_CLIENT_SSO_SECRET;
        } elseif (defined('SCP_SSO_SHARED_SECRET') && SCP_SSO_SHARED_SECRET !== 'PASTE_YOUR_SHARED_SECRET_HERE') {
            \$secret = SCP_SSO_SHARED_SECRET;
        } else {
            \$secret = (string) get_option('scp_sso_shared_secret', '');
        }

        if (empty(\$secret) || \$secret === 'PASTE_YOUR_SHARED_SECRET_HERE') {
            wp_die(
                __('SSO shared secret is not configured on this website. Please set SCP_CLIENT_SSO_SECRET in wp-content/mu-plugins/secure-cloud-portal-client-sso.php.', 'scp-sso'),
                __('SSO Configuration Error', 'scp-sso'),
                array('response' => 403)
            );
        }

        \$email    = sanitize_email(rawurldecode(\$_GET['email']));
        \$expires  = intval(\$_GET['expires']);
        \$site_id  = intval(\$_GET['site_id'] ?? 0);
        \$nonce    = sanitize_text_field(\$_GET['nonce']);
        \$provided = sanitize_text_field(\$_GET['sig']);

        // 1. Check token expiration (strict short-lived window, max 120s in the future)
        \$now = time();
        if (\$now > \$expires || (\$expires - \$now) > 120) {
            wp_die(
                __('Login link has expired. Please launch a fresh login session from Secure Cloud Portal.', 'scp-sso'),
                __('Token Expired', 'scp-sso'),
                array('response' => 403)
            );
        }

        // 2. Verify cryptographic HMAC-SHA256 signature (includes unique one-time nonce)
        \$payload  = \$site_id . '|' . \$email . '|' . \$expires . '|' . \$nonce;
        \$expected = hash_hmac('sha256', \$payload, \$secret);

        if (!hash_equals(\$expected, \$provided)) {
            wp_die(
                __('Invalid security signature for SSO login.', 'scp-sso'),
                __('Access Denied', 'scp-sso'),
                array('response' => 403)
            );
        }

        // 3. Enforce single-use token (prevent replay attacks within expiration window)
        \$replay_key = 'scp_sso_used_' . md5(\$provided);
        if (get_transient(\$replay_key)) {
            wp_die(
                __('This one-time login link has already been used. Please launch a new session from Secure Cloud Portal.', 'scp-sso'),
                __('Token Already Used', 'scp-sso'),
                array('response' => 403)
            );
        }
        set_transient(\$replay_key, 1, 300);

        // 4. Match exact user by email address (never blindly fall back to an arbitrary administrator)
        \$user = get_user_by('email', \$email);
        if (!\$user) {
            wp_die(
                __('No matching WordPress user account found for your portal email address on this site.', 'scp-sso'),
                __('Login Error', 'scp-sso'),
                array('response' => 403)
            );
        }

        // 5. Log the user into WordPress session securely
        wp_clear_auth_cookie();
        wp_set_current_user(\$user->ID);
        wp_set_auth_cookie(\$user->ID, false, is_ssl());
        do_action('wp_login', \$user->user_login, \$user);

        // Redirect directly to WP-Admin dashboard
        wp_safe_redirect(admin_url());
        exit;
    }
});
PHP;
    }
}
