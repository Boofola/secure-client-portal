<?php
if (!defined('SCP_APP_INIT')) {
    exit;
}
?>
<section class="scp-page-banner">
    <div class="scp-container">
        <span class="scp-eyebrow" style="color:#38bdf8;">About <?php echo SCP_Security::e($companyName); ?></span>
        <h1>Enterprise Cloud, VPS &amp; Platform Management Built for Reliability</h1>
        <p>We combine high-performance Cloud &amp; VPS hosting, WordPress 1-Click SSO, Dedicated Ad Servers, Mass Emailing platforms, direct registrar domain management, and transparent Stripe billing under one roof.</p>
    </div>
</section>

<section class="scp-section" style="background:#ffffff;">
    <div class="scp-container">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:40px;align-items:center;">
            <div>
                <h2 style="font-size:28px;color:#0f172a;margin:0 0 16px 0;">Our Mission</h2>
                <p style="color:#475569;font-size:15px;line-height:1.7;margin:0 0 16px 0;">
                    Managing cloud servers, websites, ad servers, mass email systems, domains, SSL certificates, and recurring hosting invoices across half a dozen different vendor logins wastes valuable time. <strong><?php echo SCP_Security::e($companyName); ?></strong> was created to unify every piece of your digital infrastructure into a single, hardened client portal.
                </p>
                <p style="color:#475569;font-size:15px;line-height:1.7;margin:0;">
                    Whether you are registering a new domain name, launching your Dedicated Ad Server or Mass Emailing platform, deploying any custom application on our Cloud &amp; VPS infrastructure, or logging straight into your WordPress administration dashboard with one click, our platform puts you in complete control.
                </p>
            </div>

            <div class="scp-stats-grid" style="margin-bottom:0;">
                <div class="scp-stat-card">
                    <div>
                        <div class="scp-stat-label">Network Uptime SLA</div>
                        <div class="scp-stat-value" style="color:#0284c7;">99.99%</div>
                    </div>
                </div>
                <div class="scp-stat-card">
                    <div>
                        <div class="scp-stat-label">Payment Security</div>
                        <div class="scp-stat-value" style="color:#10b981;">PCI-DSS L1</div>
                    </div>
                </div>
                <div class="scp-stat-card">
                    <div>
                        <div class="scp-stat-label">Domain Registrars</div>
                        <div class="scp-stat-value" style="font-size:20px;">Namecheap &amp; OpenSRS</div>
                    </div>
                </div>
                <div class="scp-stat-card">
                    <div>
                        <div class="scp-stat-label">WordPress Access</div>
                        <div class="scp-stat-value" style="font-size:20px;">HMAC-SHA256 SSO</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="scp-section" style="background:#f8fafc;border-top:1px solid #e2e8f0;">
    <div class="scp-container">
        <div class="scp-section-header">
            <h2>Security-First Architecture</h2>
            <p>Every layer of Secure Cloud Portal is engineered to protect your domains, websites, and billing data.</p>
        </div>
        <div class="scp-features-grid">
            <div class="scp-feature-card">
                <h3>Zero Card Storage</h3>
                <p>All credit card processing, subscription billing, and invoice payments are handled directly by Stripe's PCI-DSS Level 1 infrastructure.</p>
            </div>
            <div class="scp-feature-card">
                <h3>Single-Use Cryptographic SSO</h3>
                <p>When one-click WordPress login is enabled for your site, each click generates a 60-second, single-use HMAC-SHA256 token that cannot be replayed.</p>
            </div>
            <div class="scp-feature-card">
                <h3>Google reCAPTCHA Enterprise</h3>
                <p>All authentication and public endpoints are shielded by Google Cloud reCAPTCHA Enterprise risk analysis and IP rate limiting.</p>
            </div>
        </div>
    </div>
</section>
