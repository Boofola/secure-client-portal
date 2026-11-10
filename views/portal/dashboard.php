<?php
if (!defined('SCP_APP_INIT')) {
    exit;
}

$displayName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
if (empty($displayName)) {
    $displayName = $currentUser['display_name'] ?? $currentUser['email'];
}

$activeSubCount = is_array($subscriptions) ? count($subscriptions) : 0;
$domainCount    = is_array($domains) ? count($domains) : 0;
$websiteCount   = is_array($websites) ? count($websites) : 0;

$openInvoicesCount = 0;
if (is_array($invoices)) {
    foreach ($invoices as $inv) {
        if (($inv['status'] ?? '') === 'open') {
            $openInvoicesCount++;
        }
    }
}

$openQuotesCount = 0;
if (is_array($quotes)) {
    foreach ($quotes as $qt) {
        if (in_array($qt['status'] ?? '', array('open', 'accepted'), true)) {
            $openQuotesCount++;
        }
    }
}
?>
<div class="scp-portal-shell">
    <!-- Left Sidebar Navigation -->
    <aside class="scp-sidebar">
        <div class="scp-sidebar-brand">
            <span class="scp-badge" style="background:rgba(14,165,233,0.18);color:#38bdf8;border:1px solid rgba(14,165,233,0.35);font-size:11px;letter-spacing:0.04em;padding:4px 10px;">
                CLIENT PORTAL
            </span>
        </div>

        <div class="scp-user-mini-card">
            <div class="scp-user-avatar">
                <?php echo SCP_Security::e(strtoupper(substr($displayName, 0, 1))); ?>
            </div>
            <div class="scp-user-meta">
                <div class="scp-user-name"><?php echo SCP_Security::e($displayName); ?></div>
                <div class="scp-user-email"><?php echo SCP_Security::e($currentUser['email']); ?></div>
            </div>
        </div>

        <nav class="scp-nav">
            <button type="button" class="scp-nav-item active" data-tab="overview">
                <span class="scp-nav-icon">&#9638;</span>
                <span>Overview</span>
            </button>
            <button type="button" class="scp-nav-item" data-tab="billing">
                <span class="scp-nav-icon">&#128179;</span>
                <span>Subscriptions &amp; Billing</span>
                <?php if ($openInvoicesCount > 0 || $openQuotesCount > 0): ?>
                    <span class="scp-badge scp-badge-warning"><?php echo (int)($openInvoicesCount + $openQuotesCount); ?></span>
                <?php endif; ?>
            </button>
            <button type="button" class="scp-nav-item" data-tab="domains">
                <span class="scp-nav-icon">&#127760;</span>
                <span>Domain Manager</span>
            </button>
            <button type="button" class="scp-nav-item" data-tab="websites">
                <span class="scp-nav-icon">&#9889;</span>
                <span>Websites &amp; Hosted Apps</span>
            </button>
            <button type="button" class="scp-nav-item" data-tab="account">
                <span class="scp-nav-icon">&#128100;</span>
                <span>Account &amp; Profile</span>
            </button>
        </nav>

        <div class="scp-sidebar-footer">
            <?php if ($auth->isAdmin()): ?>
                <a href="index.php?page=admin" class="scp-logout-link" style="margin-bottom:8px;background:rgba(14,165,233,0.12);color:#38bdf8;border-color:rgba(14,165,233,0.3);">
                    <span>&#9881;</span> Switch to Admin Center
                </a>
            <?php endif; ?>
            <a href="index.php?page=logout" class="scp-logout-link">
                <span>&#10162;</span> Sign Out
            </a>
        </div>
    </aside>

    <!-- Main Dashboard Content Area -->
    <div class="scp-main">
        <!-- Top Status Bar -->
        <header class="scp-topbar">
            <div>
                <h1 class="scp-page-title">Welcome back, <?php echo SCP_Security::e($currentUser['first_name'] ?: $displayName); ?></h1>
                <p class="scp-page-subtitle">Manage your cloud &amp; VPS hosting subscriptions, domain names, invoices, quotes, WordPress sites, ad servers, and hosted applications.</p>
            </div>
            <div class="scp-topbar-actions">
                <button type="button" class="scp-btn scp-btn-outline scp-sync-stripe-btn" title="Pull latest billing info, invoices, and quotes from Stripe">
                    &#8635; Sync with Stripe
                </button>
                <button type="button" class="scp-btn scp-btn-secondary scp-open-stripe-portal">
                    Manage Payment Methods
                </button>
                <button type="button" class="scp-btn scp-btn-primary scp-switch-tab" data-target="domains">
                    + Register Domain
                </button>
            </div>
        </header>

        <?php if (isset($_GET['checkout']) && $_GET['checkout'] === 'success'): ?>
            <div class="scp-alert scp-alert-success">
                <strong>Payment Confirmed!</strong> Thank you for your order. Your account services and invoices are being updated automatically.
            </div>
        <?php endif; ?>

        <?php if (!empty($_GET['domain_success']) || (isset($_GET['domain_checkout']) && $_GET['domain_checkout'] === 'success')): ?>
            <div class="scp-alert scp-alert-success">
                <strong>Domain Active in Your Dashboard!</strong>
                <?php if (!empty($_GET['domain_success'])): ?>
                    <code><?php echo SCP_Security::e($_GET['domain_success']); ?></code> has been automatically registered/renewed and added to your <strong>Domain Manager</strong> below.
                <?php else: ?>
                    Your domain registration/renewal has been provisioned and added to your <strong>Domain Manager</strong> below.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div id="scp-global-toast" class="scp-toast" style="display:none;"></div>

        <!-- TAB 1: OVERVIEW -->
        <section class="scp-tab-panel active" id="scp-tab-overview">
            <div class="scp-stats-grid">
                <div class="scp-stat-card">
                    <div class="scp-stat-label">Active Subscriptions</div>
                    <div class="scp-stat-value"><?php echo (int)$activeSubCount; ?></div>
                    <div class="scp-stat-footer">
                        <a href="#" class="scp-switch-tab" data-target="billing">Manage Plans &rarr;</a>
                    </div>
                </div>
                <div class="scp-stat-card">
                    <div class="scp-stat-label">Registered Domains</div>
                    <div class="scp-stat-value"><?php echo (int)$domainCount; ?></div>
                    <div class="scp-stat-footer">
                        <a href="#" class="scp-switch-tab" data-target="domains">Manage Domains &rarr;</a>
                    </div>
                </div>
                <div class="scp-stat-card">
                    <div class="scp-stat-label">Websites &amp; Hosted Apps</div>
                    <div class="scp-stat-value"><?php echo (int)$websiteCount; ?></div>
                    <div class="scp-stat-footer">
                        <a href="#" class="scp-switch-tab" data-target="websites">Launch Platforms &rarr;</a>
                    </div>
                </div>
                <div class="scp-stat-card">
                    <div class="scp-stat-label">Open Invoices &amp; Quotes</div>
                    <div class="scp-stat-value <?php echo ($openInvoicesCount + $openQuotesCount) > 0 ? 'scp-text-warning' : 'scp-text-success'; ?>">
                        <?php echo (int)($openInvoicesCount + $openQuotesCount); ?>
                    </div>
                    <div class="scp-stat-footer">
                        <a href="#" class="scp-switch-tab" data-target="billing">View Invoices &amp; Quotes &rarr;</a>
                    </div>
                </div>
            </div>

            <div class="scp-overview-columns">
                <!-- Quick Websites & Hosted Platforms Card -->
                <div class="scp-card">
                    <div class="scp-card-header">
                        <h3>Your Websites &amp; Hosted Platforms</h3>
                        <a href="#" class="scp-link scp-switch-tab" data-target="websites">View All</a>
                    </div>
                    <div class="scp-card-body">
                        <?php if (empty($websites)): ?>
                            <div class="scp-empty-state">
                                <p>No websites or hosted platforms assigned yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="scp-site-list-compact">
                                <?php foreach (array_slice($websites, 0, 4) as $site):
                                    $pType = $site['platform_type'] ?? 'wordpress';
                                    $pShort = $site['platform_short_label'] ?? 'WordPress';
                                    $pBg = $site['platform_badge_bg'] ?? '#e0f2fe';
                                    $pCol = $site['platform_badge_color'] ?? '#0369a1';
                                ?>
                                    <div class="scp-site-row-compact">
                                        <div>
                                            <div class="scp-site-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                                <span><?php echo SCP_Security::e($site['site_name']); ?></span>
                                                <span class="scp-badge" style="background:<?php echo SCP_Security::e($pBg); ?>;color:<?php echo SCP_Security::e($pCol); ?>;font-size:11px;">
                                                    <?php echo SCP_Security::e($pShort); ?>
                                                </span>
                                            </div>
                                            <a href="<?php echo SCP_Security::e($site['site_url']); ?>" target="_blank" rel="noopener" class="scp-site-url">
                                                <?php echo SCP_Security::e($site['site_url']); ?> &#8599;
                                            </a>
                                        </div>
                                        <div class="scp-site-actions">
                                            <a href="<?php echo SCP_Security::e($site['site_url']); ?>" target="_blank" rel="noopener" class="scp-btn scp-btn-sm scp-btn-outline">
                                                Open
                                            </a>
                                            <?php if ($pType === 'wordpress' && !empty($site['enable_auto_login'])): ?>
                                                <button type="button" class="scp-btn scp-btn-sm scp-btn-primary scp-sso-launch-btn" data-site-id="<?php echo (int)$site['id']; ?>">
                                                    &#9889; One-Click WP Admin
                                                </button>
                                            <?php else: ?>
                                                <a href="<?php echo SCP_Security::e($site['login_link'] ?? $site['site_url']); ?>" target="_blank" rel="noopener" class="scp-btn scp-btn-sm scp-btn-secondary">
                                                    <?php echo $pType === 'wordpress' ? 'WP Login' : 'Control Panel'; ?> &#8599;
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Invoices Card -->
                <div class="scp-card">
                    <div class="scp-card-header">
                        <h3>Recent Stripe Invoices</h3>
                        <a href="#" class="scp-link scp-switch-tab" data-target="billing">All Billing</a>
                    </div>
                    <div class="scp-card-body">
                        <?php if (empty($invoices)): ?>
                            <div class="scp-empty-state">
                                <p>No invoices found on your Stripe billing profile.</p>
                            </div>
                        <?php else: ?>
                            <div class="scp-table-responsive">
                                <table class="scp-table">
                                    <thead>
                                        <tr>
                                            <th>Invoice</th>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($invoices, 0, 5) as $inv):
                                            $isInvPastDue = SCP_StripeService::isInvoicePastDue($inv);
                                        ?>
                                            <tr class="<?php echo $isInvPastDue ? 'scp-row-past-due' : ''; ?>">
                                                <td><strong><?php echo SCP_Security::e($inv['number'] ?: $inv['id']); ?></strong></td>
                                                <td><?php echo SCP_Security::e(date('M j, Y', $inv['created'])); ?></td>
                                                <td>$<?php echo SCP_Security::e(number_format(($inv['amount_due'] ?: $inv['total']) / 100, 2)); ?></td>
                                                <td>
                                                    <?php if ($isInvPastDue): ?>
                                                        <span class="scp-status-pill scp-status-past-due">PAST DUE</span>
                                                    <?php else: ?>
                                                        <span class="scp-status-pill scp-status-<?php echo SCP_Security::e($inv['status']); ?>">
                                                            <?php echo SCP_Security::e(ucfirst($inv['status'])); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($inv['status'] === 'open' && !empty($inv['hosted_invoice_url'])): ?>
                                                        <a href="<?php echo SCP_Security::e($inv['hosted_invoice_url']); ?>" target="_blank" rel="noopener" class="scp-btn scp-btn-xs scp-btn-primary">
                                                            Pay Now
                                                        </a>
                                                    <?php elseif (!empty($inv['hosted_invoice_url'])): ?>
                                                        <a href="<?php echo SCP_Security::e($inv['hosted_invoice_url']); ?>" target="_blank" rel="noopener" class="scp-btn scp-btn-xs scp-btn-outline">
                                                            Receipt
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- TAB 2: SUBSCRIPTIONS & BILLING -->
        <section class="scp-tab-panel" id="scp-tab-billing">
            <!-- Active Subscriptions -->
            <div class="scp-card scp-mb-24">
                <div class="scp-card-header">
                    <div>
                        <h3>Your Active Subscriptions</h3>
                        <p class="scp-card-sub">Manage your active recurring plans or open the Stripe Customer Portal to update payment methods.</p>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <button type="button" class="scp-btn scp-btn-outline scp-sync-stripe-btn">
                            &#8635; Refresh from Stripe
                        </button>
                        <button type="button" class="scp-btn scp-btn-secondary scp-open-stripe-portal">
                            Open Stripe Billing Portal &#8599;
                        </button>
                    </div>
                </div>
                <div class="scp-card-body">
                    <?php if (empty($subscriptions)): ?>
                        <div class="scp-empty-state">
                            <p>You do not have any active recurring subscriptions yet. Browse available plans below to get started.</p>
                        </div>
                    <?php else: ?>
                        <div class="scp-table-responsive">
                            <table class="scp-table">
                                <thead>
                                    <tr>
                                        <th>Plan / Product</th>
                                        <th>Status</th>
                                        <th>Billing Cycle</th>
                                        <th>Current Period End</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subscriptions as $sub):
                                        $firstItem = $sub['items']['data'][0] ?? array();
                                        $priceObj  = $firstItem['price'] ?? array();
                                        $amount    = isset($priceObj['unit_amount']) ? ($priceObj['unit_amount'] / 100) : 0;
                                        $interval  = $priceObj['recurring']['interval'] ?? 'month';
                                        $prodName  = $priceObj['nickname'] ?: ($firstItem['plan']['nickname'] ?? 'Cloud Subscription (' . $sub['id'] . ')');
                                        $cancelAtEnd = !empty($sub['cancel_at_period_end']);
                                    ?>
                                        <tr>
                                            <td><strong><?php echo SCP_Security::e($prodName); ?></strong></td>
                                            <td>
                                                <span class="scp-status-pill scp-status-<?php echo SCP_Security::e($sub['status']); ?>">
                                                    <?php echo SCP_Security::e(ucfirst($sub['status'])); ?>
                                                </span>
                                                <?php if ($cancelAtEnd): ?>
                                                    <span class="scp-status-pill scp-status-canceled">Cancels at period end</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>$<?php echo SCP_Security::e(number_format($amount, 2)); ?> / <?php echo SCP_Security::e($interval); ?></td>
                                            <td><?php echo !empty($sub['current_period_end']) ? SCP_Security::e(date('M j, Y', $sub['current_period_end'])) : '—'; ?></td>
                                            <td>
                                                <?php if (!$cancelAtEnd && in_array($sub['status'], array('active', 'trialing'), true)): ?>
                                                    <button type="button" class="scp-btn scp-btn-xs scp-btn-danger scp-cancel-sub-btn" data-sub-id="<?php echo SCP_Security::e($sub['id']); ?>">
                                                        Remove / Cancel
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-open-stripe-portal">
                                                        Manage in Stripe
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stripe Quotes History -->
            <div class="scp-card scp-mb-24">
                <div class="scp-card-header">
                    <div>
                        <h3>Stripe Quotes &amp; Proposals</h3>
                        <p class="scp-card-sub">Custom quotes and service proposals prepared for your account in Stripe.</p>
                    </div>
                </div>
                <div class="scp-card-body">
                    <?php if (empty($quotes)): ?>
                        <div class="scp-empty-state">
                            <p>No active or past quotes found for your Stripe customer profile.</p>
                        </div>
                    <?php else: ?>
                        <div class="scp-table-responsive">
                            <table class="scp-table">
                                <thead>
                                    <tr>
                                        <th>Quote #</th>
                                        <th>Description</th>
                                        <th>Created</th>
                                        <th>Acceptance Due Date</th>
                                        <th>Total Amount</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($quotes as $qt):
                                        $qtTotal = isset($qt['amount_total']) ? ($qt['amount_total'] / 100) : 0;
                                        $qtCurrency = strtoupper($qt['currency'] ?? 'USD');
                                        $qtNumber = $qt['number'] ?: $qt['id'];
                                        $qtDesc = $qt['description'] ?: ($qt['header'] ?: 'Custom Service Quote');
                                        $isQtPastDue = SCP_StripeService::isQuotePastDue($qt);
                                    ?>
                                        <tr class="<?php echo $isQtPastDue ? 'scp-row-past-due' : ''; ?>">
                                            <td><strong><?php echo SCP_Security::e($qtNumber); ?></strong></td>
                                            <td><?php echo SCP_Security::e($qtDesc); ?></td>
                                            <td><?php echo !empty($qt['created']) ? SCP_Security::e(date('M j, Y', $qt['created'])) : '—'; ?></td>
                                            <td><?php echo !empty($qt['expires_at']) ? SCP_Security::e(date('M j, Y', $qt['expires_at'])) : '—'; ?></td>
                                            <td><strong>$<?php echo SCP_Security::e(number_format($qtTotal, 2)); ?> <?php echo SCP_Security::e($qtCurrency); ?></strong></td>
                                            <td>
                                                <?php if ($isQtPastDue): ?>
                                                    <span class="scp-status-pill scp-status-past-due">PAST DUE DATE</span>
                                                <?php else: ?>
                                                    <span class="scp-status-pill scp-status-<?php echo SCP_Security::e($qt['status']); ?>">
                                                        <?php echo SCP_Security::e(ucfirst($qt['status'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="scp-table-actions">
                                                    <?php if (!empty($qt['hosted_quote_url'])): ?>
                                                        <a href="<?php echo SCP_Security::e($qt['hosted_quote_url']); ?>" target="_blank" rel="noopener" class="scp-btn scp-btn-xs scp-btn-primary">
                                                            <?php echo ($qt['status'] ?? '') === 'open' ? 'Review & Accept Quote' : 'View Quote'; ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-open-stripe-portal">
                                                            View in Stripe Portal
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (!empty($qt['invoice']) && is_string($qt['invoice'])): ?>
                                                        <span class="scp-badge">Invoice: <?php echo SCP_Security::e($qt['invoice']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Full Invoice History -->
            <div class="scp-card scp-mb-24">
                <div class="scp-card-header">
                    <div>
                        <h3>Stripe Invoices &amp; Statements</h3>
                        <p class="scp-card-sub">Pay open invoices online or download PDF statements for your accounting records.</p>
                    </div>
                </div>
                <div class="scp-card-body">
                    <?php if (empty($invoices)): ?>
                        <div class="scp-empty-state">
                            <p>No invoices found.</p>
                        </div>
                    <?php else: ?>
                        <div class="scp-table-responsive">
                            <table class="scp-table">
                                <thead>
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Created</th>
                                        <th>Due Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($invoices as $inv):
                                        $isInvPastDue = SCP_StripeService::isInvoicePastDue($inv);
                                    ?>
                                        <tr class="<?php echo $isInvPastDue ? 'scp-row-past-due' : ''; ?>">
                                            <td><strong><?php echo SCP_Security::e($inv['number'] ?: $inv['id']); ?></strong></td>
                                            <td><?php echo SCP_Security::e(date('M j, Y', $inv['created'])); ?></td>
                                            <td><?php echo !empty($inv['due_date']) ? SCP_Security::e(date('M j, Y', $inv['due_date'])) : 'Upon Receipt'; ?></td>
                                            <td><strong>$<?php echo SCP_Security::e(number_format(($inv['amount_due'] ?: $inv['total']) / 100, 2)); ?> <?php echo SCP_Security::e(strtoupper($inv['currency'] ?? 'USD')); ?></strong></td>
                                            <td>
                                                <?php if ($isInvPastDue): ?>
                                                    <span class="scp-status-pill scp-status-past-due">PAST DUE</span>
                                                <?php else: ?>
                                                    <span class="scp-status-pill scp-status-<?php echo SCP_Security::e($inv['status']); ?>">
                                                        <?php echo SCP_Security::e(ucfirst($inv['status'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="scp-table-actions">
                                                    <?php if ($inv['status'] === 'open' && !empty($inv['hosted_invoice_url'])): ?>
                                                        <a href="<?php echo SCP_Security::e($inv['hosted_invoice_url']); ?>" target="_blank" rel="noopener" class="scp-btn scp-btn-xs scp-btn-primary">
                                                            Pay Invoice Now
                                                        </a>
                                                    <?php elseif (!empty($inv['hosted_invoice_url'])): ?>
                                                        <a href="<?php echo SCP_Security::e($inv['hosted_invoice_url']); ?>" target="_blank" rel="noopener" class="scp-btn scp-btn-xs scp-btn-outline">
                                                            View Online
                                                        </a>
                                                    <?php endif; ?>

                                                    <?php if (!empty($inv['invoice_pdf'])): ?>
                                                        <a href="<?php echo SCP_Security::e($inv['invoice_pdf']); ?>" target="_blank" rel="noopener" class="scp-btn scp-btn-xs scp-btn-secondary">
                                                            PDF
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stripe Payments & Charge Receipts -->
            <div class="scp-card scp-mb-24">
                <div class="scp-card-header">
                    <div>
                        <h3>Payment History &amp; Receipts</h3>
                        <p class="scp-card-sub">All card payments and charges processed on your Stripe billing profile.</p>
                    </div>
                </div>
                <div class="scp-card-body">
                    <?php if (empty($payments)): ?>
                        <div class="scp-empty-state">
                            <p>No completed payments found on your Stripe billing profile yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="scp-table-responsive">
                            <table class="scp-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Description</th>
                                        <th>Payment Method</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Receipt</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $pay):
                                        $payAmt  = isset($pay['amount']) ? ($pay['amount'] / 100) : 0;
                                        $payCurr = strtoupper($pay['currency'] ?? 'USD');
                                        $cardObj = $pay['payment_method_details']['card'] ?? array();
                                        $brand   = strtoupper($cardObj['brand'] ?? 'CARD');
                                        $last4   = $cardObj['last4'] ?? '';
                                    ?>
                                        <tr>
                                            <td><?php echo !empty($pay['created']) ? SCP_Security::e(date('M j, Y', $pay['created'])) : '—'; ?></td>
                                            <td><strong><?php echo SCP_Security::e($pay['description'] ?: 'Cloud Portal Payment'); ?></strong></td>
                                            <td><?php echo !empty($last4) ? SCP_Security::e($brand . ' •••• ' . $last4) : 'Card / Stripe'; ?></td>
                                            <td><strong>$<?php echo SCP_Security::e(number_format($payAmt, 2)); ?> <?php echo SCP_Security::e($payCurr); ?></strong></td>
                                            <td>
                                                <span class="scp-status-pill scp-status-<?php echo !empty($pay['refunded']) ? 'canceled' : (($pay['status'] ?? '') === 'succeeded' ? 'active' : 'open'); ?>">
                                                    <?php echo !empty($pay['refunded']) ? 'Refunded' : SCP_Security::e(ucfirst($pay['status'] ?? 'paid')); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($pay['receipt_url'])): ?>
                                                    <a href="<?php echo SCP_Security::e($pay['receipt_url']); ?>" target="_blank" rel="noopener" class="scp-btn scp-btn-xs scp-btn-outline">
                                                        Receipt &#8599;
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Add New Products / Services Catalog (Last Section on Subscriptions & Billing Page) -->
            <?php
            $portalCatSections = $categorizedCatalog['sections'] ?? array();
            ?>
            <div class="scp-card" id="portal-catalog-top">
                <div class="scp-card-header">
                    <div>
                        <h3>Available Cloud Plans &amp; Add-On Products</h3>
                        <p class="scp-card-sub">Browse our service categories below and add new hosting plans, maintenance packages, or services directly from our live catalog.</p>
                    </div>
                </div>
                <div class="scp-card-body">
                    <?php if (empty($portalCatSections)): ?>
                        <div class="scp-empty-state">
                            <p>No active products found in Stripe yet.</p>
                        </div>
                    <?php else: ?>
                        <!-- Quick-Jump Category Navigation Bar -->
                        <div class="scp-category-quicklinks" style="margin-bottom:24px;">
                            <span class="scp-quicklinks-label">Quick Jump to Category:</span>
                            <div class="scp-quicklinks-pills">
                                <?php foreach ($portalCatSections as $pIdx => $pSec): ?>
                                    <a href="#portal-cat-<?php echo SCP_Security::e($pSec['slug']); ?>" class="scp-category-jump-pill <?php echo $pIdx === 0 ? 'active' : ''; ?>">
                                        <span><?php echo SCP_Security::e($pSec['name']); ?></span>
                                        <span class="scp-jump-count"><?php echo count($pSec['products']); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="scp-categorized-catalog-wrapper">
                            <?php foreach ($portalCatSections as $pSec):
                                $pAvailTerms = $pSec['available_terms'] ?? array();
                                $pDefCatTerm = $pSec['default_term_key'] ?? '';
                            ?>
                                <div id="portal-cat-<?php echo SCP_Security::e($pSec['slug']); ?>" class="scp-category-section-block">
                                    <div class="scp-category-section-header">
                                        <div class="scp-category-header-text">
                                            <div class="scp-category-title-row">
                                                <h3 class="scp-category-heading"><?php echo SCP_Security::e($pSec['name']); ?></h3>
                                                <span class="scp-category-count-badge">
                                                    <?php echo count($pSec['products']); ?> <?php echo count($pSec['products']) === 1 ? 'Product' : 'Products'; ?>
                                                </span>
                                            </div>
                                            <?php if (!empty($pSec['description'])): ?>
                                                <p class="scp-category-description"><?php echo SCP_Security::e($pSec['description']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                                            <?php if (count($pAvailTerms) > 1): ?>
                                                <div class="scp-term-switcher-bar scp-category-term-bar" role="tablist" aria-label="Select Billing Term">
                                                    <?php foreach ($pAvailTerms as $tIdx => $tOpt):
                                                        $isTermActive = ($pDefCatTerm !== '' ? ($tOpt['term_key'] === $pDefCatTerm) : ($tIdx === 0));
                                                    ?>
                                                        <button type="button"
                                                                class="scp-term-pill scp-category-term-btn <?php echo $isTermActive ? 'active' : ''; ?>"
                                                                data-term-key="<?php echo SCP_Security::e($tOpt['term_key']); ?>">
                                                            <?php echo SCP_Security::e($tOpt['term_name']); ?>
                                                        </button>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (count($portalCatSections) > 1): ?>
                                                <a href="#portal-catalog-top" class="scp-back-to-top-link">&uarr; Top of Catalog</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="scp-pricing-grid">
                                        <?php foreach ($pSec['products'] as $item):
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

                                                    <div class="scp-v3-price-row">
                                                        <div class="scp-pricing-price">
                                                            $<span class="scp-js-price-amount"><?php echo SCP_Security::e($priceAmt); ?></span><span class="scp-js-price-unit"><?php echo SCP_Security::e($priceUnit); ?></span>
                                                        </div>
                                                        <span class="scp-save-badge scp-js-save-badge" style="<?php echo $saveBadge !== '' ? '' : 'display:none;'; ?>">
                                                            <?php echo SCP_Security::e($saveBadge); ?>
                                                        </span>
                                                    </div>

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

                                                    <div class="scp-v3-cta-wrap">
                                                        <button type="button"
                                                                class="scp-btn <?php echo $isFeatured ? 'scp-btn-primary' : 'scp-btn-outline-blue'; ?> scp-buy-product-btn scp-js-cta-btn"
                                                                style="width:100%;justify-content:center;"
                                                                data-price-id="<?php echo SCP_Security::e($stripeHook); ?>"
                                                                data-mode="<?php echo $billingMode === 'one_time' ? 'payment' : 'subscription'; ?>">
                                                            <?php echo SCP_Security::e($ctaLabel); ?>
                                                        </button>
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
                                    $pSecNotes = $pSec['bottom_notes'] ?? array();
                                    if (empty($pSecNotes)) {
                                        $pfText  = trim((string)($pSec['bottom_note_text'] ?? ''));
                                        $pfTitle = trim((string)($pSec['bottom_note_title'] ?? ''));
                                        $pfIcon  = trim((string)($pSec['bottom_note_icon'] ?? 'green-exclamation'));
                                        if ($pfText !== '' || $pfTitle !== '') {
                                            $pSecNotes[] = array('icon' => $pfIcon, 'title' => $pfTitle, 'text' => $pfText);
                                        }
                                    }
                                    if (!empty($pSecNotes)):
                                    ?>
                                        <div class="scp-category-footer-notes-stack">
                                            <?php foreach ($pSecNotes as $psNote):
                                                $pNoteText  = trim((string)($psNote['text'] ?? ''));
                                                $pNoteTitle = trim((string)($psNote['title'] ?? ''));
                                                $pNoteIcon  = trim((string)($psNote['icon'] ?? 'green-exclamation'));
                                                if ($pNoteText === '' && $pNoteTitle === '') {
                                                    continue;
                                                }
                                                $pIconSvg = SCP_Security::renderCategoryNoteIcon($pNoteIcon);
                                            ?>
                                                <div class="scp-category-footer-note">
                                                    <?php if (!empty($pIconSvg)): ?>
                                                        <span class="scp-cat-note-icon-wrap"><?php echo $pIconSvg; ?></span>
                                                    <?php endif; ?>
                                                    <div class="scp-cat-note-content">
                                                        <?php if ($pNoteTitle !== ''): ?>
                                                            <strong><?php echo SCP_Security::e($pNoteTitle); ?></strong>
                                                        <?php endif; ?>
                                                        <span><?php echo SCP_Security::e($pNoteText); ?></span>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- TAB 3: DOMAIN MANAGER -->
        <section class="scp-tab-panel" id="scp-tab-domains">
            <!-- Domain Search & Registration Box -->
            <div class="scp-card scp-domain-search-hero scp-mb-24">
                <div class="scp-card-body">
                    <h3>Register a New Domain Name</h3>
                    <p>Real-time domain availability lookup powered by OpenSRS &amp; Namecheap with instant Stripe Checkout.</p>
                    <form id="scp-domain-search-form" class="scp-domain-search-bar">
                        <input type="text" name="domain_query" id="scp-domain-query" placeholder="Enter your desired domain (e.g. mycloudcompany.com)" required />
                        <button type="submit" class="scp-btn scp-btn-primary">Search Availability</button>
                    </form>
                    <div id="scp-domain-search-results" class="scp-domain-results-wrap" style="display:none;"></div>
                </div>
            </div>

            <!-- Existing Client Domains -->
            <div class="scp-card">
                <div class="scp-card-header">
                    <div>
                        <h3>Your Registered Domains</h3>
                        <p class="scp-card-sub">Renew domains or update custom DNS nameservers.</p>
                    </div>
                </div>
                <div class="scp-card-body">
                    <?php if (empty($domains)): ?>
                        <div class="scp-empty-state">
                            <p>You don't have any domains under management yet. Search above to register a new domain!</p>
                        </div>
                    <?php else: ?>
                        <div class="scp-table-responsive">
                            <table class="scp-table">
                                <thead>
                                    <tr>
                                        <th>Domain Name</th>
                                        <th>Registrar</th>
                                        <th>Status</th>
                                        <th>Expiration Date</th>
                                        <th>Nameservers</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($domains as $dom): ?>
                                        <tr>
                                            <td><strong><?php echo SCP_Security::e($dom['domain_name']); ?></strong></td>
                                            <td><span class="scp-badge"><?php echo SCP_Security::e(strtoupper($dom['registrar'])); ?></span></td>
                                            <td>
                                                <span class="scp-status-pill scp-status-<?php echo SCP_Security::e($dom['status']); ?>">
                                                    <?php echo SCP_Security::e(ucfirst($dom['status'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo !empty($dom['expiration_date']) ? SCP_Security::e(date('M j, Y', strtotime($dom['expiration_date']))) : '—'; ?></td>
                                            <td>
                                                <small class="scp-ns-preview"><?php echo SCP_Security::e(str_replace("\n", ', ', $dom['nameservers'] ?? 'Default')); ?></small>
                                            </td>
                                            <td>
                                                <div class="scp-table-actions">
                                                    <button type="button"
                                                        class="scp-btn scp-btn-xs scp-btn-primary scp-domain-checkout-btn"
                                                        data-domain="<?php echo SCP_Security::e($dom['domain_name']); ?>"
                                                        data-action="renew">
                                                        Renew (1 Yr)
                                                    </button>
                                                    <button type="button"
                                                        class="scp-btn scp-btn-xs scp-btn-outline scp-open-ns-modal"
                                                        data-domain="<?php echo SCP_Security::e($dom['domain_name']); ?>"
                                                        data-ns="<?php echo SCP_Security::e($dom['nameservers'] ?? ''); ?>">
                                                        DNS / Nameservers
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- TAB 4: WEBSITES & HOSTED PLATFORMS -->
        <section class="scp-tab-panel" id="scp-tab-websites">
            <div class="scp-card">
                <div class="scp-card-header">
                    <div>
                        <h3>Websites, Ad Servers, Mass Email &amp; Hosted Cloud Apps</h3>
                        <p class="scp-card-sub">Quick links to launch your live websites, WordPress 1-Click SSO dashboards, Dedicated Ad Servers, Mass Emailing platforms, and custom Cloud/VPS applications.</p>
                    </div>
                </div>
                <div class="scp-card-body">
                    <?php if (empty($websites)): ?>
                        <div class="scp-empty-state">
                            <p>No websites or hosted platforms have been linked to your account yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="scp-websites-grid">
                            <?php foreach ($websites as $site):
                                $pType    = $site['platform_type'] ?? 'wordpress';
                                $pLabel   = $site['platform_label'] ?? 'WordPress (with 1-Click SSO)';
                                $pShort   = $site['platform_short_label'] ?? 'WordPress';
                                $pBg      = $site['platform_badge_bg'] ?? '#e0f2fe';
                                $pCol     = $site['platform_badge_color'] ?? '#0369a1';
                                $loginUrl = $site['login_link'] ?? ($site['login_url'] ?: ($pType === 'wordpress' ? rtrim($site['site_url'], '/') . '/wp-login.php' : $site['site_url']));
                            ?>
                                <div class="scp-website-card">
                                    <div class="scp-website-card-header">
                                        <div>
                                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                                                <h4 style="margin:0;"><?php echo SCP_Security::e($site['site_name']); ?></h4>
                                                <span class="scp-badge" style="background:<?php echo SCP_Security::e($pBg); ?>;color:<?php echo SCP_Security::e($pCol); ?>;">
                                                    <?php echo SCP_Security::e($pShort); ?>
                                                </span>
                                            </div>
                                            <a href="<?php echo SCP_Security::e($site['site_url']); ?>" target="_blank" rel="noopener" class="scp-site-url">
                                                <?php echo SCP_Security::e($site['site_url']); ?> &#8599;
                                            </a>
                                        </div>
                                        <span class="scp-status-pill scp-status-active">
                                            Active
                                        </span>
                                    </div>

                                    <div class="scp-website-meta">
                                        <div class="scp-meta-item">
                                            <span class="scp-meta-label">Platform &amp; Plan</span>
                                            <span class="scp-meta-val"><?php echo SCP_Security::e($site['hosting_plan'] ?: $pLabel); ?></span>
                                        </div>
                                        <div class="scp-meta-item">
                                            <span class="scp-meta-label">Management Access</span>
                                            <span class="scp-meta-val">
                                                <?php if ($pType === 'wordpress' && !empty($site['enable_auto_login'])): ?>
                                                    <span class="scp-text-success">&#9889; One-Click WP SSO Active</span>
                                                <?php elseif ($pType === 'wordpress'): ?>
                                                    <span>Direct WP Admin Login</span>
                                                <?php else: ?>
                                                    <span>Direct Control Panel Access</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>

                                    <?php if (!empty($site['notes'])): ?>
                                        <p class="scp-website-notes"><?php echo SCP_Security::e($site['notes']); ?></p>
                                    <?php endif; ?>

                                    <div class="scp-website-actions">
                                        <a href="<?php echo SCP_Security::e($site['site_url']); ?>" target="_blank" rel="noopener" class="scp-btn scp-btn-outline">
                                            Open Live URL &#8599;
                                        </a>

                                        <?php if ($pType === 'wordpress' && !empty($site['enable_auto_login'])): ?>
                                            <button type="button" class="scp-btn scp-btn-primary scp-sso-launch-btn" data-site-id="<?php echo (int)$site['id']; ?>">
                                                &#9889; One-Click WP Admin Login
                                            </button>
                                        <?php elseif ($pType === 'wordpress'): ?>
                                            <a href="<?php echo SCP_Security::e($loginUrl); ?>" target="_blank" rel="noopener" class="scp-btn scp-btn-secondary">
                                                WordPress Login &#8599;
                                            </a>
                                        <?php else: ?>
                                            <a href="<?php echo SCP_Security::e($loginUrl); ?>" target="_blank" rel="noopener" class="scp-btn scp-btn-primary">
                                                Launch <?php echo SCP_Security::e($pShort); ?> Panel &#8599;
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- TAB 5: ACCOUNT & PROFILE -->
        <section class="scp-tab-panel" id="scp-tab-account">
            <div class="scp-overview-columns">
                <!-- Personal & Billing Details -->
                <div class="scp-card">
                    <div class="scp-card-header">
                        <div>
                            <h3>Account &amp; Billing Information</h3>
                            <p class="scp-card-sub">Changes saved here automatically synchronize with your Stripe Customer profile and WHOIS registrant defaults.</p>
                        </div>
                    </div>
                    <div class="scp-card-body">
                        <form id="scp-profile-form" class="scp-form">
                            <div class="scp-form-row">
                                <div class="scp-form-group">
                                    <label>First Name</label>
                                    <input type="text" name="first_name" value="<?php echo SCP_Security::e($currentUser['first_name'] ?? ''); ?>" required />
                                </div>
                                <div class="scp-form-group">
                                    <label>Last Name</label>
                                    <input type="text" name="last_name" value="<?php echo SCP_Security::e($currentUser['last_name'] ?? ''); ?>" required />
                                </div>
                            </div>

                            <div class="scp-form-row">
                                <div class="scp-form-group">
                                    <label>Email Address</label>
                                    <input type="email" name="email" value="<?php echo SCP_Security::e($currentUser['email'] ?? ''); ?>" required />
                                </div>
                                <div class="scp-form-group">
                                    <label>Phone Number</label>
                                    <input type="text" name="phone" value="<?php echo SCP_Security::e($currentUser['phone'] ?? ''); ?>" placeholder="+1.5551234567" />
                                </div>
                            </div>

                            <div class="scp-form-group">
                                <label>Company / Organization</label>
                                <input type="text" name="company" value="<?php echo SCP_Security::e($currentUser['company'] ?? ''); ?>" />
                            </div>

                            <div class="scp-form-group">
                                <label>Street Address</label>
                                <input type="text" name="address_1" value="<?php echo SCP_Security::e($currentUser['address_1'] ?? ''); ?>" />
                            </div>

                            <div class="scp-form-row">
                                <div class="scp-form-group">
                                    <label>City</label>
                                    <input type="text" name="city" value="<?php echo SCP_Security::e($currentUser['city'] ?? ''); ?>" />
                                </div>
                                <div class="scp-form-group">
                                    <label>State / Province</label>
                                    <input type="text" name="state" value="<?php echo SCP_Security::e($currentUser['state'] ?? ''); ?>" />
                                </div>
                            </div>

                            <div class="scp-form-row">
                                <div class="scp-form-group">
                                    <label>Postal / ZIP Code</label>
                                    <input type="text" name="postcode" value="<?php echo SCP_Security::e($currentUser['postcode'] ?? ''); ?>" />
                                </div>
                                <div class="scp-form-group">
                                    <label>Country Code (ISO-2)</label>
                                    <input type="text" name="country" value="<?php echo SCP_Security::e($currentUser['country'] ?: 'US'); ?>" maxlength="2" />
                                </div>
                            </div>

                            <button type="submit" class="scp-btn scp-btn-primary">Save Profile &amp; Sync to Stripe</button>
                        </form>
                    </div>
                </div>

                <!-- Security / Password Update -->
                <div class="scp-card">
                    <div class="scp-card-header">
                        <div>
                            <h3>Security &amp; Password</h3>
                            <p class="scp-card-sub">Update your client portal password.</p>
                        </div>
                    </div>
                    <div class="scp-card-body">
                        <form id="scp-password-form" class="scp-form">
                            <div class="scp-form-group">
                                <label>Current Password</label>
                                <input type="password" name="current_password" required />
                            </div>
                            <div class="scp-form-group">
                                <label>New Password (minimum 8 characters)</label>
                                <input type="password" name="new_password" minlength="8" required />
                            </div>
                            <button type="submit" class="scp-btn scp-btn-secondary">Update Password</button>
                        </form>

                        <hr class="scp-divider" />

                        <div class="scp-security-box">
                            <h4>Stripe Billing Profile</h4>
                            <p>Customer ID: <code><?php echo SCP_Security::e($customerId ?: 'Not linked yet'); ?></code></p>
                            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                                <button type="button" class="scp-btn scp-btn-outline scp-btn-sm scp-open-stripe-portal">
                                    Manage Saved Credit Cards &amp; Billing Address
                                </button>
                                <button type="button" class="scp-btn scp-btn-secondary scp-btn-sm scp-sync-stripe-btn">
                                    &#8635; Pull Latest Info from Stripe
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Nameserver Edit Modal -->
    <div id="scp-ns-modal" class="scp-modal" style="display:none;">
        <div class="scp-modal-Dialog">
            <div class="scp-modal-header">
                <h3>Update Nameservers — <span id="scp-ns-domain-label"></span></h3>
                <button type="button" class="scp-modal-close">&times;</button>
            </div>
            <form id="scp-ns-form" class="scp-form">
                <input type="hidden" name="domain_name" id="scp-ns-domain-input" />
                <div class="scp-form-group">
                    <label>Enter one nameserver per line (minimum 2):</label>
                    <textarea name="nameservers" id="scp-ns-textarea" rows="4" required></textarea>
                </div>
                <div class="scp-modal-footer">
                    <button type="button" class="scp-btn scp-btn-outline scp-modal-close">Cancel</button>
                    <button type="submit" class="scp-btn scp-btn-primary">Save Nameservers</button>
                </div>
            </form>
        </div>
    </div>
</div>
