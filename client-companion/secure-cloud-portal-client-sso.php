<?php
/**
 * Plugin Name: Cloud Portal - Client Site SSO Receiver
 * Description: Enables secure one-click single sign-on (SSO) login directly from Cloud Portal into a client WordPress website. Copy to wp-content/mu-plugins/secure-cloud-portal-client-sso.php on the client site.
 * Version: 2.3.3
 * Author: Cloud Portal
 */

if (!defined('ABSPATH')) {
    // When requested directly on the Secure Cloud Portal server (e.g., clicking Download),
    // stream the clean WordPress MU-Plugin PHP source code as a file download instead of returning an empty response.
    $portalBootstrap = dirname(__DIR__) . '/bootstrap.php';
    if (is_file($portalBootstrap)) {
        require_once $portalBootstrap;
        $websiteManager = new SCP_WebsiteManager();
        $siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
        $ssoSecret = '';
        $siteName  = '';

        if ($siteId > 0) {
            $auth = new SCP_Auth();
            if ($auth->isLoggedIn() && $auth->isAdmin()) {
                $site = $websiteManager->getWebsiteById($siteId);
                if (!empty($site['sso_secret'])) {
                    $ssoSecret = $site['sso_secret'];
                    $siteName  = $site['site_name'] ?? '';
                }
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
    }
    exit;
}

// Shared 64-character SSO Secret Key matching Secure Cloud Portal -> Client Websites & SSO
if (!defined('SCP_CLIENT_SSO_SECRET')) {
    define('SCP_CLIENT_SSO_SECRET', 'PASTE_YOUR_SHARED_SECRET_HERE');
}

add_action('init', function () {
    if (!empty($_GET['scp_sso']) && !empty($_GET['sig']) && !empty($_GET['email']) && !empty($_GET['expires']) && !empty($_GET['nonce'])) {
        $secret = '';
        if (defined('SCP_CLIENT_SSO_SECRET') && SCP_CLIENT_SSO_SECRET !== 'PASTE_YOUR_SHARED_SECRET_HERE') {
            $secret = SCP_CLIENT_SSO_SECRET;
        } elseif (defined('SCP_SSO_SHARED_SECRET') && SCP_SSO_SHARED_SECRET !== 'PASTE_YOUR_SHARED_SECRET_HERE') {
            $secret = SCP_SSO_SHARED_SECRET;
        } else {
            $secret = (string) get_option('scp_sso_shared_secret', '');
        }

        if (empty($secret) || $secret === 'PASTE_YOUR_SHARED_SECRET_HERE') {
            wp_die(
                __('SSO shared secret is not configured on this website. Please set SCP_CLIENT_SSO_SECRET in wp-content/mu-plugins/secure-cloud-portal-client-sso.php.', 'scp-sso'),
                __('SSO Configuration Error', 'scp-sso'),
                array('response' => 403)
            );
        }

        $email    = sanitize_email(rawurldecode($_GET['email']));
        $expires  = intval($_GET['expires']);
        $site_id  = intval($_GET['site_id'] ?? 0);
        $nonce    = sanitize_text_field($_GET['nonce']);
        $provided = sanitize_text_field($_GET['sig']);

        // 1. Check token expiration (strict short-lived window, max 120s in the future)
        $now = time();
        if ($now > $expires || ($expires - $now) > 120) {
            wp_die(
                __('Login link has expired. Please launch a fresh login session from Secure Cloud Portal.', 'scp-sso'),
                __('Token Expired', 'scp-sso'),
                array('response' => 403)
            );
        }

        // 2. Verify cryptographic HMAC-SHA256 signature (includes unique one-time nonce)
        $payload  = $site_id . '|' . $email . '|' . $expires . '|' . $nonce;
        $expected = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $provided)) {
            wp_die(
                __('Invalid security signature for SSO login.', 'scp-sso'),
                __('Access Denied', 'scp-sso'),
                array('response' => 403)
            );
        }

        // 3. Enforce single-use token (prevent replay attacks within expiration window)
        $replay_key = 'scp_sso_used_' . md5($provided);
        if (get_transient($replay_key)) {
            wp_die(
                __('This one-time login link has already been used. Please launch a new session from Secure Cloud Portal.', 'scp-sso'),
                __('Token Already Used', 'scp-sso'),
                array('response' => 403)
            );
        }
        set_transient($replay_key, 1, 300);

        // 4. Match exact user by email address (never blindly fall back to an arbitrary administrator)
        $user = get_user_by('email', $email);
        if (!$user) {
            wp_die(
                __('No matching WordPress user account found for your portal email address on this site.', 'scp-sso'),
                __('Login Error', 'scp-sso'),
                array('response' => 403)
            );
        }

        // 5. Log the user into WordPress session securely
        wp_clear_auth_cookie();
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, is_ssl());
        do_action('wp_login', $user->user_login, $user);

        // Redirect directly to WP-Admin dashboard
        wp_safe_redirect(admin_url());
        exit;
    }
});
