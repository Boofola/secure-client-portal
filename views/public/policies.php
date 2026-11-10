<?php
if (!defined('SCP_APP_INIT')) {
    exit;
}
?>
<section class="scp-page-banner">
    <div class="scp-container">
        <span class="scp-eyebrow" style="color:#38bdf8;">Legal &amp; Compliance</span>
        <h1>
            <?php
            if ($policyType === 'privacy') echo 'Privacy Policy';
            elseif ($policyType === 'terms') echo 'Terms of Service';
            else echo 'Billing &amp; Refund Policy';
            ?>
        </h1>
        <p>Last Updated: <?php echo date('F j, Y'); ?> &bull; <?php echo SCP_Security::e($companyName); ?></p>
    </div>
</section>

<section class="scp-section" style="background:#ffffff;">
    <div class="scp-container" style="max-width:860px;">
        <div style="display:flex;gap:12px;margin-bottom:28px;flex-wrap:wrap;">
            <a href="index.php?page=privacy" class="scp-btn <?php echo $policyType === 'privacy' ? 'scp-btn-primary' : 'scp-btn-secondary'; ?> scp-btn-sm">Privacy Policy</a>
            <a href="index.php?page=terms" class="scp-btn <?php echo $policyType === 'terms' ? 'scp-btn-primary' : 'scp-btn-secondary'; ?> scp-btn-sm">Terms of Service</a>
            <a href="index.php?page=refund-policy" class="scp-btn <?php echo $policyType === 'refund-policy' ? 'scp-btn-primary' : 'scp-btn-secondary'; ?> scp-btn-sm">Billing &amp; Refund Policy</a>
        </div>

        <div class="scp-card">
            <div class="scp-card-body scp-policy-content" style="line-height:1.75;color:#334155;font-size:15px;">
                <?php if ($policyType === 'privacy'): ?>
                    <h3>1. Information We Collect</h3>
                    <p>When you register for an account or purchase cloud hosting and domain services at <strong><?php echo SCP_Security::e($companyName); ?></strong>, we collect your name, email address, billing address, phone number, and company details required for ICANN domain registration and Stripe invoicing.</p>

                    <h3>2. How Payment Information is Handled</h3>
                    <p>We do not store raw credit card numbers on our servers. All payment methods, subscriptions, invoices, and quotes are processed and stored directly by <strong>Stripe</strong> in compliance with PCI-DSS Level 1 security standards.</p>

                    <h3>3. Third-Party Service Providers</h3>
                    <p>We share only the minimum necessary registration data with accredited domain registrars (<strong>Namecheap</strong> and <strong>OpenSRS / Tucows</strong>) to provision and manage your domain names, and with <strong>Google reCAPTCHA Enterprise</strong> to protect our portal against automated abuse.</p>

                    <h3>4. Security &amp; Access Logs</h3>
                    <p>To protect your account and managed WordPress websites, we log authentication events, one-click SSO token launches, and DNS changes along with your IP address.</p>

                    <h3>5. Contacting Us</h3>
                    <p>For any privacy requests or data inquiries, please contact us at <a href="mailto:<?php echo SCP_Security::e($supportEmail); ?>"><?php echo SCP_Security::e($supportEmail); ?></a>.</p>

                <?php elseif ($policyType === 'terms'): ?>
                    <h3>1. Acceptance of Terms</h3>
                    <p>By accessing or using <strong><?php echo SCP_Security::e($companyName); ?></strong>, you agree to be bound by these Terms of Service and all applicable ICANN registrar policies for domain names registered through our portal.</p>

                    <h3>2. Account Security &amp; One-Click WordPress SSO</h3>
                    <p>You are responsible for maintaining the confidentiality of your portal credentials. When Administrative Auto-Login (SSO) is enabled for your managed WordPress websites, single-use cryptographic tokens are issued exclusively to your authenticated portal session.</p>

                    <h3>3. Acceptable Use</h3>
                    <p>Hosted websites and registered domains may not be used for unlawful activities, malware distribution, phishing, or unsolicited bulk email (spam). We reserve the right to suspend services that violate network security or acceptable use standards.</p>

                    <h3>4. Domain Name Registration</h3>
                    <p>Domain registrations and renewals are provisioned via Namecheap and OpenSRS. You agree to maintain accurate WHOIS contact details in your Account Profile.</p>

                <?php else: ?>
                    <h3>1. Subscription Billing &amp; Invoices</h3>
                    <p>All recurring hosting subscriptions and one-time service invoices are billed through <strong>Stripe</strong>. You may view current and past invoices, download PDF receipts, update payment cards, or schedule subscription cancellations at the end of the current billing period directly inside your Client Portal.</p>

                    <h3>2. Domain Name Registrations &amp; Renewals</h3>
                    <p>Because domain name registrations and renewals are immediately committed to the central registry (via Namecheap or OpenSRS) upon payment, domain registration and renewal fees are non-refundable once provisioned.</p>

                    <h3>3. Cloud Hosting Subscriptions</h3>
                    <p>You may cancel recurring cloud hosting subscriptions at any time from the <strong>Subscriptions &amp; Billing</strong> tab in your portal. Cancellations take effect at the conclusion of your active billing cycle.</p>

                    <h3>4. Billing Support</h3>
                    <p>If you have questions regarding an invoice or quote, please reach out to <a href="mailto:<?php echo SCP_Security::e($supportEmail); ?>"><?php echo SCP_Security::e($supportEmail); ?></a>.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
