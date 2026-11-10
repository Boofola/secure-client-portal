<?php
if (!defined('SCP_APP_INIT')) {
    exit;
}
?>
<section class="scp-page-banner">
    <div class="scp-container">
        <span class="scp-eyebrow" style="color:#38bdf8;">24/7 Client Assistance</span>
        <h1>Contact Support &amp; Sales</h1>
        <p>Have a question about your hosting package, domain transfer, or custom quote? Send us a message below.</p>
    </div>
</section>

<section class="scp-section" style="background:#f8fafc;">
    <div class="scp-container">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:32px;align-items:flex-start;">
            <!-- Contact Form -->
            <div class="scp-card" style="margin-bottom:0;">
                <div class="scp-card-header">
                    <div>
                        <h3 class="scp-card-title">Send Us a Message</h3>
                        <p class="scp-card-subtitle">Our engineering and billing team responds promptly to all inquiries.</p>
                    </div>
                </div>
                <div class="scp-card-body">
                    <form id="scp-contact-form">
                        <div class="scp-form-grid">
                            <div class="scp-form-group">
                                <label>Your Name *</label>
                                <input type="text" name="name" class="scp-input" value="<?php echo SCP_Security::e($currentUser['display_name'] ?? ''); ?>" placeholder="Jane Doe" required />
                            </div>
                            <div class="scp-form-group">
                                <label>Email Address *</label>
                                <input type="email" name="email" class="scp-input" value="<?php echo SCP_Security::e($currentUser['email'] ?? ''); ?>" placeholder="jane@company.com" required />
                            </div>
                            <div class="scp-form-group">
                                <label>Phone Number (Optional)</label>
                                <input type="tel" name="phone" class="scp-input" value="<?php echo SCP_Security::e($currentUser['phone'] ?? ''); ?>" placeholder="+1 (555) 000-0000" />
                            </div>
                            <div class="scp-form-group">
                                <label>Department</label>
                                <select name="department" class="scp-select">
                                    <option value="Technical Support">Technical Support &amp; WordPress</option>
                                    <option value="Billing & Invoices">Billing, Subscriptions &amp; Quotes</option>
                                    <option value="Domains & DNS">Domain Registration &amp; DNS</option>
                                    <option value="Sales Inquiry">New Project / Sales Inquiry</option>
                                </select>
                            </div>
                            <div class="scp-form-group full-width">
                                <label>Subject *</label>
                                <input type="text" name="subject" class="scp-input" placeholder="How can we help you?" required />
                            </div>
                            <div class="scp-form-group full-width">
                                <label>Message *</label>
                                <textarea name="message" class="scp-textarea" rows="5" placeholder="Please include any relevant domain names or website URLs..." required></textarea>
                            </div>
                        </div>

                        <?php if (!empty($recaptchaSiteKey)): ?>
                            <input type="hidden" name="g-recaptcha-response" class="scp-recaptcha-token" value="" />
                            <div style="margin-top:16px;font-size:12px;color:#64748b;display:flex;align-items:center;gap:6px;">
                                <span style="color:#10b981;">&#128274;</span> Protected by Google reCAPTCHA Enterprise
                            </div>
                        <?php endif; ?>

                        <div id="scp-contact-feedback" style="display:none;padding:12px 14px;border-radius:8px;margin-top:16px;font-size:14px;"></div>

                        <div style="margin-top:20px;">
                            <button type="submit" class="scp-btn scp-btn-primary">Send Message &rarr;</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Direct Contact Info Cards -->
            <div style="display:flex;flex-direction:column;gap:20px;">
                <div class="scp-card" style="margin-bottom:0;">
                    <div class="scp-card-body">
                        <h4 style="margin:0 0 8px 0;font-size:16px;color:#0f172a;">Direct Support Channels</h4>
                        <p style="margin:0 0 16px 0;font-size:14px;color:#64748b;">Existing clients can also manage billing, domains, and WordPress logins 24/7 inside the Client Portal.</p>
                        <div style="font-size:14px;color:#334155;display:flex;flex-direction:column;gap:10px;">
                            <div><strong>Email:</strong> <a href="mailto:<?php echo SCP_Security::e($supportEmail); ?>" style="color:#0284c7;"><?php echo SCP_Security::e($supportEmail); ?></a></div>
                            <div><strong>Phone:</strong> <?php echo SCP_Security::e($supportPhone); ?></div>
                            <div><strong>Portal Hours:</strong> 24/7/365 Automated Self-Service</div>
                        </div>
                    </div>
                </div>

                <div class="scp-card" style="margin-bottom:0;background:#0f172a;color:#ffffff;border-color:#1e293b;">
                    <div class="scp-card-body">
                        <h4 style="margin:0 0 8px 0;font-size:16px;color:#ffffff;">Already a Client?</h4>
                        <p style="margin:0 0 16px 0;font-size:14px;color:#94a3b8;">Sign in to your account to pay open invoices, accept quotes, update DNS nameservers, or launch one-click WordPress admin login.</p>
                        <a href="index.php?page=portal" class="scp-btn scp-btn-primary" style="width:100%;justify-content:center;">Go to Client Portal &rarr;</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
