<?php
if (!defined('SCP_APP_INIT')) {
    exit;
}
$showRegister = (isset($initialAuthMode) && $initialAuthMode === 'register');
$authLogo = !empty($siteLogoUrl) ? $siteLogoUrl : (file_exists(SCP_ROOT_DIR . '/assets/logo.svg') ? 'assets/logo.svg' : 'assets/secure-cloud-portal-logo.svg');
?>
<div class="scp-auth-wrapper" style="padding:48px 20px;">
    <!-- Sign In Card -->
    <div id="scp-login-box" class="scp-auth-card" style="<?php echo $showRegister ? 'display:none;' : ''; ?>">
        <div class="scp-auth-logo">
            <img src="<?php echo SCP_Security::e($authLogo); ?>" alt="<?php echo SCP_Security::e($companyName); ?>" />
            <h2>Client Portal Sign In</h2>
            <p>Manage your websites, domains, subscriptions, and Stripe invoices.</p>
        </div>

        <?php if (!empty($_GET['logged_out'])): ?>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:13px;text-align:center;">
                You have been signed out securely.
            </div>
        <?php endif; ?>

        <form id="scp-login-form">
            <div class="scp-form-group" style="margin-bottom:16px;">
                <label>Email Address</label>
                <input type="email" name="email" class="scp-input" placeholder="you@company.com" required />
            </div>

            <div class="scp-form-group" style="margin-bottom:16px;">
                <label>Password</label>
                <input type="password" name="password" class="scp-input" placeholder="••••••••" required />
            </div>

            <?php if (!empty($recaptchaSiteKey)): ?>
                <input type="hidden" name="g-recaptcha-response" class="scp-recaptcha-token" value="" />
                <div style="margin-bottom:16px;font-size:12px;color:#64748b;text-align:center;display:flex;align-items:center;justify-content:center;gap:6px;">
                    <span style="color:#10b981;">&#128274;</span> Protected by Google reCAPTCHA Enterprise
                </div>
            <?php endif; ?>

            <button type="submit" class="scp-btn scp-btn-primary" style="width:100%;justify-content:center;padding:12px;">
                Sign In to Portal &rarr;
            </button>
        </form>

        <div class="scp-auth-switch">
            Don't have a portal account yet?
            <a href="#register" class="scp-auth-toggle" data-target="scp-register-box">Create Account</a>
        </div>
    </div>

    <!-- Registration Card -->
    <div id="scp-register-box" class="scp-auth-card" style="<?php echo $showRegister ? '' : 'display:none;'; ?>">
        <div class="scp-auth-logo">
            <img src="<?php echo SCP_Security::e($authLogo); ?>" alt="<?php echo SCP_Security::e($companyName); ?>" />
            <h2>Create Your Client Account</h2>
            <p>Already billed via Stripe? Use the same email address and your billing profile, invoices, and quotes will be imported automatically.</p>
        </div>

        <form id="scp-register-form">
            <div class="scp-form-grid" style="margin-bottom:16px;">
                <div class="scp-form-group">
                    <label>First Name</label>
                    <input type="text" name="first_name" class="scp-input" placeholder="Jane" required />
                </div>
                <div class="scp-form-group">
                    <label>Last Name</label>
                    <input type="text" name="last_name" class="scp-input" placeholder="Doe" required />
                </div>
            </div>

            <div class="scp-form-group" style="margin-bottom:16px;">
                <label>Company / Organization (Optional)</label>
                <input type="text" name="company" class="scp-input" placeholder="Acme Cloud Inc." />
            </div>

            <div class="scp-form-group" style="margin-bottom:16px;">
                <label>Email Address</label>
                <input type="email" name="email" class="scp-input" placeholder="jane@company.com" required />
            </div>

            <div class="scp-form-group" style="margin-bottom:20px;">
                <label>Create Password (min. 8 characters)</label>
                <input type="password" name="password" class="scp-input" placeholder="Minimum 8 characters" required />
            </div>

            <?php if (!empty($recaptchaSiteKey)): ?>
                <input type="hidden" name="g-recaptcha-response" class="scp-recaptcha-token" value="" />
                <div style="margin-bottom:16px;font-size:12px;color:#64748b;text-align:center;display:flex;align-items:center;justify-content:center;gap:6px;">
                    <span style="color:#10b981;">&#128274;</span> Protected by Google reCAPTCHA Enterprise
                </div>
            <?php endif; ?>

            <button type="submit" class="scp-btn scp-btn-primary" style="width:100%;justify-content:center;padding:12px;">
                Create Account &amp; Sync Stripe &rarr;
            </button>
        </form>

        <div class="scp-auth-switch">
            Already have an account?
            <a href="#login" class="scp-auth-toggle" data-target="scp-login-box">Sign In</a>
        </div>
    </div>
</div>
