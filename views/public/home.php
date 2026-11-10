<?php
if (!defined('SCP_APP_INIT')) {
    exit;
}
?>
<!-- Hero Section with Live Domain Search -->
<section class="scp-hero">
    <div class="scp-container scp-hero-inner">
        <div class="scp-hero-badge">Enterprise Cloud, VPS, Ad Servers, Mass Email &amp; WordPress</div>
        <h1 class="scp-hero-title">
            Manage Your Cloud &amp; VPS Hosting, Domains, Platforms &amp; Billing in One Place
        </h1>
        <p class="scp-hero-subtitle">
            Power any application on our high-speed Cloud &amp; VPS infrastructure — including Managed WordPress with 1-Click SSO, Dedicated Ad Servers, Mass Emailing Platforms, and instant domain registration via Namecheap &amp; OpenSRS.
        </p>

        <!-- Live Domain Search Box on Home Page -->
        <div id="domains-section" class="scp-hero-domain-box">
            <form id="scp-public-domain-form" class="scp-domain-search-bar" style="margin-bottom:0;">
                <input type="text" id="scp-public-domain-input" class="scp-input" placeholder="Search for your new domain name (e.g., mycompany.com)..." required />
                <button type="submit" class="scp-btn scp-btn-primary" style="padding:14px 28px;font-size:15px;white-space:nowrap;">
                    Search Domain Availability
                </button>
            </form>
            <div class="scp-tld-pills">
                <span><strong>.com</strong> $15.58/yr</span>
                <span><strong>.net</strong> $17.98/yr</span>
                <span><strong>.org</strong> $16.78/yr</span>
                <span><strong>.cloud</strong> $22.79/yr</span>
                <span><strong>.io</strong> $47.99/yr</span>
                <span><strong>.co</strong> $29.99/yr</span>
            </div>
            <div id="scp-public-domain-results" style="margin-top:16px;"></div>
        </div>

        <div class="scp-hero-actions">
            <?php if ($auth->isLoggedIn()): ?>
                <a href="index.php?page=portal" class="scp-btn scp-btn-primary scp-btn-lg">Open Your Client Portal &rarr;</a>
            <?php else: ?>
                <a href="index.php?page=register" class="scp-btn scp-btn-primary scp-btn-lg">Create Your Client Account &rarr;</a>
                <a href="index.php?page=login" class="scp-btn scp-btn-outline-light scp-btn-lg">Existing Client Sign In</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Core Platform Capabilities -->
<section class="scp-section" style="background:#ffffff;">
    <div class="scp-container">
        <div class="scp-section-header">
            <span class="scp-eyebrow">Unified Cloud Ecosystem</span>
            <h2>Built for Every Platform You Run</h2>
            <p>From Managed WordPress with 1-Click SSO to our proprietary Ad Server, Mass Emailing Service, and custom Cloud/VPS workloads.</p>
        </div>

        <div class="scp-features-grid">
            <div class="scp-feature-card">
                <div class="scp-stat-icon blue" style="margin-bottom:16px;">
                    <svg style="width:24px;height:24px;fill:currentColor;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
                </div>
                <h3>WordPress SSO, Ad Servers &amp; Mass Email</h3>
                <p>Manage all your hosted platforms in one dashboard — launch straight into WordPress via 1-Click SSO, or access your Dedicated Ad Server, Mass Emailing Service, and custom Cloud/VPS apps.</p>
            </div>

            <div class="scp-feature-card">
                <div class="scp-stat-icon emerald" style="margin-bottom:16px;">
                    <svg style="width:24px;height:24px;fill:currentColor;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
                </div>
                <h3>Global Domain Registrar</h3>
                <p>Real-time domain search, instant registration, multi-year renewals, and custom DNS nameserver management powered directly by Namecheap and OpenSRS APIs.</p>
            </div>

            <div class="scp-feature-card">
                <div class="scp-stat-icon purple" style="margin-bottom:16px;">
                    <svg style="width:24px;height:24px;fill:currentColor;" viewBox="0 0 24 24"><path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/></svg>
                </div>
                <h3>Flexible Terms &amp; Stripe Billing</h3>
                <p>Choose flexible monthly, multi-month, or one-time plans with instant Stripe checkout. Existing Stripe customers automatically sync their subscriptions, invoices, and quotes.</p>
            </div>
        </div>
    </div>
</section>

<!-- Live Cloud Hosting, Platform & Service Plans (Categorized & Multi-Term Enabled) -->
<?php
$catSections = $categorizedCatalog['sections'] ?? array();
?>
<section id="pricing-section" class="scp-section" style="background:#f8fafc;border-top:1px solid #e2e8f0;">
    <div class="scp-container">
        <div class="scp-section-header">
            <span class="scp-eyebrow">Transparent Pricing</span>
            <h2>Cloud Hosting, Platforms &amp; Managed Service Plans</h2>
            <p>Browse our service categories below, compare features and billing terms, and deploy or upgrade with instant Stripe checkout.</p>
        </div>

        <?php if (!empty($catSections)): ?>
            <!-- Quick-Jump Category Navigation Bar at Top of Pricing/Products Section -->
            <div class="scp-category-quicklinks" aria-label="Product Category Quick Links">
                <span class="scp-quicklinks-label">Quick Jump to Category:</span>
                <div class="scp-quicklinks-pills">
                    <?php foreach ($catSections as $secIdx => $sec): ?>
                        <a href="#pricing-cat-<?php echo SCP_Security::e($sec['slug']); ?>" class="scp-category-jump-pill <?php echo $secIdx === 0 ? 'active' : ''; ?>">
                            <span><?php echo SCP_Security::e($sec['name']); ?></span>
                            <span class="scp-jump-count"><?php echo count($sec['products']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Categorized Product Sections with Clear Separations, Headings, Descriptions & Term Switchers -->
            <div class="scp-categorized-catalog-wrapper">
                <?php foreach ($catSections as $secIdx => $sec):
                    $availTerms = $sec['available_terms'] ?? array();
                    $defCatTerm = $sec['default_term_key'] ?? '';
                ?>
                    <div id="pricing-cat-<?php echo SCP_Security::e($sec['slug']); ?>" class="scp-category-section-block">
                        <div class="scp-category-section-header">
                            <div class="scp-category-header-text">
                                <div class="scp-category-title-row">
                                    <h3 class="scp-category-heading"><?php echo SCP_Security::e($sec['name']); ?></h3>
                                    <span class="scp-category-count-badge">
                                        <?php echo count($sec['products']); ?> <?php echo count($sec['products']) === 1 ? 'Product' : 'Products'; ?>
                                    </span>
                                </div>
                                <?php if (!empty($sec['description'])): ?>
                                    <p class="scp-category-description"><?php echo SCP_Security::e($sec['description']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                                <?php if (count($availTerms) > 1): ?>
                                    <!-- Category-Level Master Term Switcher Pill Bar -->
                                    <div class="scp-term-switcher-bar scp-category-term-bar" role="tablist" aria-label="Select Billing Term">
                                        <?php foreach ($availTerms as $tIdx => $tOpt):
                                            $isTermActive = ($defCatTerm !== '' ? ($tOpt['term_key'] === $defCatTerm) : ($tIdx === 0));
                                        ?>
                                            <button type="button"
                                                    class="scp-term-pill scp-category-term-btn <?php echo $isTermActive ? 'active' : ''; ?>"
                                                    data-term-key="<?php echo SCP_Security::e($tOpt['term_key']); ?>">
                                                <?php echo SCP_Security::e($tOpt['term_name']); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (count($catSections) > 1): ?>
                                    <a href="#pricing-section" class="scp-back-to-top-link">&uarr; All Categories</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="scp-pricing-grid">
                            <?php foreach ($sec['products'] as $item):
                                $isFeatured   = !empty($item['is_featured']);
                                $superTitle   = trim((string)($item['super_title'] ?? ''));
                                $hasTerms     = !empty($item['has_terms']) && !empty($item['terms']);
                                $termsList    = $item['terms'] ?? array();
                                $priceAmt     = number_format((float)($item['price_amount'] ?? (($item['unit_amount'] ?? 0) / 100)), 2);
                                $priceUnit    = isset($item['price_unit']) ? (string)$item['price_unit'] : ($item['is_recurring'] ? '/' . $item['interval'] : ' one-time');
                                $termLenLbl   = trim((string)($item['term_length_label'] ?? ''));
                                $renewsLbl    = trim((string)($item['renews_at_label'] ?? ''));
                                $saveBadge    = trim((string)($item['save_badge_text'] ?? ''));
                                $promoCallout = trim((string)($item['promo_callout'] ?? ''));
                                $ctaLabel     = !empty($item['cta_label']) ? $item['cta_label'] : 'Choose Plan';
                                $stripeHook   = trim((string)($item['stripe_hook'] ?? ($item['price_id'] ?? '')));
                                $billingMode  = $item['billing_mode'] ?? (!empty($item['is_recurring']) ? 'recurring' : 'one_time');
                                $useHeadings  = !empty($item['use_feature_headings']);
                                $featuresList = $item['features'] ?? array();
                            ?>
                                <div class="scp-pricing-card scp-v3-product-card <?php echo $isFeatured ? 'scp-pricing-card-featured' : ''; ?> <?php echo $superTitle !== '' ? 'has-super-title' : ''; ?>"
                                     data-has-terms="<?php echo $hasTerms ? '1' : '0'; ?>"
                                     data-terms="<?php echo SCP_Security::e(json_encode($termsList)); ?>">

                                    <?php if ($superTitle !== ''): ?>
                                        <div class="scp-card-super-title <?php echo $isFeatured ? 'featured' : ''; ?>">
                                            <?php echo SCP_Security::e($superTitle); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="scp-v3-card-top">
                                        <h4 class="scp-pricing-title"><?php echo SCP_Security::e($item['name']); ?></h4>
                                        <?php if (!empty($item['description'])): ?>
                                            <p class="scp-pricing-desc"><?php echo SCP_Security::e($item['description']); ?></p>
                                        <?php endif; ?>

                                        <?php if ($hasTerms && count($termsList) > 1):
                                            $defIdx = isset($item['default_term_index']) ? (int)$item['default_term_index'] : 0;
                                        ?>
                                            <!-- Card-Level Term Pill Switcher -->
                                            <div class="scp-card-term-pills">
                                                <?php foreach ($termsList as $tIdx => $tRow):
                                                    $tActive   = ((int)$tIdx === $defIdx);
                                                    $tBtnLabel = !empty($tRow['display_label']) ? $tRow['display_label'] : $tRow['term_name'];
                                                ?>
                                                    <button type="button"
                                                            class="scp-card-term-btn <?php echo $tActive ? 'active' : ''; ?>"
                                                            data-term-key="<?php echo SCP_Security::e($tRow['term_key']); ?>"
                                                            data-term-index="<?php echo (int)$tIdx; ?>">
                                                        <?php echo SCP_Security::e($tBtnLabel); ?>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Price & Save Badge Row -->
                                        <div class="scp-v3-price-row">
                                            <div class="scp-pricing-price">
                                                $<span class="scp-js-price-amount"><?php echo SCP_Security::e($priceAmt); ?></span><span class="scp-js-price-unit"><?php echo SCP_Security::e($priceUnit); ?></span>
                                            </div>
                                            <span class="scp-save-badge scp-js-save-badge" style="<?php echo $saveBadge !== '' ? '' : 'display:none;'; ?>">
                                                <?php echo SCP_Security::e($saveBadge); ?>
                                            </span>
                                        </div>

                                        <!-- Term Length & Renews At Meta -->
                                        <div class="scp-v3-term-meta">
                                            <div class="scp-term-length-line scp-js-term-length" style="<?php echo $termLenLbl !== '' ? '' : 'display:none;'; ?>">
                                                <?php echo SCP_Security::e($termLenLbl); ?>
                                            </div>
                                            <div class="scp-renews-at-line scp-js-renews-at" style="<?php echo $renewsLbl !== '' ? '' : 'display:none;'; ?>">
                                                <?php echo SCP_Security::e($renewsLbl); ?>
                                            </div>
                                        </div>

                                        <?php if ($promoCallout !== ''): ?>
                                            <div class="scp-promo-callout-pill">
                                                <?php echo SCP_Security::e($promoCallout); ?>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Action Button -->
                                        <div class="scp-v3-cta-wrap">
                                            <?php
                                            $isDirectUrl = (bool)preg_match('#^https?://#i', $stripeHook);
                                            if ($auth->isLoggedIn() || $isDirectUrl):
                                            ?>
                                                <button type="button"
                                                        class="scp-btn <?php echo $isFeatured ? 'scp-btn-primary' : 'scp-btn-outline-blue'; ?> scp-btn-subscribe scp-js-cta-btn"
                                                        style="width:100%;justify-content:center;"
                                                        data-price-id="<?php echo SCP_Security::e($stripeHook); ?>"
                                                        data-mode="<?php echo $billingMode === 'one_time' ? 'payment' : 'subscription'; ?>">
                                                    <?php echo SCP_Security::e($ctaLabel); ?>
                                                </button>
                                            <?php else: ?>
                                                <a href="index.php?page=register"
                                                   class="scp-btn <?php echo $isFeatured ? 'scp-btn-primary' : 'scp-btn-outline-blue'; ?> scp-js-cta-link"
                                                   style="width:100%;justify-content:center;">
                                                    <?php echo SCP_Security::e($ctaLabel); ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if (!empty($featuresList)): ?>
                                        <div class="scp-v3-features-section">
                                            <?php
                                            $lastHeading = null;
                                            foreach ($featuresList as $feat):
                                                $fHeading = trim((string)($feat['heading'] ?? ''));
                                                $fText    = trim((string)($feat['text'] ?? ''));
                                                if ($fText === '') {
                                                    continue;
                                                }
                                                $fBold     = !empty($feat['is_bold']);
                                                $fItalic   = !empty($feat['is_italic']);
                                                $fNew      = !empty($feat['is_new']);
                                                $fHint     = trim((string)($feat['hint'] ?? ''));
                                                $fHintIcon = strtolower(trim((string)($feat['hint_icon'] ?? 'black-info')));
                                                $fIcon     = strtolower(trim((string)($feat['icon'] ?? 'check')));
                                                $fTextClasses = array('scp-feat-text');
                                                if ($fBold) {
                                                    $fTextClasses[] = 'is-bold';
                                                }
                                                if ($fItalic) {
                                                    $fTextClasses[] = 'is-italic';
                                                }
                                                if ($useHeadings && $fHeading !== '' && $fHeading !== $lastHeading):
                                                    $lastHeading = $fHeading;
                                            ?>
                                                <div class="scp-feature-group-heading"><?php echo SCP_Security::e($fHeading); ?></div>
                                            <?php endif; ?>
                                                <div class="scp-feature-item<?php echo $fHint !== '' ? ' has-hint' : ''; ?>"<?php echo $fHint !== '' ? ' tabindex="0"' : ''; ?>>
                                                    <?php if ($fIcon === 'cross'): ?>
                                                        <span class="scp-feat-icon scp-feat-cross" aria-hidden="true">&#10005;</span>
                                                    <?php elseif ($fIcon === 'bullet'): ?>
                                                        <span class="scp-feat-icon scp-feat-bullet" aria-hidden="true">&bull;</span>
                                                    <?php else: ?>
                                                        <span class="scp-feat-icon scp-feat-check" aria-hidden="true">&#10003;</span>
                                                    <?php endif; ?>
                                                    <span class="<?php echo implode(' ', $fTextClasses); ?>">
                                                        <?php echo SCP_Security::e($fText); ?><?php if ($fNew): ?><span class="scp-feat-new-badge">New</span><?php endif; ?><?php if ($fHint !== ''): ?><span class="scp-feat-hint-icon" aria-hidden="true"><?php echo SCP_Security::renderFeatureHintIcon($fHintIcon); ?></span><?php endif; ?>
                                                    </span>
                                                    <?php if ($fHint !== ''): ?>
                                                        <span class="scp-feat-hint-tooltip" role="tooltip"><?php echo SCP_Security::e($fHint); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php
                        $secNotes = $sec['bottom_notes'] ?? array();
                        if (empty($secNotes)) {
                            $fText  = trim((string)($sec['bottom_note_text'] ?? ''));
                            $fTitle = trim((string)($sec['bottom_note_title'] ?? ''));
                            $fIcon  = trim((string)($sec['bottom_note_icon'] ?? 'green-exclamation'));
                            if ($fText !== '' || $fTitle !== '') {
                                $secNotes[] = array('icon' => $fIcon, 'title' => $fTitle, 'text' => $fText);
                            }
                        }
                        if (!empty($secNotes)):
                        ?>
                            <div class="scp-category-footer-notes-stack">
                                <?php foreach ($secNotes as $sNote):
                                    $noteText  = trim((string)($sNote['text'] ?? ''));
                                    $noteTitle = trim((string)($sNote['title'] ?? ''));
                                    $noteIcon  = trim((string)($sNote['icon'] ?? 'green-exclamation'));
                                    if ($noteText === '' && $noteTitle === '') {
                                        continue;
                                    }
                                    $iconSvg = SCP_Security::renderCategoryNoteIcon($noteIcon);
                                ?>
                                    <div class="scp-category-footer-note">
                                        <?php if (!empty($iconSvg)): ?>
                                            <span class="scp-cat-note-icon-wrap"><?php echo $iconSvg; ?></span>
                                        <?php endif; ?>
                                        <div class="scp-cat-note-content">
                                            <?php if ($noteTitle !== ''): ?>
                                                <strong><?php echo SCP_Security::e($noteTitle); ?></strong>
                                            <?php endif; ?>
                                            <span><?php echo SCP_Security::e($noteText); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="scp-pricing-grid">
                <div class="scp-pricing-card">
                    <div class="scp-pricing-title">Managed Cloud &amp; WordPress Starter</div>
                    <div class="scp-pricing-price">$29.00<span>/month</span></div>
                    <p class="scp-pricing-desc">Ideal for business websites requiring high-speed NVMe cloud hosting, daily backups, free SSL, and one-click portal SSO.</p>
                    <a href="index.php?page=register" class="scp-btn scp-btn-primary" style="width:100%;justify-content:center;">Choose Plan &rarr;</a>
                </div>
                <div class="scp-pricing-card scp-pricing-card-featured">
                    <div class="scp-pricing-title">Business Cloud &amp; Platform Pro</div>
                    <div class="scp-pricing-price">$79.00<span>/month</span></div>
                    <p class="scp-pricing-desc">Dedicated cloud resources for WordPress, Ad Servers, Mass Emailing, or custom VPS workloads with priority engineering support.</p>
                    <a href="index.php?page=register" class="scp-btn scp-btn-primary" style="width:100%;justify-content:center;">Choose Plan &rarr;</a>
                </div>
                <div class="scp-pricing-card">
                    <div class="scp-pricing-title">Enterprise Dedicated Cluster</div>
                    <div class="scp-pricing-price">$199.00<span>/month</span></div>
                    <p class="scp-pricing-desc">Multi-server high-availability cloud infrastructure with custom SLA, automated failover, and white-glove management.</p>
                    <a href="index.php?page=contact" class="scp-btn scp-btn-secondary" style="width:100%;justify-content:center;">Contact Sales &rarr;</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
