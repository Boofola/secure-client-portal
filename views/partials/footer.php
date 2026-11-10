<?php
if (!defined('SCP_APP_INIT')) {
    exit;
}
?>
    </main>

    <!-- Global Site Footer -->
    <footer class="scp-site-footer">
        <div class="scp-container">
            <div class="scp-footer-grid">
                <div class="scp-footer-col" style="flex:1.6;">
                    <?php $footerLogo = !empty($siteLogoUrl) ? $siteLogoUrl : (file_exists(SCP_ROOT_DIR . '/assets/logo.svg') ? 'assets/logo.svg' : 'assets/secure-cloud-portal-logo.svg'); ?>
                    <img src="<?php echo SCP_Security::e($footerLogo); ?>" alt="<?php echo SCP_Security::e($companyName); ?>" style="height:38px;margin-bottom:16px;" />
                    <p style="color:#94a3b8;font-size:14px;line-height:1.6;max-width:340px;margin:0 0 16px 0;">
                        Unified cloud hosting, domain registration, automated Stripe billing, and one-click managed WordPress administration in one secure portal.
                    </p>
                    <div style="font-size:13px;color:#cbd5e1;">
                        <strong>Support:</strong> <a href="mailto:<?php echo SCP_Security::e($supportEmail); ?>" style="color:#38bdf8;text-decoration:none;"><?php echo SCP_Security::e($supportEmail); ?></a>
                    </div>
                </div>

                <div class="scp-footer-col">
                    <h4>Platform</h4>
                    <ul>
                        <li><a href="index.php?page=home">Home</a></li>
                        <li><a href="index.php?page=home#domains-section">Domain Registration</a></li>
                        <li><a href="index.php?page=home#pricing-section">Products</a></li>
                        <li><a href="index.php?page=portal">Client Portal</a></li>
                    </ul>
                </div>

                <div class="scp-footer-col">
                    <h4>Company</h4>
                    <ul>
                        <li><a href="index.php?page=about">About Us</a></li>
                        <li><a href="index.php?page=contact">Contact Support</a></li>
                        <li><a href="index.php?page=login">Client Sign In</a></li>
                        <li><a href="index.php?page=register">Create Account</a></li>
                    </ul>
                </div>

                <div class="scp-footer-col">
                    <h4>Legal &amp; Policies</h4>
                    <ul>
                        <li><a href="index.php?page=privacy">Privacy Policy</a></li>
                        <li><a href="index.php?page=terms">Terms of Service</a></li>
                        <li><a href="index.php?page=refund-policy">Billing &amp; Refund Policy</a></li>
                    </ul>
                </div>
            </div>

            <div class="scp-footer-bottom">
                <div>&copy; <?php echo date('Y'); ?> <?php echo SCP_Security::e($companyName); ?>. All rights reserved. Version <?php echo SCP_Security::e(SCP_VERSION); ?></div>
                <div style="display:flex;gap:16px;align-items:center;">
                    <span>Secured with 256-Bit SSL, Stripe &amp; Google reCAPTCHA Enterprise</span>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script>
        window.scpConfig = {
            apiUrl: 'api.php',
            csrfToken: <?php echo json_encode($csrfToken); ?>,
            isLoggedIn: <?php echo json_encode($auth->isLoggedIn()); ?>,
            loginUrl: 'index.php?page=login',
            recaptchaSiteKey: <?php echo json_encode(!empty($recaptchaDomainReady) ? ($recaptchaSiteKey ?? '') : ''); ?>
        };
    </script>
    <script src="assets/js/app.js?v=<?php echo SCP_Security::e(SCP_VERSION); ?>"></script>
</body>
</html>
