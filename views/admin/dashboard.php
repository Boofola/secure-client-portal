<?php
if (!defined('SCP_APP_INIT')) {
    exit;
}

$totalUsersCount    = is_array($allUsers) ? count($allUsers) : 0;
$totalWebsitesCount = is_array($allWebsites) ? count($allWebsites) : 0;
$totalDomainsCount  = is_array($allDomains) ? count($allDomains) : 0;
$importedProducts   = $importedProducts ?? array();
$productCategories  = $productCategories ?? array();
$importedSubscriptions = $importedSubscriptions ?? array();
$importedInvoices   = $importedInvoices ?? array();
$importedPayments   = $importedPayments ?? array();
$importedQuotes     = $importedQuotes ?? array();
$lastFullStripeSync = !empty($settings['last_full_stripe_sync']) ? date('M j, Y g:i a', (int)$settings['last_full_stripe_sync']) : 'Not run yet';

$unreadInquiriesCount = 0;
if (is_array($inquiries)) {
    foreach ($inquiries as $inq) {
        if (($inq['status'] ?? '') === 'unread') {
            $unreadInquiriesCount++;
        }
    }
}

$stripeMode         = $settings['stripe_mode'] ?? 'test';
$ncEnabled          = ($settings['nc_enabled'] ?? 'yes') !== 'no';
$osFallbackMode     = $settings['os_fallback_enabled'] ?? 'yes';
$activeRegistrar    = (!$ncEnabled || $osFallbackMode === 'primary') ? 'opensrs' : 'namecheap';
$webhookEndpoint    = SCP_Security::getBaseUrl() . '/api/stripe-webhook';
$companionSsoPath = 'client-companion/secure-cloud-portal-client-sso.php';
?>
<div class="scp-portal-shell">
    <!-- Admin Left Sidebar Navigation -->
    <aside class="scp-sidebar">
        <div class="scp-sidebar-brand">
            <span class="scp-badge" style="background:rgba(14,165,233,0.18);color:#38bdf8;border:1px solid rgba(14,165,233,0.35);font-size:11px;letter-spacing:0.04em;padding:4px 10px;">
                ADMIN CONTROL CENTER
            </span>
        </div>

        <nav class="scp-nav">
            <button type="button" class="scp-nav-item active" data-tab="admin-overview">
                <span class="scp-nav-icon">&#9638;</span>
                <span>Executive Overview</span>
            </button>
            <button type="button" class="scp-nav-item" data-tab="admin-clients">
                <span class="scp-nav-icon">&#128101;</span>
                <span>Clients &amp; Stripe Sync</span>
                <span class="scp-badge"><?php echo (int)$totalUsersCount; ?></span>
            </button>
            <button type="button" class="scp-nav-item" data-tab="admin-products">
                <span class="scp-nav-icon">&#128230;</span>
                <span>Products &amp; Categories</span>
                <span class="scp-badge"><?php echo count($catalogProducts ?? $importedProducts); ?></span>
            </button>
            <button type="button" class="scp-nav-item" data-tab="admin-websites">
                <span class="scp-nav-icon">&#9889;</span>
                <span>Websites &amp; Hosted Apps</span>
            </button>
            <button type="button" class="scp-nav-item" data-tab="admin-domains">
                <span class="scp-nav-icon">&#127760;</span>
                <span>Domains Manager</span>
            </button>
            <button type="button" class="scp-nav-item" data-tab="admin-email">
                <span class="scp-nav-icon">&#128231;</span>
                <span>Email Center</span>
                <span class="scp-badge scp-badge-warning" id="scp-email-nav-unread-badge" style="<?php echo ((int)($emailCounts['inbox_unread'] ?? 0)) > 0 ? '' : 'display:none;'; ?>"><?php echo (int)($emailCounts['inbox_unread'] ?? 0); ?></span>
            </button>
            <button type="button" class="scp-nav-item" data-tab="admin-inquiries">
                <span class="scp-nav-icon">&#9993;</span>
                <span>Contact Inquiries</span>
                <?php if ($unreadInquiriesCount > 0): ?>
                    <span class="scp-badge scp-badge-warning"><?php echo (int)$unreadInquiriesCount; ?></span>
                <?php endif; ?>
            </button>
            <button type="button" class="scp-nav-item" data-tab="admin-settings">
                <span class="scp-nav-icon">&#9881;</span>
                <span>API &amp; Portal Settings</span>
            </button>
            <button type="button" class="scp-nav-item" data-tab="admin-logs">
                <span class="scp-nav-icon">&#128737;</span>
                <span>Security &amp; Audit Logs</span>
            </button>
        </nav>

        <div class="scp-sidebar-footer">
            <a href="index.php?page=portal" class="scp-logout-link" style="margin-bottom:8px;background:rgba(16,185,129,0.12);color:#34d399;border-color:rgba(16,185,129,0.3);">
                <span>&#128100;</span> View Client Portal
            </a>
            <a href="index.php?page=logout" class="scp-logout-link">
                <span>&#10162;</span> Sign Out
            </a>
        </div>
    </aside>

    <!-- Admin Main Content Area -->
    <div class="scp-main">
        <header class="scp-topbar">
            <div>
                <h1 class="scp-page-title">Admin Control Center</h1>
                <p class="scp-page-subtitle">Manage clients, Stripe billing synchronization, WordPress SSO sites, domains, and API configurations.</p>
            </div>
            <div class="scp-topbar-actions">
                <span class="scp-status-pill <?php echo $stripeMode === 'live' ? 'scp-status-active' : 'scp-status-open'; ?>">
                    Stripe Mode: <?php echo strtoupper(SCP_Security::e($stripeMode)); ?>
                </span>
                <span class="scp-status-pill scp-status-active">
                    Primary: <?php echo strtoupper(SCP_Security::e($activeRegistrar)); ?>
                </span>
                <?php if ($activeRegistrar === 'namecheap' && $osFallbackMode === 'yes'): ?>
                    <span class="scp-status-pill scp-status-open" title="OpenSRS is active as automatic fallback if Namecheap encounters an error">
                        Fallback: OPENSRS
                    </span>
                <?php endif; ?>
                <button type="button" class="scp-btn scp-btn-outline scp-admin-import-all-stripe-btn" title="Import all existing Products, Customers, Subscriptions, Invoices, Payments & Quotes from Stripe and push unlinked portal clients to Stripe">
                    &#8644; Import All from Stripe
                </button>
                <button type="button" class="scp-btn scp-btn-primary scp-open-create-user-modal">
                    + New Client / Admin
                </button>
            </div>
        </header>

        <div id="scp-global-toast" class="scp-toast" style="display:none;"></div>

        <!-- ADMIN TAB 1: OVERVIEW -->
        <section class="scp-tab-panel active" id="scp-tab-admin-overview">
            <div class="scp-stats-grid">
                <div class="scp-stat-card">
                    <div class="scp-stat-label">Total Accounts</div>
                    <div class="scp-stat-value"><?php echo (int)$totalUsersCount; ?></div>
                    <div class="scp-stat-footer">
                        <a href="#" class="scp-switch-tab" data-target="admin-clients">Manage Clients &rarr;</a>
                    </div>
                </div>
                <div class="scp-stat-card">
                    <div class="scp-stat-label">Websites &amp; Hosted Apps</div>
                    <div class="scp-stat-value"><?php echo (int)$totalWebsitesCount; ?></div>
                    <div class="scp-stat-footer">
                        <a href="#" class="scp-switch-tab" data-target="admin-websites">Manage Platforms &rarr;</a>
                    </div>
                </div>
                <div class="scp-stat-card">
                    <div class="scp-stat-label">Managed Client Domains</div>
                    <div class="scp-stat-value"><?php echo (int)$totalDomainsCount; ?></div>
                    <div class="scp-stat-footer">
                        <a href="#" class="scp-switch-tab" data-target="admin-domains">View Domains &rarr;</a>
                    </div>
                </div>
                <div class="scp-stat-card">
                    <div class="scp-stat-label">Unread Inquiries</div>
                    <div class="scp-stat-value <?php echo $unreadInquiriesCount > 0 ? 'scp-text-warning' : 'scp-text-success'; ?>">
                        <?php echo (int)$unreadInquiriesCount; ?>
                    </div>
                    <div class="scp-stat-footer">
                        <a href="#" class="scp-switch-tab" data-target="admin-inquiries">Open Inbox &rarr;</a>
                    </div>
                </div>
            </div>

            <!-- Executive Billing & Revenue Summary -->
            <?php
            $bSum = array_merge([
                'outstanding_balance_cents'  => 0,
                'past_due_balance_cents'     => 0,
                'open_invoices_count'        => 0,
                'past_due_invoices_count'    => 0,
                'open_quotes_count'          => 0,
                'past_due_quotes_count'      => 0,
                'active_subscriptions_count' => 0,
                'mrr_cents'                  => 0,
                'recent_revenue_30d_cents'   => 0,
                'total_revenue_cents'        => 0,
                'recent_payments_30d_count'  => 0,
                'new_purchases_30d_count'    => 0,
                'outstanding_invoices'       => [],
                'recent_purchases'           => [],
            ], is_array($billingSummary ?? null) ? $billingSummary : []);
            if (!is_array($bSum['outstanding_invoices'])) {
                $bSum['outstanding_invoices'] = is_array($bSum['outstanding_items'] ?? null) ? $bSum['outstanding_items'] : [];
            }
            if (!is_array($bSum['recent_purchases'])) {
                $bSum['recent_purchases'] = [];
            }
            ?>
            <div class="scp-card scp-mb-24" style="border-left:4px solid #0284c7;">
                <div class="scp-card-header">
                    <div>
                        <h3>Executive Billing &amp; Revenue Summary</h3>
                        <p class="scp-card-sub">Real-time overview of outstanding balances, open &amp; past-due invoices, active subscriptions, and new products purchased across all clients.</p>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <a href="#" class="scp-btn scp-btn-xs scp-btn-outline scp-switch-tab" data-target="admin-clients">View All Clients &amp; Invoices &rarr;</a>
                    </div>
                </div>
                <div class="scp-card-body">
                    <div class="scp-stats-grid" style="margin-bottom:20px;">
                        <div class="scp-stat-card" style="padding:16px;<?php echo $bSum['past_due_balance_cents'] > 0 ? 'border-color:#991b1b;background:#fff1f2;' : ''; ?>">
                            <div class="scp-stat-label">Outstanding Balance</div>
                            <div class="scp-stat-value <?php echo $bSum['outstanding_balance_cents'] > 0 ? 'scp-text-danger' : 'scp-text-success'; ?>" style="font-size:24px;">
                                $<?php echo SCP_Security::e(number_format($bSum['outstanding_balance_cents'] / 100, 2)); ?>
                            </div>
                            <div class="scp-stat-footer" style="font-size:12px;">
                                <?php if ($bSum['past_due_balance_cents'] > 0): ?>
                                    <span style="color:#991b1b;font-weight:700;">&#9888; $<?php echo SCP_Security::e(number_format($bSum['past_due_balance_cents'] / 100, 2)); ?> Past Due</span>
                                <?php else: ?>
                                    <span class="scp-text-muted">No past-due balance</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="scp-stat-card" style="padding:16px;<?php echo ($bSum['past_due_invoices_count'] > 0 || $bSum['past_due_quotes_count'] > 0) ? 'border-color:#991b1b;background:#fff1f2;' : ''; ?>">
                            <div class="scp-stat-label">Outstanding Invoices &amp; Quotes</div>
                            <div class="scp-stat-value" style="font-size:24px;">
                                <?php echo (int)$bSum['open_invoices_count']; ?> <span style="font-size:14px;font-weight:500;color:#64748b;">Open Inv.</span>
                            </div>
                            <div class="scp-stat-footer" style="font-size:12px;">
                                <?php if ($bSum['past_due_invoices_count'] > 0 || $bSum['past_due_quotes_count'] > 0): ?>
                                    <span style="color:#991b1b;font-weight:700;">
                                        <?php echo (int)$bSum['past_due_invoices_count']; ?> Past-Due Inv. &bull; <?php echo (int)$bSum['past_due_quotes_count']; ?> Expired/Past-Due Quotes
                                    </span>
                                <?php else: ?>
                                    <span class="scp-text-muted"><?php echo (int)$bSum['open_quotes_count']; ?> Open Quotes Pending Acceptance</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="scp-stat-card" style="padding:16px;">
                            <div class="scp-stat-label">Active Subscriptions &amp; MRR</div>
                            <div class="scp-stat-value" style="font-size:24px;color:#0284c7;">
                                <?php echo (int)$bSum['active_subscriptions_count']; ?> <span style="font-size:14px;font-weight:500;color:#64748b;">Active</span>
                            </div>
                            <div class="scp-stat-footer" style="font-size:12px;">
                                Est. MRR: <strong>$<?php echo SCP_Security::e(number_format($bSum['mrr_cents'] / 100, 2)); ?>/mo</strong> &bull; <?php echo (int)$bSum['new_purchases_30d_count']; ?> new (30d)
                            </div>
                        </div>

                        <div class="scp-stat-card" style="padding:16px;">
                            <div class="scp-stat-label">Collected Revenue (Last 30 Days)</div>
                            <div class="scp-stat-value scp-text-success" style="font-size:24px;">
                                $<?php echo SCP_Security::e(number_format($bSum['recent_revenue_30d_cents'] / 100, 2)); ?>
                            </div>
                            <div class="scp-stat-footer" style="font-size:12px;">
                                <?php echo (int)$bSum['recent_payments_30d_count']; ?> payments (30d) &bull; Lifetime: <strong>$<?php echo SCP_Security::e(number_format($bSum['total_revenue_cents'] / 100, 2)); ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="scp-overview-columns" style="margin-bottom:0;">
                        <!-- Left: Outstanding Balances & Unpaid Invoices -->
                        <div style="border:1px solid #e2e8f0;border-radius:10px;padding:16px;background:#f8fafc;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                                <h4 style="margin:0;font-size:15px;color:#0f172a;">Outstanding Balances &amp; Unpaid Invoices (<?php echo count($bSum['outstanding_invoices']); ?>)</h4>
                            </div>
                            <?php if (empty($bSum['outstanding_invoices'])): ?>
                                <div class="scp-empty-state" style="padding:18px;">
                                    <p>All client invoices are paid and up to date.</p>
                                </div>
                            <?php else: ?>
                                <div class="scp-table-responsive">
                                    <table class="scp-table">
                                        <thead>
                                            <tr>
                                                <th>Invoice #</th>
                                                <th>Client</th>
                                                <th>Due</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($bSum['outstanding_invoices'], 0, 8) as $oInv):
                                                $oAmt = ((int)($oInv['amount_due'] ?: $oInv['total_cents'])) / 100;
                                                $oPastDue = !empty($oInv['is_past_due']);
                                                $oClientName = trim(($oInv['first_name'] ?? '') . ' ' . ($oInv['last_name'] ?? '')) ?: ($oInv['user_email'] ?: ($oInv['customer_email'] ?: $oInv['customer_id']));
                                            ?>
                                                <tr class="<?php echo $oPastDue ? 'scp-row-past-due' : ''; ?>">
                                                    <td>
                                                        <strong><?php echo SCP_Security::e($oInv['invoice_number']); ?></strong>
                                                        <div class="scp-row-subtext" style="font-size:11px;color:#64748b;">
                                                            <?php echo !empty($oInv['due_date']) ? 'Due ' . SCP_Security::e(date('M j, Y', (int)$oInv['due_date'])) : (!empty($oInv['created_ts']) ? SCP_Security::e(date('M j, Y', (int)$oInv['created_ts'])) : ''); ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($oInv['user_id'])): ?>
                                                            <a href="#" class="scp-client-name-link scp-view-client-billing-btn" data-user-id="<?php echo (int)$oInv['user_id']; ?>" title="View client's full invoice & payment history">
                                                                <?php echo SCP_Security::e($oClientName); ?>
                                                            </a>
                                                        <?php else: ?>
                                                            <?php echo SCP_Security::e($oClientName); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><strong>$<?php echo SCP_Security::e(number_format($oAmt, 2)); ?></strong></td>
                                                    <td>
                                                        <?php if ($oPastDue): ?>
                                                            <span class="scp-status-pill scp-status-past-due">PAST DUE</span>
                                                        <?php else: ?>
                                                            <span class="scp-status-pill scp-status-<?php echo SCP_Security::e($oInv['status']); ?>"><?php echo SCP_Security::e(ucfirst($oInv['status'])); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($oInv['hosted_invoice_url'])): ?>
                                                            <a href="<?php echo SCP_Security::e($oInv['hosted_invoice_url']); ?>" target="_blank" rel="noopener" class="scp-btn scp-btn-xs scp-btn-outline">Invoice &#8599;</a>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Right: New Products Purchased & Recent Payments -->
                        <div style="border:1px solid #e2e8f0;border-radius:10px;padding:16px;background:#f8fafc;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                                <h4 style="margin:0;font-size:15px;color:#0f172a;">New Products Purchased &amp; Recent Payments</h4>
                            </div>
                            <?php if (empty($bSum['recent_purchases'])): ?>
                                <div class="scp-empty-state" style="padding:18px;">
                                    <p>No recent product purchases or payments recorded yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="scp-table-responsive">
                                    <table class="scp-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Client</th>
                                                <th>Product / Payment</th>
                                                <th>Amount</th>
                                                <th>Type</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($bSum['recent_purchases'], 0, 8) as $pur): ?>
                                                <tr>
                                                    <td style="font-size:12px;white-space:nowrap;">
                                                        <?php echo !empty($pur['date_ts']) ? SCP_Security::e(date('M j, Y', (int)$pur['date_ts'])) : '—'; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($pur['user_id'])): ?>
                                                            <a href="#" class="scp-client-name-link scp-view-client-billing-btn" data-user-id="<?php echo (int)$pur['user_id']; ?>" title="View client's full invoice & payment history">
                                                                <?php echo SCP_Security::e($pur['client_name']); ?>
                                                            </a>
                                                        <?php else: ?>
                                                            <?php echo SCP_Security::e($pur['client_name']); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo SCP_Security::e($pur['item_name']); ?></strong>
                                                    </td>
                                                    <td>
                                                        <strong>$<?php echo SCP_Security::e(number_format(((int)$pur['amount_cents']) / 100, 2)); ?></strong>
                                                        <?php if (!empty($pur['interval'])): ?>
                                                            <span style="font-size:11px;color:#64748b;">/<?php echo SCP_Security::e($pur['interval']); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="scp-badge" style="<?php echo $pur['type'] === 'Subscription' ? 'background:#e0f2fe;color:#0369a1;' : 'background:#dcfce7;color:#15803d;'; ?>">
                                                            <?php echo SCP_Security::e($pur['type']); ?>
                                                        </span>
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
            </div>

            <div class="scp-overview-columns">
                <!-- Quick Webhook & API Health -->
                <div class="scp-card">
                    <div class="scp-card-header">
                        <h3>System &amp; Webhook Endpoints</h3>
                        <a href="#" class="scp-link scp-switch-tab" data-target="admin-settings">Edit Settings</a>
                    </div>
                    <div class="scp-card-body">
                        <div class="scp-security-box" style="margin-bottom:16px;">
                            <h4>Primary Stripe Webhook URL</h4>
                            <p>Add this endpoint in your Stripe Dashboard under <strong>Developers &rarr; Webhooks</strong>:</p>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <code style="word-break:break-all;"><?php echo SCP_Security::e($webhookEndpoint); ?></code>
                                <button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-copy-btn" data-copy="<?php echo SCP_Security::e($webhookEndpoint); ?>">
                                    Copy URL
                                </button>
                            </div>
                        </div>

                        <div class="scp-security-box">
                            <h4>Locked Vault Credentials (config/credentials.php)</h4>
                            <ul style="margin:8px 0 0 18px;padding:0;font-size:13px;color:#475569;line-height:1.7;">
                                <li><strong>Stripe Live &amp; Test Keys:</strong> <span class="scp-text-success">&#10003; Loaded from config/credentials.php</span></li>
                                <li><strong>Stripe Webhook Signing Secret:</strong> <span class="scp-text-success">&#10003; Loaded from config/credentials.php</span></li>
                                <li><strong>OpenSRS Live &amp; Test Keys:</strong> <span class="scp-text-success">&#10003; Loaded from config/credentials.php</span></li>
                                <li><strong>Namecheap API Key:</strong> <span class="scp-text-success">&#10003; Loaded from config/credentials.php</span></li>
                                <li><strong>Google reCAPTCHA Enterprise:</strong> <span class="scp-text-success">&#10003; Loaded from config/credentials.php</span></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Recent Audit Activity -->
                <div class="scp-card">
                    <div class="scp-card-header">
                        <h3>Recent Security &amp; Portal Activity</h3>
                        <a href="#" class="scp-link scp-switch-tab" data-target="admin-logs">Full Audit Log</a>
                    </div>
                    <div class="scp-card-body">
                        <?php if (empty($logs)): ?>
                            <div class="scp-empty-state">
                                <p>No activity logged yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="scp-table-responsive">
                                <table class="scp-table">
                                    <thead>
                                        <tr>
                                            <th>Event</th>
                                            <th>User</th>
                                            <th>Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($logs, 0, 6) as $log): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo SCP_Security::e($log['event_type']); ?></strong>
                                                    <div style="font-size:12px;color:#64748b;"><?php echo SCP_Security::e($log['description']); ?></div>
                                                </td>
                                                <td><?php echo SCP_Security::e($log['user_email'] ?: 'System / Webhook'); ?></td>
                                                <td style="white-space:nowrap;font-size:12px;"><?php echo SCP_Security::e(date('M j, g:i a', strtotime($log['created_at']))); ?></td>
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

        <!-- ADMIN TAB 2: CLIENTS & STRIPE SYNC -->
        <section class="scp-tab-panel" id="scp-tab-admin-clients">
            <!-- Master Stripe Two-Way Import & Synchronization Card -->
            <div class="scp-card scp-mb-24" style="border-left:4px solid #0284c7;">
                <div class="scp-card-header">
                    <div>
                        <h3>Master Stripe Import &amp; Two-Way Synchronization</h3>
                        <p class="scp-card-sub">
                            Import all existing Stripe Products, Customers, Subscriptions, Invoices, Payments, and Quotes into the portal database, and automatically push any unlinked portal clients to Stripe.
                            <span style="margin-left:8px;color:#0284c7;font-weight:600;">Last Full Sync: <?php echo SCP_Security::e($lastFullStripeSync); ?></span>
                        </p>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <button type="button" class="scp-btn scp-btn-primary scp-admin-import-all-stripe-btn">
                            &#8644; Import &amp; Sync All Stripe Data Now
                        </button>
                    </div>
                </div>
                <div class="scp-card-body">
                    <div class="scp-stats-grid" style="margin-bottom:0;">
                        <div class="scp-stat-card" style="padding:14px 16px;">
                            <div class="scp-stat-label">Synced Accounts</div>
                            <div class="scp-stat-value" style="font-size:22px;"><?php echo (int)$totalUsersCount; ?></div>
                        </div>
                        <div class="scp-stat-card" style="padding:14px 16px;">
                            <div class="scp-stat-label">Imported Products &amp; Prices</div>
                            <div class="scp-stat-value" style="font-size:22px;"><?php echo count($importedProducts); ?></div>
                        </div>
                        <div class="scp-stat-card" style="padding:14px 16px;">
                            <div class="scp-stat-label">Imported Subscriptions</div>
                            <div class="scp-stat-value" style="font-size:22px;"><?php echo count($importedSubscriptions); ?></div>
                        </div>
                        <div class="scp-stat-card" style="padding:14px 16px;">
                            <div class="scp-stat-label">Imported Invoices &amp; Payments</div>
                            <div class="scp-stat-value" style="font-size:22px;"><?php echo count($importedInvoices) + count($importedPayments); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Client & Administrator Accounts -->
            <div class="scp-card scp-mb-24">
                <div class="scp-card-header">
                    <div>
                        <h3>Client &amp; Administrator Accounts</h3>
                        <p class="scp-card-sub">Click any client's name to view their complete invoice history, payment history, and outstanding balances. Adding a new client automatically creates them in Stripe.</p>
                    </div>
                    <button type="button" class="scp-btn scp-btn-primary scp-open-create-user-modal">
                        + Create New Client (Auto-Adds to Stripe)
                    </button>
                </div>
                <div class="scp-card-body">
                    <div class="scp-table-responsive">
                        <table class="scp-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name &amp; Company (Click for Billing History)</th>
                                    <th>Email &amp; Phone</th>
                                    <th>Role</th>
                                    <th>Stripe Customer ID</th>
                                    <th>Last Synced</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Build lookup maps of clients who owe money on open / past-due / uncollectible invoices
                                $outstandingByUserId     = array();
                                $outstandingByCustomerId = array();
                                $outstandingByEmail      = array();

                                foreach ($importedInvoices as $invRow) {
                                    $invStatus = strtolower((string)($invRow['status'] ?? ''));
                                    if (!in_array($invStatus, array('open', 'past_due', 'uncollectible'), true)) {
                                        continue;
                                    }
                                    $amtDue  = (int)($invRow['amount_due'] ?? 0);
                                    $amtPaid = (int)($invRow['amount_paid'] ?? 0);
                                    $unpaid  = max(0, $amtDue - $amtPaid);
                                    if ($unpaid === 0) {
                                        $unpaid = (int)($invRow['total_cents'] ?? $amtDue);
                                    }
                                    if ($unpaid <= 0) {
                                        continue;
                                    }

                                    $iUid   = (int)($invRow['user_id'] ?? 0);
                                    $iCid   = trim((string)($invRow['customer_id'] ?? ''));
                                    $iEmail = strtolower(trim((string)($invRow['customer_email'] ?? ($invRow['user_email'] ?? ''))));

                                    if ($iUid > 0) {
                                        $outstandingByUserId[$iUid] = ($outstandingByUserId[$iUid] ?? 0) + $unpaid;
                                    }
                                    if ($iCid !== '') {
                                        $outstandingByCustomerId[$iCid] = ($outstandingByCustomerId[$iCid] ?? 0) + $unpaid;
                                    }
                                    if ($iEmail !== '') {
                                        $outstandingByEmail[$iEmail] = ($outstandingByEmail[$iEmail] ?? 0) + $unpaid;
                                    }
                                }

                                foreach ($allUsers as $u):
                                    $uId    = (int)$u['id'];
                                    $uName  = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['display_name'] ?? $u['email']);
                                    $uCid   = trim((string)($u['stripe_customer_id'] ?? ''));
                                    $uEmail = strtolower(trim((string)($u['email'] ?? '')));

                                    $owedCents = 0;
                                    if (!empty($outstandingByUserId[$uId])) {
                                        $owedCents = $outstandingByUserId[$uId];
                                    } elseif ($uCid !== '' && !empty($outstandingByCustomerId[$uCid])) {
                                        $owedCents = $outstandingByCustomerId[$uCid];
                                    } elseif ($uEmail !== '' && !empty($outstandingByEmail[$uEmail])) {
                                        $owedCents = $outstandingByEmail[$uEmail];
                                    }
                                    $clientOwesMoney = ($owedCents > 0);
                                ?>
                                    <tr>
                                        <td>#<?php echo $uId; ?></td>
                                        <td>
                                            <a href="#"
                                                class="scp-client-name-link scp-view-client-billing-btn"
                                                data-user-id="<?php echo $uId; ?>"
                                                title="Click to inspect <?php echo SCP_Security::e($uName); ?>'s invoices, payments &amp; outstanding balance">
                                                <strong><?php echo SCP_Security::e($uName); ?></strong> &#128269;
                                            </a>
                                            <?php if (!empty($u['company'])): ?>
                                                <div style="font-size:12px;color:#64748b;"><?php echo SCP_Security::e($u['company']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div>
                                                <a href="#"
                                                    class="scp-email-client-link scp-open-compose-email"
                                                    data-to-email="<?php echo SCP_Security::e($u['email']); ?>"
                                                    data-user-id="<?php echo $uId; ?>"
                                                    data-attention="<?php echo SCP_Security::e($uName); ?>"
                                                    title="Send an email to <?php echo SCP_Security::e($uName); ?>">
                                                    &#9993; <?php echo SCP_Security::e($u['email']); ?>
                                                </a>
                                            </div>
                                            <?php if (!empty($u['phone'])): ?>
                                                <div style="font-size:12px;color:#64748b;"><?php echo SCP_Security::e($u['phone']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="scp-badge <?php echo ($u['role'] ?? '') === 'admin' ? 'scp-badge-warning' : ''; ?>">
                                                <?php echo SCP_Security::e(strtoupper($u['role'] ?? 'client')); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($u['stripe_customer_id'])): ?>
                                                <code><?php echo SCP_Security::e($u['stripe_customer_id']); ?></code>
                                            <?php else: ?>
                                                <span class="scp-text-muted">Not linked</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:12px;">
                                            <?php echo !empty($u['stripe_last_synced']) ? SCP_Security::e(date('M j, Y g:i a', (int)$u['stripe_last_synced'])) : 'Never'; ?>
                                        </td>
                                        <td>
                                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                                <button type="button"
                                                    class="scp-btn scp-btn-xs <?php echo $clientOwesMoney ? 'scp-btn-outstanding-red' : 'scp-btn-primary'; ?> scp-view-client-billing-btn"
                                                    data-user-id="<?php echo $uId; ?>"
                                                    title="<?php echo $clientOwesMoney ? 'Outstanding Balance Owed: $' . SCP_Security::e(number_format($owedCents / 100, 2)) : 'View client billing &amp; invoice history'; ?>">
                                                    &#128179; Billing &amp; Invoices
                                                </button>
                                                <button type="button"
                                                    class="scp-btn scp-btn-xs scp-btn-outline scp-admin-sync-client-btn"
                                                    data-user-id="<?php echo $uId; ?>">
                                                    &#8635; Sync Stripe
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Imported Stripe Products & Prices Catalog -->
            <div class="scp-card scp-mb-24">
                <div class="scp-card-header">
                    <div>
                        <h3>Imported Stripe Products &amp; Prices (<?php echo count($importedProducts); ?>)</h3>
                        <p class="scp-card-sub">Active products and recurring/one-time price tiers imported from your Stripe account.</p>
                    </div>
                    <button type="button" class="scp-btn scp-btn-primary scp-switch-tab" data-target="admin-products">
                        &#128230; Manage Categories &amp; Product On/Off Toggles &rarr;
                    </button>
                </div>
                <div class="scp-card-body">
                    <?php if (empty($importedProducts)): ?>
                        <div class="scp-empty-state">
                            <p>No active Stripe products imported yet. Click <strong>Import &amp; Sync All Stripe Data Now</strong> above to import your catalog.</p>
                        </div>
                    <?php else: ?>
                        <div class="scp-table-responsive">
                            <table class="scp-table">
                                <thead>
                                    <tr>
                                        <th>Website Status</th>
                                        <th>Product Name</th>
                                        <th>Categories</th>
                                        <th>Price ID</th>
                                        <th>Billing Type</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($importedProducts as $prod):
                                        $isVis = !empty($prod['portal_visible']);
                                    ?>
                                        <tr>
                                            <td>
                                                <span class="scp-status-pill <?php echo $isVis ? 'scp-status-active' : 'scp-status-canceled'; ?>">
                                                    <?php echo $isVis ? 'ON (Visible)' : 'OFF (Hidden)'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong><?php echo SCP_Security::e($prod['name']); ?></strong>
                                                <?php if (!empty($prod['description'])): ?>
                                                    <div style="max-width:260px;font-size:12px;color:#64748b;"><?php echo SCP_Security::e($prod['description']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($prod['categories'])): ?>
                                                    <div style="display:flex;flex-wrap:wrap;gap:4px;">
                                                        <?php foreach ($prod['categories'] as $c): ?>
                                                            <span class="scp-badge" style="background:#e0f2fe;color:#0369a1;"><?php echo SCP_Security::e($c['name']); ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="scp-text-muted" style="font-size:12px;">Uncategorized</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><code><?php echo SCP_Security::e($prod['price_id']); ?></code></td>
                                            <td>
                                                <span class="scp-badge">
                                                    <?php echo !empty($prod['is_recurring']) ? 'Recurring (' . SCP_Security::e($prod['interval_unit']) . ')' : 'One-Time'; ?>
                                                </span>
                                            </td>
                                            <td><strong>$<?php echo SCP_Security::e(number_format(((int)$prod['unit_amount']) / 100, 2)); ?> <?php echo SCP_Security::e($prod['currency']); ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Imported Stripe Subscriptions & Quotes -->
            <div class="scp-overview-columns scp-mb-24">
                <div class="scp-card" style="margin-bottom:0;">
                    <div class="scp-card-header">
                        <div>
                            <h3>Imported Subscriptions (<?php echo count($importedSubscriptions); ?>)</h3>
                            <p class="scp-card-sub">Active and past Stripe subscriptions across all clients.</p>
                        </div>
                    </div>
                    <div class="scp-card-body">
                        <?php if (empty($importedSubscriptions)): ?>
                            <div class="scp-empty-state"><p>No Stripe subscriptions found.</p></div>
                        <?php else: ?>
                            <div class="scp-table-responsive">
                                <table class="scp-table">
                                    <thead>
                                        <tr>
                                            <th>Client</th>
                                            <th>Plan</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Renews</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($importedSubscriptions, 0, 25) as $sub): ?>
                                            <tr>
                                                <td>
                                                    <?php if (!empty($sub['user_id'])): ?>
                                                        <a href="#" class="scp-client-name-link scp-view-client-billing-btn" data-user-id="<?php echo (int)$sub['user_id']; ?>">
                                                            <?php echo SCP_Security::e($sub['user_email'] ?: $sub['customer_id']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <div><?php echo SCP_Security::e($sub['user_email'] ?: $sub['customer_id']); ?></div>
                                                    <?php endif; ?>
                                                    <div style="font-size:11px;color:#64748b;"><code><?php echo SCP_Security::e($sub['subscription_id']); ?></code></div>
                                                </td>
                                                <td><strong><?php echo SCP_Security::e($sub['plan_name']); ?></strong></td>
                                                <td>$<?php echo SCP_Security::e(number_format(((int)$sub['amount_cents']) / 100, 2)); ?>/<?php echo SCP_Security::e($sub['interval_unit']); ?></td>
                                                <td><span class="scp-status-pill scp-status-<?php echo SCP_Security::e($sub['status']); ?>"><?php echo SCP_Security::e(ucfirst($sub['status'])); ?></span></td>
                                                <td style="font-size:12px;"><?php echo !empty($sub['current_period_end']) ? SCP_Security::e(date('M j, Y', (int)$sub['current_period_end'])) : '—'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="scp-card" style="margin-bottom:0;">
                    <div class="scp-card-header">
                        <div>
                            <h3>Imported Quotes (<?php echo count($importedQuotes); ?>)</h3>
                            <p class="scp-card-sub">Custom service quotes and proposals imported from Stripe. Quotes past their acceptance date are highlighted in dark red.</p>
                        </div>
                    </div>
                    <div class="scp-card-body">
                        <?php if (empty($importedQuotes)): ?>
                            <div class="scp-empty-state"><p>No Stripe quotes found.</p></div>
                        <?php else: ?>
                            <div class="scp-table-responsive">
                                <table class="scp-table">
                                    <thead>
                                        <tr>
                                            <th>Quote #</th>
                                            <th>Client</th>
                                            <th>Total</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($importedQuotes, 0, 25) as $qt):
                                            $qtPastDue = SCP_StripeService::isQuotePastDue($qt);
                                        ?>
                                            <tr class="<?php echo $qtPastDue ? 'scp-row-past-due' : ''; ?>">
                                                <td>
                                                    <strong><?php echo SCP_Security::e($qt['quote_number']); ?></strong>
                                                    <?php if (!empty($qt['expires_at'])): ?>
                                                        <div class="scp-row-subtext" style="font-size:11px;color:#64748b;">
                                                            Due: <?php echo SCP_Security::e(date('M j, Y', (int)$qt['expires_at'])); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($qt['user_id'])): ?>
                                                        <a href="#" class="scp-client-name-link scp-view-client-billing-btn" data-user-id="<?php echo (int)$qt['user_id']; ?>">
                                                            <?php echo SCP_Security::e($qt['user_email'] ?: $qt['customer_id']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <?php echo SCP_Security::e($qt['user_email'] ?: $qt['customer_id']); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>$<?php echo SCP_Security::e(number_format(((int)$qt['amount_total']) / 100, 2)); ?></td>
                                                <td>
                                                    <?php if ($qtPastDue): ?>
                                                        <span class="scp-status-pill scp-status-past-due">PAST DUE DATE</span>
                                                    <?php else: ?>
                                                        <span class="scp-status-pill scp-status-<?php echo SCP_Security::e($qt['status']); ?>"><?php echo SCP_Security::e(ucfirst($qt['status'])); ?></span>
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

            <!-- Imported Stripe Invoices & Payments -->
            <div class="scp-overview-columns">
                <div class="scp-card" style="margin-bottom:0;">
                    <div class="scp-card-header">
                        <div>
                            <h3>Imported Stripe Invoices (<?php echo count($importedInvoices); ?>)</h3>
                            <p class="scp-card-sub">All client invoices synced from Stripe. Past-due invoices are highlighted in dark red.</p>
                        </div>
                    </div>
                    <div class="scp-card-body">
                        <?php if (empty($importedInvoices)): ?>
                            <div class="scp-empty-state"><p>No Stripe invoices found.</p></div>
                        <?php else: ?>
                            <div class="scp-table-responsive">
                                <table class="scp-table">
                                    <thead>
                                        <tr>
                                            <th>Invoice #</th>
                                            <th>Client</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Link</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($importedInvoices, 0, 25) as $inv):
                                            $invAmt = ((int)($inv['amount_due'] ?: $inv['total_cents'])) / 100;
                                            $invPastDue = SCP_StripeService::isInvoicePastDue($inv);
                                        ?>
                                            <tr class="<?php echo $invPastDue ? 'scp-row-past-due' : ''; ?>">
                                                <td>
                                                    <strong><?php echo SCP_Security::e($inv['invoice_number']); ?></strong>
                                                    <div class="scp-row-subtext" style="font-size:11px;color:#64748b;">
                                                        <?php echo !empty($inv['due_date']) ? 'Due ' . SCP_Security::e(date('M j, Y', (int)$inv['due_date'])) : (!empty($inv['created_ts']) ? SCP_Security::e(date('M j, Y', (int)$inv['created_ts'])) : ''); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (!empty($inv['user_id'])): ?>
                                                        <a href="#" class="scp-client-name-link scp-view-client-billing-btn" data-user-id="<?php echo (int)$inv['user_id']; ?>">
                                                            <?php echo SCP_Security::e($inv['user_email'] ?: ($inv['customer_email'] ?: $inv['customer_id'])); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <?php echo SCP_Security::e($inv['user_email'] ?: ($inv['customer_email'] ?: $inv['customer_id'])); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><strong>$<?php echo SCP_Security::e(number_format($invAmt, 2)); ?></strong></td>
                                                <td>
                                                    <?php if ($invPastDue): ?>
                                                        <span class="scp-status-pill scp-status-past-due">PAST DUE</span>
                                                    <?php else: ?>
                                                        <span class="scp-status-pill scp-status-<?php echo SCP_Security::e($inv['status']); ?>"><?php echo SCP_Security::e(ucfirst($inv['status'])); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($inv['hosted_invoice_url'])): ?>
                                                        <a href="<?php echo SCP_Security::e($inv['hosted_invoice_url']); ?>" target="_blank" rel="noopener" class="scp-btn scp-btn-xs scp-btn-outline">Invoice &#8599;</a>
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

                <div class="scp-card" style="margin-bottom:0;">
                    <div class="scp-card-header">
                        <div>
                            <h3>Imported Stripe Payments (<?php echo count($importedPayments); ?>)</h3>
                            <p class="scp-card-sub">Completed charges, card payments, and receipts imported from Stripe.</p>
                        </div>
                    </div>
                    <div class="scp-card-body">
                        <?php if (empty($importedPayments)): ?>
                            <div class="scp-empty-state"><p>No Stripe payments found.</p></div>
                        <?php else: ?>
                            <div class="scp-table-responsive">
                                <table class="scp-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Client</th>
                                            <th>Amount &amp; Card</th>
                                            <th>Status</th>
                                            <th>Receipt</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($importedPayments, 0, 25) as $pay): ?>
                                            <tr>
                                                <td style="font-size:12px;white-space:nowrap;">
                                                    <?php echo !empty($pay['created_ts']) ? SCP_Security::e(date('M j, Y', (int)$pay['created_ts'])) : '—'; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($pay['user_id'])): ?>
                                                        <a href="#" class="scp-client-name-link scp-view-client-billing-btn" data-user-id="<?php echo (int)$pay['user_id']; ?>">
                                                            <?php echo SCP_Security::e($pay['user_email'] ?: ($pay['customer_email'] ?: ($pay['customer_id'] ?: 'Guest'))); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <div><?php echo SCP_Security::e($pay['user_email'] ?: ($pay['customer_email'] ?: ($pay['customer_id'] ?: 'Guest'))); ?></div>
                                                    <?php endif; ?>
                                                    <div style="font-size:11px;color:#64748b;"><?php echo SCP_Security::e($pay['description']); ?></div>
                                                </td>
                                                <td>
                                                    <strong>$<?php echo SCP_Security::e(number_format(((int)$pay['amount_cents']) / 100, 2)); ?> <?php echo SCP_Security::e($pay['currency']); ?></strong>
                                                    <?php if (!empty($pay['card_last4'])): ?>
                                                        <div style="font-size:11px;color:#64748b;"><?php echo SCP_Security::e($pay['card_brand']); ?> &bull;&bull;&bull;&bull; <?php echo SCP_Security::e($pay['card_last4']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="scp-status-pill scp-status-<?php echo !empty($pay['refunded']) ? 'canceled' : ($pay['status'] === 'succeeded' ? 'active' : 'open'); ?>">
                                                        <?php echo !empty($pay['refunded']) ? 'Refunded' : SCP_Security::e(ucfirst($pay['status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($pay['receipt_url'])): ?>
                                                        <a href="<?php echo SCP_Security::e($pay['receipt_url']); ?>" target="_blank" rel="noopener" class="scp-btn scp-btn-xs scp-btn-outline">Receipt &#8599;</a>
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

        <!-- ADMIN TAB 2B: PRODUCTS, CATEGORIES & VISIBILITY MANAGER -->
        <section class="scp-tab-panel" id="scp-tab-admin-products">
            <div class="scp-overview-columns scp-mb-24">
                <!-- Create / Edit Product Category Form -->
                <div class="scp-card" style="margin-bottom:0;">
                    <div class="scp-card-header">
                        <div>
                            <h3 id="scp-category-form-title">Create New Product Category</h3>
                            <p class="scp-card-sub">Categories organize products on your front-end pricing page with headings, descriptions, and quick-jump navigation links.</p>
                        </div>
                    </div>
                    <div class="scp-card-body">
                        <form id="scp-admin-category-form" class="scp-form">
                            <input type="hidden" name="category_id" id="scp-cat-id-input" value="0" />
                            <input type="hidden" name="assign_price_id" id="scp-cat-assign-price-input" value="" />

                            <div class="scp-form-row">
                                <div class="scp-form-group" style="flex:2;">
                                    <label>Category Heading / Name *</label>
                                    <input type="text" name="name" id="scp-cat-name-input" placeholder="e.g., Managed WordPress Hosting" required />
                                </div>
                                <div class="scp-form-group" style="flex:1;">
                                    <label>Display Sort Order</label>
                                    <input type="number" name="sort_order" id="scp-cat-order-input" value="10" step="1" />
                                </div>
                            </div>

                            <div class="scp-form-group">
                                <label>Category Description (Displayed under the Category Heading on the Website)</label>
                                <textarea name="description" id="scp-cat-desc-input" rows="3" placeholder="e.g., High-performance NVMe WordPress cloud plans with automated daily backups, free SSL, and 1-Click WP-Admin SSO."></textarea>
                            </div>

                            <div class="scp-card-divider" style="margin:16px 0;border-top:1px dashed #e2e8f0;"></div>

                            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;flex-wrap:wrap;">
                                <div>
                                    <strong style="font-size:13px;color:#0f172a;">Bottom Information / Notes Callout (Optional)</strong>
                                    <p class="scp-card-sub" style="margin-top:2px;font-size:12px;">
                                        Displays one or more informational callout boxes at the bottom of this category container in the products section.
                                    </p>
                                </div>
                                <button type="button" id="scp-add-cat-note-btn" class="scp-btn scp-btn-xs scp-btn-outline">
                                    + Add Callout Section
                                </button>
                            </div>

                            <input type="hidden" name="bottom_notes_json" id="scp-cat-notes-json-input" value="[]" />

                            <div id="scp-cat-notes-list" class="scp-cat-notes-builder-list">
                                <div class="scp-cat-note-item-card" data-note-index="0">
                                    <div class="scp-cat-note-item-header">
                                        <span class="scp-cat-note-item-title">Callout Section #1</span>
                                        <div style="display:flex;align-items:center;gap:6px;">
                                            <button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-move-cat-note-up-btn" title="Move Callout Section Up">&uarr;</button>
                                            <button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-move-cat-note-down-btn" title="Move Callout Section Down">&darr;</button>
                                            <button type="button" class="scp-btn scp-btn-xs scp-btn-danger scp-remove-cat-note-btn" title="Remove this callout section">
                                                &times; Remove
                                            </button>
                                        </div>
                                    </div>
                                    <div class="scp-form-row">
                                        <div class="scp-form-group" style="flex:1.2;">
                                            <label>Callout Icon</label>
                                            <select class="scp-select scp-cat-note-icon-field">
                                                <option value="green-exclamation">&#9888; Green Exclamation (!)</option>
                                                <option value="red-exclamation">&#9888; Red Exclamation (!)</option>
                                                <option value="dark-grey-asterisk">&#10033; Dark Grey Asterisk (*)</option>
                                                <option value="green-checkmark">&#10004; Green Checkmark (&#10003;)</option>
                                                <option value="red-x">&#10006; Red X (&#10005;)</option>
                                                <option value="none">No Icon (Text Only)</option>
                                            </select>
                                        </div>
                                        <div class="scp-form-group" style="flex:2;">
                                            <label>Callout Heading / Prefix (Bold Title)</label>
                                            <input type="text" class="scp-cat-note-title-field" placeholder="e.g., All plans include:" />
                                        </div>
                                    </div>
                                    <div class="scp-form-group" style="margin-bottom:0;">
                                        <label>Callout Note Text / Details</label>
                                        <textarea class="scp-cat-note-text-field" rows="2" placeholder="e.g., 99.99% Uptime SLA, Weekly Website Backup, AI Site Creation, Multi-site Management, Plugins Management, Yoast SEO..."></textarea>
                                    </div>
                                </div>
                            </div>

                            <div id="scp-cat-note-preview-wrap" style="display:none;margin-top:12px;margin-bottom:16px;">
                                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;margin-bottom:6px;">Live Banner Preview:</div>
                                <div id="scp-cat-note-preview-list"></div>
                            </div>

                            <div style="display:flex;gap:10px;align-items:center;">
                                <button type="submit" id="scp-save-category-btn" class="scp-btn scp-btn-primary">
                                    + Save Category
                                </button>
                                <button type="button" id="scp-cancel-category-edit-btn" class="scp-btn scp-btn-outline" style="display:none;">
                                    Cancel Edit
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Existing Categories List -->
                <div class="scp-card" style="margin-bottom:0;">
                    <div class="scp-card-header">
                        <div>
                            <h3>Saved Product Categories (<?php echo count($productCategories); ?>)</h3>
                            <p class="scp-card-sub">Edit category headings, descriptions, or display order for the front-end catalog.</p>
                        </div>
                    </div>
                    <div class="scp-card-body">
                        <?php if (empty($productCategories)): ?>
                            <div class="scp-empty-state">
                                <p>No product categories created yet. Add your first category on the left or click <strong>+ New Category</strong> on any product below.</p>
                            </div>
                        <?php else: ?>
                            <div class="scp-table-responsive">
                                <table class="scp-table">
                                    <thead>
                                        <tr>
                                            <th>Order</th>
                                            <th>Category Heading &amp; Description</th>
                                            <th>Products</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($productCategories as $cat):
                                            $catNotes = $cat['bottom_notes'] ?? array();
                                        ?>
                                            <tr>
                                                <td><code><?php echo (int)$cat['sort_order']; ?></code></td>
                                                <td>
                                                    <strong><?php echo SCP_Security::e($cat['name']); ?></strong>
                                                    <?php if (!empty($cat['description'])): ?>
                                                        <div style="font-size:12px;color:#64748b;margin-top:2px;"><?php echo SCP_Security::e($cat['description']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($catNotes)): ?>
                                                        <div style="display:flex;flex-direction:column;gap:4px;margin-top:5px;">
                                                            <?php foreach ($catNotes as $cn): ?>
                                                                <div style="display:inline-flex;align-items:center;gap:6px;font-size:11px;color:#0369a1;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:3px 8px;max-width:100%;box-sizing:border-box;width:fit-content;">
                                                                    <span style="display:inline-flex;width:14px;height:14px;flex-shrink:0;"><?php echo SCP_Security::renderCategoryNoteIcon($cn['icon'] ?? 'green-exclamation'); ?></span>
                                                                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                                                        <strong><?php echo SCP_Security::e($cn['title'] ?? ''); ?></strong>
                                                                        <?php echo SCP_Security::e(mb_strimwidth($cn['text'] ?? '', 0, 48, '...')); ?>
                                                                    </span>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div style="font-size:11px;color:#94a3b8;margin-top:2px;">Quick Link: <code>#pricing-cat-<?php echo SCP_Security::e($cat['slug']); ?></code></div>
                                                </td>
                                                <td>
                                                    <span class="scp-badge"><?php echo (int)($cat['product_count'] ?? 0); ?> assigned</span>
                                                </td>
                                                <td style="white-space:nowrap;">
                                                    <button type="button"
                                                        class="scp-btn scp-btn-xs scp-btn-outline scp-edit-category-btn"
                                                        data-id="<?php echo (int)$cat['id']; ?>"
                                                        data-name="<?php echo SCP_Security::e($cat['name']); ?>"
                                                        data-desc="<?php echo SCP_Security::e($cat['description'] ?? ''); ?>"
                                                        data-order="<?php echo (int)$cat['sort_order']; ?>"
                                                        data-notes="<?php echo SCP_Security::e(json_encode($catNotes)); ?>"
                                                        data-note-icon="<?php echo SCP_Security::e($cat['bottom_note_icon'] ?? 'green-exclamation'); ?>"
                                                        data-note-title="<?php echo SCP_Security::e($cat['bottom_note_title'] ?? ''); ?>"
                                                        data-note-text="<?php echo SCP_Security::e($cat['bottom_note_text'] ?? ''); ?>">
                                                        Edit
                                                    </button>
                                                    <button type="button"
                                                        class="scp-btn scp-btn-xs scp-btn-danger scp-delete-category-btn"
                                                        data-id="<?php echo (int)$cat['id']; ?>"
                                                        data-name="<?php echo SCP_Security::e($cat['name']); ?>">
                                                        Delete
                                                    </button>
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

            <!-- Custom Product Builder & Catalog Table: Visibility Toggle + Multi-Term + Features + Category Selector -->
            <?php
            $adminCatalogList = $catalogProducts ?? array();
            ?>
            <div class="scp-card">
                <div class="scp-card-header" style="flex-wrap:wrap;gap:14px;">
                    <div>
                        <h3>Custom Product Catalog, Multi-Term Pricing &amp; Category Manager (<?php echo count($adminCatalogList); ?>)</h3>
                        <p class="scp-card-sub">
                            Create and manage products with custom descriptions, super titles, featured highlighting, multi-term or one-time pricing, Stripe checkout hooks/links, and grouped feature lists.
                        </p>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <button type="button" class="scp-btn scp-btn-primary scp-open-catalog-product-modal" data-product="">
                            + Create New Product
                        </button>
                        <button type="button" class="scp-btn scp-btn-outline scp-import-stripe-to-catalog-btn" title="Copy any unlisted Stripe products into this custom catalog">
                            &#8644; Import Unlisted from Stripe
                        </button>
                        <input type="text" id="scp-admin-product-search" class="scp-input" placeholder="Search products..." style="width:190px;padding:7px 12px;font-size:13px;" />
                        <select id="scp-admin-product-filter" class="scp-input" style="width:auto;padding:7px 12px;font-size:13px;">
                            <option value="all">Show: All Products</option>
                            <option value="visible">Visible on Website (ON)</option>
                            <option value="hidden">Hidden from Website (OFF)</option>
                            <option value="featured">Featured Products Only</option>
                            <option value="uncategorized">Uncategorized Only</option>
                        </select>
                    </div>
                </div>
                <div class="scp-card-body">
                    <?php if (empty($adminCatalogList)): ?>
                        <div class="scp-empty-state">
                            <p>No products in your custom catalog yet. Click <strong>+ Create New Product</strong> above to build a product, or click <strong>&#8644; Import Unlisted from Stripe</strong>.</p>
                        </div>
                    <?php else: ?>
                        <div class="scp-table-responsive">
                            <table class="scp-table" id="scp-admin-products-table">
                                <thead>
                                    <tr>
                                        <th style="width:140px;">Website Display</th>
                                        <th>Product, Super Title &amp; Features</th>
                                        <th>Pricing, Terms &amp; Stripe Hook</th>
                                        <th style="min-width:290px;">Assigned Categories (Click to Toggle or + New)</th>
                                        <th style="width:150px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($adminCatalogList as $prod):
                                        $prodId       = (int)$prod['id'];
                                        $isVisible    = !empty($prod['portal_visible']);
                                        $isFeatured   = !empty($prod['is_featured']);
                                        $superTitle   = trim((string)($prod['super_title'] ?? ''));
                                        $hasTerms     = !empty($prod['has_terms']) && !empty($prod['terms']);
                                        $termsArr     = $prod['terms'] ?? array();
                                        $featsArr     = $prod['features'] ?? array();
                                        $assignedIds  = $prod['category_ids'] ?? array();
                                        $hasCat       = !empty($assignedIds);
                                    ?>
                                        <tr class="scp-admin-product-row <?php echo !$isVisible ? 'scp-product-row-off' : ''; ?>"
                                            data-visible="<?php echo $isVisible ? '1' : '0'; ?>"
                                            data-featured="<?php echo $isFeatured ? '1' : '0'; ?>"
                                            data-categorized="<?php echo $hasCat ? '1' : '0'; ?>"
                                            data-search="<?php echo SCP_Security::e(strtolower($prod['name'] . ' ' . $superTitle . ' ' . ($prod['description'] ?? '') . ' ' . ($prod['stripe_hook'] ?? ''))); ?>">
                                            <td>
                                                <div style="display:flex;align-items:center;gap:10px;">
                                                    <label class="scp-toggle-switch" title="Toggle product ON or OFF on the website &amp; client portal">
                                                        <input type="checkbox"
                                                            class="scp-product-visibility-toggle"
                                                            data-price-id="<?php echo $prodId; ?>"
                                                            <?php echo $isVisible ? 'checked' : ''; ?> />
                                                        <span class="scp-toggle-slider"></span>
                                                    </label>
                                                    <span class="scp-vis-label scp-status-pill <?php echo $isVisible ? 'scp-status-active' : 'scp-status-canceled'; ?>">
                                                        <?php echo $isVisible ? 'ON' : 'OFF'; ?>
                                                    </span>
                                                </div>
                                                <?php if ($isFeatured): ?>
                                                    <div style="margin-top:6px;">
                                                        <span class="scp-badge" style="background:#2563eb;color:#ffffff;font-weight:700;font-size:10.5px;">&#9733; FEATURED</span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($superTitle !== ''): ?>
                                                    <div style="margin-bottom:4px;">
                                                        <span class="scp-badge" style="background:<?php echo $isFeatured ? '#dbeafe' : '#f1f5f9'; ?>;color:<?php echo $isFeatured ? '#1d4ed8' : '#475569'; ?>;font-size:10.5px;font-weight:700;text-transform:uppercase;">
                                                            <?php echo SCP_Security::e($superTitle); ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                                <strong style="font-size:15px;color:#0f172a;"><?php echo SCP_Security::e($prod['name']); ?></strong>
                                                <?php if (!empty($prod['description'])): ?>
                                                    <div style="font-size:12.5px;color:#64748b;margin-top:3px;max-width:340px;"><?php echo SCP_Security::e($prod['description']); ?></div>
                                                <?php endif; ?>
                                                <div style="font-size:11.5px;color:#0284c7;margin-top:5px;font-weight:600;">
                                                    <?php echo count($featsArr); ?> Feature<?php echo count($featsArr) === 1 ? '' : 's'; ?>
                                                    <?php if (!empty($featsArr)): ?>
                                                        &bull; Headings: <?php echo !empty($prod['use_feature_headings']) ? 'ON' : 'OFF'; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($hasTerms): ?>
                                                    <span class="scp-badge" style="background:#e0f2fe;color:#0369a1;margin-bottom:6px;display:inline-block;">
                                                        Multi-Term (<?php echo count($termsArr); ?> terms)
                                                    </span>
                                                    <div style="display:flex;flex-direction:column;gap:5px;">
                                                        <?php foreach ($termsArr as $tIdx => $t): ?>
                                                            <div style="font-size:12px;color:#334155;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                                                <span>
                                                                    <strong><?php echo SCP_Security::e($t['term_name']); ?>:</strong>
                                                                    $<?php echo SCP_Security::e($t['price_amount']); ?><?php echo SCP_Security::e($t['price_unit']); ?>
                                                                </span>
                                                                <?php if (!empty($t['save_badge_text'])): ?>
                                                                    <span style="background:#059669;color:#fff;padding:1px 6px;border-radius:4px;font-size:10.5px;font-weight:700;">
                                                                        <?php echo SCP_Security::e($t['save_badge_text']); ?>
                                                                    </span>
                                                                <?php endif; ?>
                                                                <?php if (count($termsArr) > 1): ?>
                                                                    <button type="button"
                                                                            class="scp-btn scp-btn-xs scp-btn-outline scp-table-split-term-btn"
                                                                            data-product-id="<?php echo $prodId; ?>"
                                                                            data-term-index="<?php echo (int)$tIdx; ?>"
                                                                            style="padding:1px 7px;font-size:10.5px;border-color:#7dd3fc;color:#0369a1;background:#f0f9ff;"
                                                                            title="Copy all product information to a new product listing and move this price/term to the new product">
                                                                        &#10697; Move to New Product
                                                                    </button>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div style="font-size:15px;font-weight:700;color:#0f172a;">
                                                        $<?php echo SCP_Security::e($prod['price_amount']); ?><?php echo SCP_Security::e($prod['price_unit']); ?>
                                                        <?php if (!empty($prod['save_badge_text'])): ?>
                                                            <span style="background:#059669;color:#fff;padding:2px 6px;border-radius:4px;font-size:10.5px;font-weight:700;margin-left:4px;">
                                                                <?php echo SCP_Security::e($prod['save_badge_text']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="scp-badge" style="margin-top:4px;display:inline-block;">
                                                        <?php echo ($prod['billing_mode'] ?? '') === 'one_time' ? 'One-Time Charge' : 'Single Term / Recurring'; ?>
                                                    </span>
                                                    <?php if (!empty($prod['stripe_hook'])): ?>
                                                        <div style="font-size:11px;color:#64748b;margin-top:4px;max-width:210px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo SCP_Security::e($prod['stripe_hook']); ?>">
                                                            Hook: <code><?php echo SCP_Security::e($prod['stripe_hook']); ?></code>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="scp-product-cat-pills" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
                                                    <?php foreach ($productCategories as $cat):
                                                        $cId        = (int)$cat['id'];
                                                        $isAssigned = in_array($cId, $assignedIds, true);
                                                    ?>
                                                        <button type="button"
                                                            class="scp-cat-select-pill <?php echo $isAssigned ? 'active' : ''; ?>"
                                                            data-price-id="<?php echo $prodId; ?>"
                                                            data-category-id="<?php echo $cId; ?>"
                                                            data-assigned="<?php echo $isAssigned ? '1' : '0'; ?>"
                                                            title="<?php echo $isAssigned ? 'Click to remove from ' . SCP_Security::e($cat['name']) : 'Click to add to ' . SCP_Security::e($cat['name']); ?>">
                                                            <span class="scp-pill-check"><?php echo $isAssigned ? '&#10003;' : '+'; ?></span>
                                                            <span><?php echo SCP_Security::e($cat['name']); ?></span>
                                                        </button>
                                                    <?php endforeach; ?>

                                                    <button type="button"
                                                        class="scp-btn scp-btn-xs scp-btn-outline scp-open-quick-cat-modal"
                                                        data-price-id="<?php echo $prodId; ?>"
                                                        data-product-name="<?php echo SCP_Security::e($prod['name']); ?>"
                                                        style="border-style:dashed;">
                                                        + New Category
                                                    </button>
                                                </div>
                                            </td>
                                            <td style="white-space:nowrap;">
                                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                                    <button type="button"
                                                        class="scp-btn scp-btn-xs scp-btn-primary scp-open-catalog-product-modal"
                                                        data-product="<?php echo SCP_Security::e(json_encode($prod)); ?>">
                                                        &#9998; Edit Product
                                                    </button>
                                                    <button type="button"
                                                        class="scp-btn scp-btn-xs scp-btn-danger scp-delete-catalog-product-btn"
                                                        data-product-id="<?php echo $prodId; ?>"
                                                        data-product-name="<?php echo SCP_Security::e($prod['name']); ?>">
                                                        Delete
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

        <!-- ADMIN TAB 3: CLIENT WEBSITES & HOSTED PLATFORMS -->
        <section class="scp-tab-panel" id="scp-tab-admin-websites">
            <div class="scp-overview-columns scp-mb-24">
                <!-- Assign Website / Hosted Platform Form -->
                <div class="scp-card">
                    <div class="scp-card-header">
                        <div>
                            <h3>Assign Website or Hosted Platform to Client</h3>
                            <p class="scp-card-sub">Assign a Managed WordPress site (with 1-Click SSO), Ad Server, Mass Emailing Service, or any Cloud / VPS application to a client's dashboard.</p>
                        </div>
                    </div>
                    <div class="scp-card-body">
                        <form id="scp-admin-add-website-form" class="scp-form">
                            <div class="scp-form-row">
                                <div class="scp-form-group" style="flex:1.2;">
                                    <label>Select Client Account *</label>
                                    <select name="user_id" required>
                                        <option value="">— Select Client —</option>
                                        <?php foreach ($allUsers as $u):
                                            $uDisplay = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: $u['email'];
                                        ?>
                                            <option value="<?php echo (int)$u['id']; ?>">
                                                #<?php echo (int)$u['id']; ?> — <?php echo SCP_Security::e($uDisplay); ?> (<?php echo SCP_Security::e($u['email']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="scp-form-group" style="flex:1;">
                                    <label>Platform / Service Type *</label>
                                    <select name="platform_type" id="scp-admin-platform-type-select" required>
                                        <option value="wordpress">WordPress (with 1-Click SSO)</option>
                                        <option value="ad_server">Ad Server</option>
                                        <option value="mass_email">Mass Emailing Service</option>
                                        <option value="cloud_vps">Cloud / VPS Server or Custom Web App</option>
                                    </select>
                                </div>
                            </div>

                            <div class="scp-form-row">
                                <div class="scp-form-group">
                                    <label>Site / Platform Title *</label>
                                    <input type="text" name="site_name" placeholder="e.g., Acme Corp Main Site or Dedicated Ad Server" required />
                                </div>
                                <div class="scp-form-group">
                                    <label>Hosting Plan / Service Tier Label</label>
                                    <input type="text" name="hosting_plan" id="scp-admin-hosting-plan-input" placeholder="Managed Cloud WordPress" value="Managed Cloud WordPress" />
                                </div>
                            </div>

                            <div class="scp-form-row">
                                <div class="scp-form-group">
                                    <label>Live Website / Application URL *</label>
                                    <input type="url" name="site_url" placeholder="https://clientdomain.com" required />
                                </div>
                                <div class="scp-form-group">
                                    <label id="scp-admin-login-url-label">Control Panel / WP Admin Login URL</label>
                                    <input type="url" name="login_url" id="scp-admin-login-url-input" placeholder="https://clientdomain.com/wp-login.php" />
                                </div>
                            </div>

                            <div class="scp-form-row" id="scp-admin-wp-sso-fields-row">
                                <div class="scp-form-group">
                                    <label>Target WP Admin Email (Optional — defaults to client email)</label>
                                    <input type="email" name="wp_admin_email" placeholder="admin@clientdomain.com" />
                                </div>
                                <div class="scp-form-group" style="display:flex;align-items:flex-end;padding-bottom:10px;">
                                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                        <input type="checkbox" name="enable_auto_login" id="scp-admin-enable-sso-cb" value="1" checked style="width:auto;" />
                                        Enable 1-Click WordPress Auto-Login (SSO)
                                    </label>
                                </div>
                            </div>

                            <div class="scp-form-group">
                                <label>Notes / Server Details (Visible to Client)</label>
                                <input type="text" name="notes" placeholder="Optional notes (e.g. Dedicated IP, SMTP Relay Active, Daily Backups, PHP 8.3)" />
                            </div>

                            <button type="submit" class="scp-btn scp-btn-primary">Assign Platform to Client</button>
                        </form>
                    </div>
                </div>

                <!-- Companion Mu-Plugin & Multi-Platform Instructions -->
                <div class="scp-card">
                    <div class="scp-card-header">
                        <div>
                            <h3>Supported Hosted Platforms &amp; 1-Click WordPress SSO</h3>
                            <p class="scp-card-sub">Deliver WordPress sites, Ad Servers, Mass Emailing systems, and Cloud/VPS apps in one client dashboard.</p>
                        </div>
                    </div>
                    <div class="scp-card-body">
                        <div class="scp-security-box" style="margin-bottom:14px;">
                            <h4>Multi-Platform Client Access</h4>
                            <p style="margin:0;font-size:13px;line-height:1.6;color:#475569;">
                                &bull; <strong>WordPress Websites:</strong> Supports 1-Click passwordless WP-Admin login via HMAC-SHA256 SSO tokens.<br/>
                                &bull; <strong>Ad Servers &amp; Mass Emailing Services:</strong> Displays dedicated platform badges and launches the client straight into their Ad Server or Mass Emailing control panel.<br/>
                                &bull; <strong>Cloud &amp; VPS Applications:</strong> Link any custom web app, CRM, Node/Python/PHP system, or VPS control panel running on your cloud.
                            </p>
                        </div>
                        <h4 style="margin:0 0 8px 0;font-size:14px;color:#0f172a;">WordPress 1-Click SSO Setup</h4>
                        <ol style="margin:0;padding:0 0 0 18px;font-size:13px;color:#334155;line-height:1.65;">
                            <li>Click <strong>&#8681; Download Pre-Configured SSO Plugin</strong> next to any WordPress site below (or download the blank template: <a href="<?php echo SCP_Security::e($companionSsoPath); ?>" download class="scp-link"><code>secure-cloud-portal-client-sso.php</code></a>).</li>
                            <li>Upload the file to the client's WordPress site under <code>wp-content/mu-plugins/secure-cloud-portal-client-sso.php</code>.</li>
                            <li>When the client clicks <strong>One-Click WP Admin</strong> inside their portal, a single-use 60-second HMAC-SHA256 token logs them straight into WordPress!</li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- Assigned Websites & Platforms Table -->
            <div class="scp-card">
                <div class="scp-card-header">
                    <h3>All Assigned Client Websites &amp; Hosted Platforms (<?php echo count($allWebsites); ?>)</h3>
                </div>
                <div class="scp-card-body">
                    <?php if (empty($allWebsites)): ?>
                        <div class="scp-empty-state">
                            <p>No websites or hosted platforms assigned yet. Use the form above to assign a client platform.</p>
                        </div>
                    <?php else: ?>
                        <div class="scp-table-responsive">
                            <table class="scp-table">
                                <thead>
                                    <tr>
                                        <th>Platform &amp; Title</th>
                                        <th>Client</th>
                                        <th>Access Mode</th>
                                        <th>WordPress SSO Secret / Panel Link</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allWebsites as $site):
                                        $pType  = $site['platform_type'] ?? 'wordpress';
                                        $pShort = $site['platform_short_label'] ?? 'WordPress';
                                        $pBg    = $site['platform_badge_bg'] ?? '#e0f2fe';
                                        $pCol   = $site['platform_badge_color'] ?? '#0369a1';
                                    ?>
                                        <tr>
                                            <td>
                                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:3px;">
                                                    <span class="scp-badge" style="background:<?php echo SCP_Security::e($pBg); ?>;color:<?php echo SCP_Security::e($pCol); ?>;font-size:11px;">
                                                        <?php echo SCP_Security::e($pShort); ?>
                                                    </span>
                                                    <strong><?php echo SCP_Security::e($site['site_name']); ?></strong>
                                                </div>
                                                <div>
                                                    <a href="<?php echo SCP_Security::e($site['site_url']); ?>" target="_blank" rel="noopener" class="scp-site-url">
                                                        <?php echo SCP_Security::e($site['site_url']); ?> &#8599;
                                                    </a>
                                                </div>
                                                <?php if (!empty($site['hosting_plan'])): ?>
                                                    <div style="font-size:11.5px;color:#64748b;"><?php echo SCP_Security::e($site['hosting_plan']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo SCP_Security::e(trim(($site['first_name'] ?? '') . ' ' . ($site['last_name'] ?? '')) ?: $site['user_email']); ?></strong>
                                                <div style="font-size:12px;color:#64748b;"><?php echo SCP_Security::e($site['user_email']); ?></div>
                                            </td>
                                            <td>
                                                <?php if ($pType === 'wordpress' && !empty($site['enable_auto_login'])): ?>
                                                    <span class="scp-status-pill scp-status-active">&#9889; 1-Click WP SSO</span>
                                                <?php elseif ($pType === 'wordpress'): ?>
                                                    <span class="scp-status-pill scp-status-open">Direct WP Login</span>
                                                <?php else: ?>
                                                    <span class="scp-status-pill scp-status-open">Control Panel Link</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($pType === 'wordpress'): ?>
                                                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                                        <code style="font-size:11px;"><?php echo SCP_Security::e(substr($site['sso_secret'] ?? '', 0, 16)); ?>...</code>
                                                        <button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-copy-btn" data-copy="<?php echo SCP_Security::e($site['sso_secret'] ?? ''); ?>">
                                                            Copy Secret
                                                        </button>
                                                        <a href="<?php echo SCP_Security::e($companionSsoPath . '?site_id=' . (int)$site['id']); ?>" download="secure-cloud-portal-client-sso.php" class="scp-btn scp-btn-xs scp-btn-primary" style="text-decoration:none;">
                                                            &#8681; Download Pre-Configured SSO Plugin
                                                        </a>
                                                    </div>
                                                <?php else: ?>
                                                    <a href="<?php echo SCP_Security::e($site['login_link'] ?? $site['site_url']); ?>" target="_blank" rel="noopener" class="scp-btn scp-btn-xs scp-btn-outline">
                                                        Open <?php echo SCP_Security::e($pShort); ?> Panel &#8599;
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="scp-btn scp-btn-xs scp-btn-danger scp-admin-delete-site-btn" data-site-id="<?php echo (int)$site['id']; ?>">
                                                    Remove
                                                </button>
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

        <!-- ADMIN TAB 4: DOMAINS MANAGER -->
        <section class="scp-tab-panel" id="scp-tab-admin-domains">
            <div class="scp-card scp-mb-24">
                <div class="scp-card-header">
                    <div>
                        <h3>Assign Existing Domain to Client</h3>
                        <p class="scp-card-sub">Manually assign an existing OpenSRS or Namecheap domain to a client's portal account.</p>
                    </div>
                </div>
                <div class="scp-card-body">
                    <form id="scp-admin-add-domain-form" class="scp-form">
                        <div class="scp-form-row">
                            <div class="scp-form-group">
                                <label>Client Account *</label>
                                <select name="user_id" required>
                                    <option value="">— Select Client —</option>
                                    <?php foreach ($allUsers as $u):
                                        $uDisplay = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: $u['email'];
                                    ?>
                                        <option value="<?php echo (int)$u['id']; ?>">
                                            #<?php echo (int)$u['id']; ?> — <?php echo SCP_Security::e($uDisplay); ?> (<?php echo SCP_Security::e($u['email']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="scp-form-group">
                                <label>Domain Name *</label>
                                <input type="text" name="domain_name" placeholder="exampleclient.com" required />
                            </div>
                        </div>

                        <div class="scp-form-row">
                            <div class="scp-form-group">
                                <label>Registrar</label>
                                <select name="registrar">
                                    <option value="namecheap">Namecheap</option>
                                    <option value="opensrs">OpenSRS (Tucows)</option>
                                </select>
                            </div>
                            <div class="scp-form-group">
                                <label>Expiration Date</label>
                                <input type="date" name="expiration_date" value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>" required />
                            </div>
                        </div>

                        <div class="scp-form-group">
                            <label>Nameservers (One per line)</label>
                            <textarea name="nameservers" rows="3" placeholder="ns1.example.com&#10;ns2.example.com"><?php echo SCP_Security::e($settings['default_nameservers'] ?? ""); ?></textarea>
                        </div>

                        <button type="submit" class="scp-btn scp-btn-primary">Assign Domain to Client</button>
                    </form>
                </div>
            </div>

            <div class="scp-card">
                <div class="scp-card-header">
                    <h3>All Managed Client Domains</h3>
                </div>
                <div class="scp-card-body">
                    <?php if (empty($allDomains)): ?>
                        <div class="scp-empty-state">
                            <p>No domains assigned or registered yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="scp-table-responsive">
                            <table class="scp-table">
                                <thead>
                                    <tr>
                                        <th>Domain Name</th>
                                        <th>Client</th>
                                        <th>Registrar</th>
                                        <th>Status</th>
                                        <th>Expiration</th>
                                        <th>Nameservers</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allDomains as $dom): ?>
                                        <tr>
                                            <td><strong><?php echo SCP_Security::e($dom['domain_name']); ?></strong></td>
                                            <td>
                                                <strong><?php echo SCP_Security::e(trim(($dom['first_name'] ?? '') . ' ' . ($dom['last_name'] ?? '')) ?: $dom['user_email']); ?></strong>
                                                <div style="font-size:12px;color:#64748b;"><?php echo SCP_Security::e($dom['user_email']); ?></div>
                                            </td>
                                            <td><span class="scp-badge"><?php echo SCP_Security::e(strtoupper($dom['registrar'])); ?></span></td>
                                            <td>
                                                <span class="scp-status-pill scp-status-<?php echo SCP_Security::e($dom['status']); ?>">
                                                    <?php echo SCP_Security::e(ucfirst($dom['status'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo !empty($dom['expiration_date']) ? SCP_Security::e(date('M j, Y', strtotime($dom['expiration_date']))) : '—'; ?></td>
                                            <td><small class="scp-ns-preview"><?php echo SCP_Security::e(str_replace("\n", ', ', $dom['nameservers'] ?? '')); ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- ADMIN TAB: EMAIL CENTER (Titan SMTP / IMAP) -->
        <section class="scp-tab-panel" id="scp-tab-admin-email">
            <div class="scp-card">
                <div class="scp-card-header">
                    <div>
                        <h3>Email Center</h3>
                        <p class="scp-card-sub">
                            Send branded emails to clients and read &amp; reply to incoming mail for
                            <strong><?php echo SCP_Security::e($mailConfig['email'] ?? ($settings['support_email'] ?? 'support@example.com')); ?></strong>.
                            <span id="scp-email-last-sync" style="color:#94a3b8;"><?php echo !empty($settings['last_imap_sync_ts']) ? '&bull; Last synced ' . SCP_Security::e(date('M j, g:i A', (int)$settings['last_imap_sync_ts'])) : ''; ?></span>
                        </p>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="button" class="scp-btn scp-btn-outline" id="scp-email-sync-btn">&#8635; Check for New Mail</button>
                        <button type="button" class="scp-btn scp-btn-primary scp-open-compose-email">&#9998; Compose</button>
                    </div>
                </div>
                <div class="scp-card-body" style="padding:0;">
                    <div class="scp-mail-layout">
                        <!-- Folders -->
                        <aside class="scp-mail-folders">
                            <button type="button" class="scp-mail-folder active" data-folder="inbox">
                                <span>&#128229; Inbox</span>
                                <span class="scp-mail-count" data-count="inbox_unread"><?php echo (int)($emailCounts['inbox_unread'] ?? 0) ?: ''; ?></span>
                            </button>
                            <button type="button" class="scp-mail-folder" data-folder="unread">
                                <span>&#9679; Unread</span>
                                <span class="scp-mail-count" data-count="unread"><?php echo (int)($emailCounts['unread'] ?? 0) ?: ''; ?></span>
                            </button>
                            <button type="button" class="scp-mail-folder" data-folder="starred">
                                <span>&#9733; Starred</span>
                                <span class="scp-mail-count" data-count="starred"><?php echo (int)($emailCounts['starred'] ?? 0) ?: ''; ?></span>
                            </button>
                            <button type="button" class="scp-mail-folder" data-folder="sent">
                                <span>&#10148; Sent</span>
                                <span class="scp-mail-count scp-mail-count-muted" data-count="sent"><?php echo (int)($emailCounts['sent'] ?? 0) ?: ''; ?></span>
                            </button>
                            <button type="button" class="scp-mail-folder" data-folder="trash">
                                <span>&#128465; Trash</span>
                                <span class="scp-mail-count scp-mail-count-muted" data-count="trash"><?php echo (int)($emailCounts['trash'] ?? 0) ?: ''; ?></span>
                            </button>
                        </aside>

                        <!-- Message List -->
                        <div class="scp-mail-list-pane">
                            <div class="scp-mail-toolbar">
                                <label class="scp-mail-checkall" title="Select all">
                                    <input type="checkbox" id="scp-mail-select-all" />
                                </label>
                                <input type="search" id="scp-mail-search" placeholder="Search mail..." />
                                <div class="scp-mail-bulk-actions" id="scp-mail-bulk-actions">
                                    <button type="button" class="scp-btn scp-btn-xs scp-btn-outline" data-bulk="mark_read" title="Mark as read">&#10003; Read</button>
                                    <button type="button" class="scp-btn scp-btn-xs scp-btn-outline" data-bulk="mark_unread" title="Mark as unread">&#9679; Unread</button>
                                    <button type="button" class="scp-btn scp-btn-xs scp-btn-outline" data-bulk="star" title="Star">&#9733;</button>
                                    <button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-mail-hide-in-trash" data-bulk="trash" title="Move to Trash">&#128465; Delete</button>
                                    <button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-mail-show-in-trash" data-bulk="restore" title="Restore" style="display:none;">&#8634; Restore</button>
                                    <button type="button" class="scp-btn scp-btn-xs scp-btn-danger scp-mail-show-in-trash" data-bulk="delete_permanent" title="Delete forever" style="display:none;">Delete Forever</button>
                                </div>
                                <button type="button" class="scp-btn scp-btn-xs scp-btn-danger scp-mail-show-in-trash" id="scp-mail-empty-trash-btn" style="display:none;margin-left:auto;">Empty Trash</button>
                            </div>
                            <div class="scp-mail-list" id="scp-mail-list">
                                <div class="scp-empty-state" style="padding:30px;"><p>Loading mailbox...</p></div>
                            </div>
                        </div>

                        <!-- Reading Pane -->
                        <div class="scp-mail-reader" id="scp-mail-reader">
                            <div class="scp-mail-reader-empty">
                                <div style="font-size:42px;line-height:1;">&#9993;</div>
                                <p>Select an email to read it here.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ADMIN TAB 5: CONTACT INQUIRIES -->
        <section class="scp-tab-panel" id="scp-tab-admin-inquiries">
            <div class="scp-card">
                <div class="scp-card-header">
                    <div>
                        <h3>Public Contact Form Inquiries</h3>
                        <p class="scp-card-sub">Messages submitted through the public Contact Us page.</p>
                    </div>
                </div>
                <div class="scp-card-body">
                    <?php if (empty($inquiries)): ?>
                        <div class="scp-empty-state">
                            <p>No contact inquiries submitted yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="scp-table-responsive">
                            <table class="scp-table">
                                <thead>
                                    <tr>
                                        <th>Sender</th>
                                        <th>Department &amp; Subject</th>
                                        <th>Message</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inquiries as $inq): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo SCP_Security::e($inq['name']); ?></strong>
                                                <div><a href="#" class="scp-link scp-open-compose-email" data-to-email="<?php echo SCP_Security::e($inq['email']); ?>" data-attention="<?php echo SCP_Security::e($inq['name']); ?>" data-inquiry-id="<?php echo (int)$inq['id']; ?>" data-subject="Re: <?php echo SCP_Security::e($inq['subject']); ?>"><?php echo SCP_Security::e($inq['email']); ?></a></div>
                                                <?php if (!empty($inq['phone'])): ?>
                                                    <div style="font-size:12px;color:#64748b;"><?php echo SCP_Security::e($inq['phone']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="scp-badge"><?php echo SCP_Security::e($inq['department']); ?></span>
                                                <div style="margin-top:4px;font-weight:600;"><?php echo SCP_Security::e($inq['subject']); ?></div>
                                            </td>
                                            <td style="max-width:340px;white-space:pre-wrap;font-size:13px;"><?php echo SCP_Security::e($inq['message']); ?></td>
                                            <td style="white-space:nowrap;font-size:12px;"><?php echo SCP_Security::e(date('M j, Y g:i a', strtotime($inq['created_at']))); ?></td>
                                            <td>
                                                <span class="scp-status-pill scp-status-<?php echo $inq['status'] === 'unread' ? 'open' : 'active'; ?>">
                                                    <?php echo SCP_Security::e(ucfirst($inq['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                                    <?php if ($inq['status'] === 'unread'): ?>
                                                        <button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-mark-inquiry-btn" data-id="<?php echo (int)$inq['id']; ?>" data-status="read">
                                                            Mark Read
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" class="scp-btn scp-btn-xs scp-btn-primary scp-open-compose-email"
                                                        data-to-email="<?php echo SCP_Security::e($inq['email']); ?>"
                                                        data-attention="<?php echo SCP_Security::e($inq['name']); ?>"
                                                        data-inquiry-id="<?php echo (int)$inq['id']; ?>"
                                                        data-subject="Re: <?php echo SCP_Security::e($inq['subject']); ?>">
                                                        &#9993; Reply
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

        <!-- ADMIN TAB 6: API & PORTAL SETTINGS -->
        <section class="scp-tab-panel" id="scp-tab-admin-settings">
            <div class="scp-card">
                <div class="scp-card-header">
                    <div>
                        <h3>Portal Branding, Stripe, OpenSRS &amp; Namecheap Settings</h3>
                        <p class="scp-card-sub">Secret API keys are securely locked inside <code>config/credentials.php</code> and masked below.</p>
                    </div>
                </div>
                <div class="scp-card-body">
                    <form id="scp-admin-settings-form" class="scp-form">
                        <h4 style="margin:0 0 12px 0;color:#0f172a;">1. Portal Branding, Title &amp; Support Identity</h4>
                        <div class="scp-form-row">
                            <div class="scp-form-group">
                                <label>Company / Brand Name</label>
                                <input type="text" name="company_name" value="<?php echo SCP_Security::e($settings['company_name'] ?? 'Cloud Portal'); ?>" required />
                            </div>
                            <div class="scp-form-group">
                                <label>Dynamic Site Title / Tagline</label>
                                <input type="text" name="site_title" value="<?php echo SCP_Security::e($settings['site_title'] ?? 'Enterprise Cloud Hosting, Domains & Client Portal'); ?>" placeholder="e.g. Enterprise Cloud Hosting &amp; Client Portal" />
                            </div>
                        </div>
                        <div class="scp-form-row">
                            <div class="scp-form-group">
                                <label>Support Email Address</label>
                                <input type="email" name="support_email" value="<?php echo SCP_Security::e($settings['support_email'] ?? 'support@example.com'); ?>" required />
                            </div>
                            <div class="scp-form-group">
                                <label>Support Phone Number</label>
                                <input type="text" name="support_phone" value="<?php echo SCP_Security::e($settings['support_phone'] ?? '+1 (800) 555-0199'); ?>" />
                            </div>
                        </div>

                        <!-- Logo Upload & Live Preview Sub-Card -->
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin:16px 0 20px 0;">
                            <label style="display:block;font-weight:600;color:#0f172a;margin-bottom:6px;font-size:14px;">Portal Brand Logo</label>
                            <p style="margin:0 0 14px 0;font-size:13px;color:#64748b;">
                                Upload a custom SVG, PNG, WebP, or JPG logo to display on your public website, login pages, and client portal header. Recommended height: 36px–48px with a transparent background (max 2 MB).
                            </p>
                            <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
                                <div style="background:#0f172a;padding:12px 20px;border-radius:10px;border:1px solid #1e293b;display:flex;align-items:center;justify-content:center;min-width:200px;min-height:56px;">
                                    <?php
                                    $activeLogo = !empty($settings['site_logo_url']) ? $settings['site_logo_url'] : (file_exists(SCP_ROOT_DIR . '/assets/logo.svg') ? 'assets/logo.svg' : 'assets/secure-cloud-portal-logo.svg');
                                    $hasCustomLogo = !empty($settings['site_logo_url']);
                                    ?>
                                    <img id="scp-admin-logo-preview" src="<?php echo SCP_Security::e($activeLogo); ?>?v=<?php echo time(); ?>" alt="Logo Preview" style="max-height:42px;max-width:240px;object-fit:contain;" />
                                </div>
                                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                                    <label class="scp-btn scp-btn-secondary scp-btn-sm" style="cursor:pointer;margin:0;">
                                        <span>&#128247;</span> Choose Logo File...
                                        <input type="file" id="scp-admin-logo-file" accept=".png,.jpg,.jpeg,.svg,.webp" style="display:none;" />
                                    </label>
                                    <button type="button" id="scp-admin-logo-upload-btn" class="scp-btn scp-btn-primary scp-btn-sm" style="display:none;">
                                        Upload Selected Logo
                                    </button>
                                    <button type="button" id="scp-admin-logo-reset-btn" class="scp-btn scp-btn-danger scp-btn-sm" style="<?php echo $hasCustomLogo ? '' : 'display:none;'; ?>">
                                        Reset to Default Logo
                                    </button>
                                    <span id="scp-admin-logo-status" style="font-size:13px;color:#64748b;"></span>
                                </div>
                            </div>
                        </div>

                        <hr class="scp-divider" />

                        <h4 style="margin:0 0 12px 0;color:#0f172a;">2. Stripe Billing &amp; Webhook Configuration</h4>
                        <div class="scp-form-row">
                            <div class="scp-form-group">
                                <label>Stripe Environment Mode</label>
                                <select name="stripe_mode">
                                    <option value="test" <?php echo ($settings['stripe_mode'] ?? 'test') === 'test' ? 'selected' : ''; ?>>Test / Sandbox Mode</option>
                                    <option value="live" <?php echo ($settings['stripe_mode'] ?? 'test') === 'live' ? 'selected' : ''; ?>>Live Production Mode</option>
                                </select>
                            </div>
                            <div class="scp-form-group">
                                <label>Default Currency</label>
                                <input type="text" name="stripe_currency" value="<?php echo SCP_Security::e($settings['stripe_currency'] ?? 'usd'); ?>" maxlength="3" />
                            </div>
                            <div class="scp-form-group">
                                <label>Stripe Webhook Signing Secret (<code>whsec_...</code>)</label>
                                <?php $hasWebhookSecret = !empty($settings['stripe_webhook_secret']) || (defined('SCP_STRIPE_WEBHOOK_SECRET') && !empty(SCP_STRIPE_WEBHOOK_SECRET)); ?>
                                <input type="password" name="stripe_webhook_secret" placeholder="<?php echo $hasWebhookSecret ? '•••••••• (Active & Loaded from config/credentials.php)' : 'whsec_...'; ?>" />
                            </div>
                        </div>

                        <hr class="scp-divider" />

                        <h4 style="margin:0 0 6px 0;color:#0f172a;">3. Namecheap Registrar Settings (Default / Main Registrar)</h4>
                        <p style="margin:0 0 14px 0;font-size:13px;color:#64748b;">
                            Namecheap is used first as your default primary registrar for domain availability searches, registrations, renewals, and DNS nameserver updates.
                        </p>
                        <div class="scp-form-row">
                            <div class="scp-form-group">
                                <label>Namecheap Status &amp; Role</label>
                                <select name="nc_enabled">
                                    <option value="yes" <?php echo ($settings['nc_enabled'] ?? 'yes') !== 'no' ? 'selected' : ''; ?>>Enabled — Default / Main Registrar</option>
                                    <option value="no" <?php echo ($settings['nc_enabled'] ?? 'yes') === 'no' ? 'selected' : ''; ?>>Disabled (Route all requests to OpenSRS)</option>
                                </select>
                            </div>
                            <div class="scp-form-group">
                                <label>Namecheap API Environment</label>
                                <select name="nc_sandbox">
                                    <option value="no" <?php echo ($settings['nc_sandbox'] ?? 'no') === 'no' ? 'selected' : ''; ?>>Production (api.namecheap.com)</option>
                                    <option value="yes" <?php echo ($settings['nc_sandbox'] ?? 'no') === 'yes' ? 'selected' : ''; ?>>Sandbox (api.sandbox.namecheap.com)</option>
                                </select>
                            </div>
                            <div class="scp-form-group">
                                <label>Namecheap Whitelisted Server IP</label>
                                <input type="text" name="nc_client_ip" value="<?php echo SCP_Security::e($settings['nc_client_ip'] ?? ''); ?>" placeholder="Server outbound IPv4 address" />
                            </div>
                        </div>
                        <div class="scp-form-row">
                            <div class="scp-form-group">
                                <label>Namecheap API Username</label>
                                <input type="text" value="<?php echo SCP_Security::e(SCP_Security::getNamecheapApiUser() ?: 'Not Configured'); ?>" disabled style="background:#f1f5f9;color:#475569;" />
                            </div>
                            <div class="scp-form-group">
                                <label>Namecheap API Key</label>
                                <input type="password" value="" placeholder="•••••••• (Loaded from config/credentials.php)" disabled style="background:#f1f5f9;" />
                            </div>
                        </div>

                        <hr class="scp-divider" />

                        <h4 style="margin:0 0 6px 0;color:#0f172a;">4. OpenSRS (Tucows) Registrar Settings (Automatic Fallback Registrar)</h4>
                        <p style="margin:0 0 14px 0;font-size:13px;color:#64748b;">
                            OpenSRS automatically handles domain lookups, registrations, renewals, and DNS updates as a fallback whenever Namecheap is unreachable or returns an API error.
                        </p>
                        <div class="scp-form-row">
                            <div class="scp-form-group">
                                <label>OpenSRS Role &amp; Failover Mode</label>
                                <select name="os_fallback_enabled">
                                    <option value="yes" <?php echo ($settings['os_fallback_enabled'] ?? 'yes') === 'yes' ? 'selected' : ''; ?>>Enabled — Automatic Fallback when Namecheap has an issue (Recommended)</option>
                                    <option value="primary" <?php echo ($settings['os_fallback_enabled'] ?? 'yes') === 'primary' ? 'selected' : ''; ?>>Override — Use OpenSRS as Main Registrar</option>
                                    <option value="no" <?php echo ($settings['os_fallback_enabled'] ?? 'yes') === 'no' ? 'selected' : ''; ?>>Disabled (Do not fall back to OpenSRS)</option>
                                </select>
                            </div>
                            <div class="scp-form-group">
                                <label>OpenSRS API Environment</label>
                                <select name="os_environment">
                                    <option value="live" <?php echo ($settings['os_environment'] ?? 'live') === 'live' ? 'selected' : ''; ?>>Live Production (rr-n1-tor.opensrs.net)</option>
                                    <option value="test" <?php echo ($settings['os_environment'] ?? 'live') === 'test' ? 'selected' : ''; ?>>Horizon Test (horizon.opensrs.net)</option>
                                </select>
                            </div>
                        </div>
                        <div class="scp-form-row">
                            <div class="scp-form-group">
                                <label>OpenSRS Reseller Username</label>
                                <input type="text" value="<?php echo SCP_Security::e(SCP_Security::getOpenSrsUsername() ?: 'Not Configured'); ?>" disabled style="background:#f1f5f9;color:#475569;" />
                            </div>
                            <div class="scp-form-group">
                                <label>OpenSRS Private API Key</label>
                                <input type="password" value="" placeholder="•••••••• (Loaded from config/credentials.php)" disabled style="background:#f1f5f9;" />
                            </div>
                        </div>

                        <hr class="scp-divider" />

                        <h4 style="margin:0 0 12px 0;color:#0f172a;">5. Global Domain Pricing &amp; Default Nameservers</h4>
                        <div class="scp-form-row">
                            <div class="scp-form-group" style="max-width:320px;">
                                <label>Retail Domain Markup (%)</label>
                                <input type="number" step="0.5" min="0" max="500" name="price_markup_percent" value="<?php echo SCP_Security::e($settings['price_markup_percent'] ?? '20'); ?>" />
                            </div>
                        </div>

                        <div class="scp-form-group">
                            <label>Default Nameservers for New Registrations (One per line)</label>
                            <textarea name="default_nameservers" rows="3" placeholder="ns1.example.com&#10;ns2.example.com"><?php echo SCP_Security::e($settings['default_nameservers'] ?? ""); ?></textarea>
                        </div>

                        <button type="submit" class="scp-btn scp-btn-primary">Save All Settings</button>
                    </form>
                </div>
            </div>

            <!-- Email Server (Titan) Settings -->
            <div class="scp-card" style="margin-top:24px;">
                <div class="scp-card-header">
                    <div>
                        <h3>Email Server (SMTP / IMAP) Settings</h3>
                        <p class="scp-card-sub">Used by the Email Center to send client emails (SMTP) and receive replies (IMAP). The password is stored securely and never displayed.</p>
                    </div>
                </div>
                <div class="scp-card-body">
                    <form id="scp-admin-email-settings-form" class="scp-form">
                        <div class="scp-form-row">
                            <div class="scp-form-group">
                                <label>Mailbox Email Address</label>
                                <input type="email" name="mail_email" value="<?php echo SCP_Security::e($mailConfig['email'] ?? ''); ?>" required />
                            </div>
                            <div class="scp-form-group">
                                <label>"From" Display Name</label>
                                <input type="text" name="mail_from_name" value="<?php echo SCP_Security::e($mailConfig['from_name'] ?? ''); ?>" />
                            </div>
                            <div class="scp-form-group">
                                <label>Mailbox Password</label>
                                <input type="password" name="mail_password" autocomplete="new-password" placeholder="•••••••• (leave blank to keep current)" />
                            </div>
                        </div>
                        <div class="scp-form-row">
                            <div class="scp-form-group">
                                <label>SMTP Host (Outgoing)</label>
                                <input type="text" name="mail_smtp_host" value="<?php echo SCP_Security::e($mailConfig['smtp_host'] ?? 'smtp.titan.email'); ?>" />
                            </div>
                            <div class="scp-form-group" style="flex:0.5;">
                                <label>SMTP Port</label>
                                <input type="number" name="mail_smtp_port" value="<?php echo (int)($mailConfig['smtp_port'] ?? 465); ?>" />
                            </div>
                            <div class="scp-form-group">
                                <label>IMAP Host (Incoming)</label>
                                <input type="text" name="mail_imap_host" value="<?php echo SCP_Security::e($mailConfig['imap_host'] ?? 'imap.titan.email'); ?>" />
                            </div>
                            <div class="scp-form-group" style="flex:0.5;">
                                <label>IMAP Port</label>
                                <input type="number" name="mail_imap_port" value="<?php echo (int)($mailConfig['imap_port'] ?? 993); ?>" />
                            </div>
                            <div class="scp-form-group" style="flex:0.6;">
                                <label>Encryption</label>
                                <select name="mail_encryption">
                                    <option value="ssl" <?php echo ($mailConfig['encryption'] ?? 'ssl') === 'ssl' ? 'selected' : ''; ?>>SSL/TLS</option>
                                    <option value="tls" <?php echo ($mailConfig['encryption'] ?? 'ssl') === 'tls' ? 'selected' : ''; ?>>STARTTLS</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="scp-btn scp-btn-primary">Save Email Server Settings</button>
                    </form>
                </div>
            </div>
        </section>

        <!-- ADMIN TAB 7: SECURITY & AUDIT LOGS -->
        <section class="scp-tab-panel" id="scp-tab-admin-logs">
            <div class="scp-card">
                <div class="scp-card-header">
                    <div>
                        <h3>Security &amp; Activity Audit Logs</h3>
                        <p class="scp-card-sub">Chronological record of authentication events, Stripe synchronizations, webhooks, and administrative actions.</p>
                    </div>
                </div>
                <div class="scp-card-body">
                    <?php if (empty($logs)): ?>
                        <div class="scp-empty-state">
                            <p>No audit logs recorded yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="scp-table-responsive">
                            <table class="scp-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Event Type</th>
                                        <th>Description</th>
                                        <th>User</th>
                                        <th>Timestamp</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td>#<?php echo (int)$log['id']; ?></td>
                                            <td><code><?php echo SCP_Security::e($log['event_type']); ?></code></td>
                                            <td><?php echo SCP_Security::e($log['description']); ?></td>
                                            <td><?php echo SCP_Security::e($log['user_email'] ?: 'System / Webhook'); ?></td>
                                            <td style="white-space:nowrap;font-size:12px;"><?php echo SCP_Security::e($log['created_at']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

    <!-- Create User Modal -->
    <div id="scp-create-user-modal" class="scp-modal" style="display:none;">
        <div class="scp-modal-Dialog">
            <div class="scp-modal-header">
                <h3>Create New Account &amp; Sync with Stripe</h3>
                <button type="button" class="scp-modal-close">&times;</button>
            </div>
            <form id="scp-admin-create-user-form" class="scp-form">
                <div class="scp-form-row">
                    <div class="scp-form-group">
                        <label>First Name *</label>
                        <input type="text" name="first_name" required />
                    </div>
                    <div class="scp-form-group">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" required />
                    </div>
                </div>
                <div class="scp-form-group">
                    <label>Email Address * (Links existing Stripe Customer if found, or creates a new Customer in Stripe automatically)</label>
                    <input type="email" name="email" required />
                </div>
                <div class="scp-form-row">
                    <div class="scp-form-group">
                        <label>Company</label>
                        <input type="text" name="company" />
                    </div>
                    <div class="scp-form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" placeholder="+1 (555) 000-0000" />
                    </div>
                </div>
                <div class="scp-form-row">
                    <div class="scp-form-group">
                        <label>Street Address (Optional — Synced to Stripe)</label>
                        <input type="text" name="address_1" placeholder="123 Cloud Way" />
                    </div>
                    <div class="scp-form-group">
                        <label>City</label>
                        <input type="text" name="city" />
                    </div>
                </div>
                <div class="scp-form-row">
                    <div class="scp-form-group">
                        <label>State / Province</label>
                        <input type="text" name="state" />
                    </div>
                    <div class="scp-form-group">
                        <label>Postal / ZIP Code</label>
                        <input type="text" name="postcode" />
                    </div>
                    <div class="scp-form-group">
                        <label>Role</label>
                        <select name="role">
                            <option value="client">Client</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                </div>
                <div class="scp-form-group">
                    <label>Initial Password (minimum 8 characters) *</label>
                    <input type="password" name="password" minlength="8" required />
                </div>
                <div class="scp-modal-footer">
                    <button type="button" class="scp-btn scp-btn-outline scp-modal-close">Cancel</button>
                    <button type="submit" class="scp-btn scp-btn-primary">Create Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick-Add Category & Assign to Product Modal -->
<div class="scp-modal" id="scp-quick-category-modal" style="display:none;">
    <div class="scp-modal-Dialog scp-modal-dialog">
        <div class="scp-modal-header">
            <h3>Add New Category &amp; Assign to Product</h3>
            <button type="button" class="scp-modal-close scp-modal-close-icon" aria-label="Close modal" title="Close (Esc)">&times;</button>
        </div>
        <div class="scp-modal-body">
            <p style="margin:0 0 14px 0;font-size:13.5px;color:#475569;">
                Creating a new category for: <strong id="scp-quick-cat-product-label" style="color:#0f172a;"></strong>
            </p>
            <form id="scp-quick-category-form" class="scp-form">
                <input type="hidden" name="category_id" value="0" />
                <input type="hidden" name="assign_price_id" id="scp-quick-cat-price-input" value="" />

                <div class="scp-form-row">
                    <div class="scp-form-group" style="flex:2;">
                        <label>Category Heading / Name *</label>
                        <input type="text" name="name" id="scp-quick-cat-name-input" placeholder="e.g., Managed Cloud Hosting" required />
                    </div>
                    <div class="scp-form-group" style="flex:1;">
                        <label>Display Sort Order</label>
                        <input type="number" name="sort_order" value="10" step="1" />
                    </div>
                </div>

                <div class="scp-form-group">
                    <label>Category Description (Displayed under the Heading on the Website)</label>
                    <textarea name="description" rows="3" placeholder="Describe the plans or services in this category for your website visitors..."></textarea>
                </div>

                <div class="scp-modal-footer">
                    <button type="button" class="scp-btn scp-btn-outline scp-modal-close">Cancel / Close</button>
                    <button type="submit" class="scp-btn scp-btn-primary">Create &amp; Assign Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Compose / Reply Email Modal -->
<div class="scp-modal" id="scp-compose-email-modal" style="display:none;">
    <div class="scp-modal-Dialog scp-modal-dialog scp-modal-wide">
        <div class="scp-modal-header">
            <div>
                <h3 id="scp-compose-title">New Email</h3>
                <p style="margin:3px 0 0 0;font-size:13px;color:#64748b;">Sent from <strong><?php echo SCP_Security::e($mailConfig['email'] ?? ($settings['support_email'] ?? 'support@example.com')); ?></strong> using the branded client email template.</p>
            </div>
            <button type="button" class="scp-modal-close scp-modal-close-icon" aria-label="Close modal" title="Close (Esc)">&times;</button>
        </div>
        <div class="scp-modal-body" style="max-height:80vh;overflow-y:auto;">
            <form id="scp-compose-email-form" class="scp-form">
                <input type="hidden" name="user_id" value="0" />
                <input type="hidden" name="reply_to_id" value="0" />
                <input type="hidden" name="inquiry_id" value="0" />

                <div class="scp-form-row">
                    <div class="scp-form-group" style="flex:1.3;">
                        <label>To (Client Email) *</label>
                        <input type="email" name="to_email" list="scp-compose-client-datalist" placeholder="client@example.com" required autocomplete="off" />
                        <datalist id="scp-compose-client-datalist">
                            <?php foreach ($allUsers as $u):
                                $dlName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                            ?>
                                <option value="<?php echo SCP_Security::e($u['email']); ?>" data-user-id="<?php echo (int)$u['id']; ?>" data-name="<?php echo SCP_Security::e($dlName); ?>"><?php echo SCP_Security::e($dlName ?: $u['email']); ?></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="scp-form-group">
                        <label>Attention (Client Name, "Dear ...")</label>
                        <input type="text" name="attention_name" placeholder="Auto-filled from client account" />
                    </div>
                </div>

                <div class="scp-form-group">
                    <label>Subject *</label>
                    <input type="text" name="subject" maxlength="250" required />
                </div>

                <div class="scp-form-group">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
                        <label style="margin:0;">Message *</label>
                        <div class="scp-compose-format-bar">
                            <button type="button" class="scp-btn scp-btn-xs scp-btn-outline" data-wrap="strong" title="Bold"><strong>B</strong></button>
                            <button type="button" class="scp-btn scp-btn-xs scp-btn-outline" data-wrap="em" title="Italic"><em>I</em></button>
                            <button type="button" class="scp-btn scp-btn-xs scp-btn-outline" data-wrap="u" title="Underline"><u>U</u></button>
                            <button type="button" class="scp-btn scp-btn-xs scp-btn-outline" data-insert-link="1" title="Insert link">&#128279; Link</button>
                        </div>
                    </div>
                    <textarea name="message" rows="10" required placeholder="Type your message. Line breaks are preserved and links are clickable automatically."></textarea>
                    <div style="font-size:11.5px;color:#64748b;margin-top:4px;">
                        Template variables you can use inside the message: <code>{EMAIL_ATTENTION}</code> <code>{EMAIL_SENDTO}</code> <code>{EMAIL_DATE}</code> <code>{EMAIL_TIME}</code>
                    </div>
                </div>

                <label id="scp-compose-quote-wrap" style="display:none;align-items:center;gap:8px;font-size:13px;color:#334155;margin-bottom:10px;cursor:pointer;">
                    <input type="checkbox" name="include_quoted" value="1" checked style="width:auto;" /> Include the original message below my reply
                </label>

                <div id="scp-compose-preview-wrap" style="display:none;margin-bottom:12px;">
                    <iframe id="scp-compose-preview-frame" title="Email preview" sandbox="" style="width:100%;height:520px;border:1px solid #e2e8f0;border-radius:10px;background:#f1f1f1;"></iframe>
                </div>

                <div class="scp-modal-footer">
                    <button type="button" class="scp-btn scp-btn-outline scp-modal-close">Cancel</button>
                    <button type="button" class="scp-btn scp-btn-outline" id="scp-compose-preview-btn">&#128065; Preview Email</button>
                    <button type="submit" class="scp-btn scp-btn-primary" id="scp-compose-send-btn">&#10148; Send Email</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Client Billing, Invoice & Payment History Inspector Modal -->
<div class="scp-modal" id="scp-client-billing-modal" style="display:none;">
    <div class="scp-modal-Dialog scp-modal-dialog scp-modal-wide">
        <div class="scp-modal-header">
            <div>
                <h3 id="scp-cb-modal-title">Client Billing &amp; Invoice History</h3>
                <p id="scp-cb-modal-subtitle" style="margin:3px 0 0 0;font-size:13px;color:#64748b;"></p>
            </div>
            <button type="button" class="scp-modal-close scp-modal-close-icon" aria-label="Close modal" title="Close (Esc)">&times;</button>
        </div>
        <div class="scp-modal-body" id="scp-cb-modal-body" style="max-height:78vh;overflow-y:auto;">
            <div class="scp-empty-state"><p>Loading client billing history...</p></div>
        </div>
        <div class="scp-modal-footer">
            <button type="button" class="scp-btn scp-btn-outline scp-modal-close">Close</button>
        </div>
    </div>
</div>

<!-- Custom Product, Multi-Term Pricing & Features Builder Modal (v3.0.0) -->
<div class="scp-modal" id="scp-catalog-product-modal" style="display:none;">
    <div class="scp-modal-Dialog scp-modal-dialog scp-modal-wide">
        <div class="scp-modal-header">
            <div>
                <h3 id="scp-cp-modal-title">Create / Edit Product</h3>
                <p style="margin:3px 0 0 0;font-size:13px;color:#64748b;">
                    Configure product name, super title bubble, featured highlight, multi-term or one-time pricing, Stripe links, and grouped features.
                </p>
            </div>
            <button type="button" class="scp-modal-close scp-modal-close-icon" aria-label="Close modal" title="Close (Esc)">&times;</button>
        </div>
        <div class="scp-modal-body" style="max-height:80vh;overflow-y:auto;">
            <!-- Datalist of imported Stripe Price IDs for quick auto-complete when desired -->
            <datalist id="scp-stripe-price-hooks-datalist">
                <?php foreach ($importedProducts as $ip): ?>
                    <option value="<?php echo SCP_Security::e($ip['price_id']); ?>">
                        <?php echo SCP_Security::e($ip['name'] . ' — $' . number_format(((int)$ip['unit_amount']) / 100, 2) . ' (' . ($ip['is_recurring'] ? $ip['interval_unit'] : 'one-time') . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </datalist>

            <form id="scp-catalog-product-form" class="scp-form">
                <input type="hidden" name="product_id" id="scp-cp-id" value="0" />

                <!-- 1. Product Identity, Super Title & Featured Highlight -->
                <div class="scp-builder-section">
                    <h4 class="scp-builder-section-title">1. Product Identity, Super Title &amp; Highlight</h4>
                    <div class="scp-form-row">
                        <div class="scp-form-group" style="flex:1.4;">
                            <label>Product Name *</label>
                            <input type="text" name="name" id="scp-cp-name" placeholder="e.g., Business, Starter, eCommerce Essentials" required />
                        </div>
                        <div class="scp-form-group" style="flex:1.2;">
                            <label>Super Title (Displayed in Top Bubble above Card)</label>
                            <input type="text" name="super_title" id="scp-cp-super-title" placeholder="e.g., RECOMMENDED FOR BUSINESSES or JUST STARTING" />
                        </div>
                        <div class="scp-form-group" style="flex:0.8;">
                            <label>Button Text</label>
                            <input type="text" name="cta_label" id="scp-cp-cta-label" placeholder="Choose Plan" value="Choose Plan" />
                        </div>
                    </div>

                    <div class="scp-form-row" style="align-items:center;margin-bottom:12px;">
                        <div class="scp-form-group" style="flex:1;margin-bottom:0;">
                            <label class="scp-builder-checkbox-card">
                                <input type="checkbox" name="is_featured" id="scp-cp-featured" value="1" />
                                <div>
                                    <strong>&#9733; Featured Product Highlight</strong>
                                    <div style="font-size:12px;color:#64748b;">Highlights this product card with a bold blue border, solid blue super-title bubble, and solid primary CTA button.</div>
                                </div>
                            </label>
                        </div>
                        <div class="scp-form-group" style="flex:1;margin-bottom:0;">
                            <label class="scp-builder-checkbox-card">
                                <input type="checkbox" name="portal_visible" id="scp-cp-visible" value="1" checked />
                                <div>
                                    <strong>&#10003; Visible on Website &amp; Client Portal</strong>
                                    <div style="font-size:12px;color:#64748b;">Turn ON to display this product in the public &amp; client pricing catalog.</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="scp-form-row">
                        <div class="scp-form-group" style="flex:1.5;">
                            <label>Product Description</label>
                            <textarea name="description" id="scp-cp-description" rows="2" placeholder="e.g., For a business site customers can trust: protected, private, and backed by phone support"></textarea>
                        </div>
                        <div class="scp-form-group" style="flex:1;">
                            <label>Optional Value Callout Banner (Under Renewal Line)</label>
                            <textarea name="promo_callout" id="scp-cp-promo-callout" rows="2" placeholder="e.g., Just $3.00/mo more than Starter, about 10c/day"></textarea>
                        </div>
                    </div>

                    <!-- Categories Selection inside Modal -->
                    <div class="scp-form-group" style="margin-bottom:0;">
                        <label>Assign to Product Categories (Select one or multiple categories)</label>
                        <div id="scp-cp-categories-wrap" style="display:flex;flex-wrap:wrap;gap:8px;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                            <?php if (empty($productCategories)): ?>
                                <span style="font-size:12.5px;color:#64748b;">No categories created yet. You can create categories in the form on the Products &amp; Categories tab.</span>
                            <?php else: ?>
                                <?php foreach ($productCategories as $cat): ?>
                                    <label class="scp-cp-cat-checkbox-pill">
                                        <input type="checkbox" class="scp-cp-cat-cb" value="<?php echo (int)$cat['id']; ?>" />
                                        <span><?php echo SCP_Security::e($cat['name']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 2. Term Toggle, Pricing, Save $/% & Stripe Hook/Link -->
                <div class="scp-builder-section">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
                        <div>
                            <h4 class="scp-builder-section-title" style="margin:0;">2. Billing Terms, Pricing, Save Badge &amp; Stripe Hook/Link</h4>
                            <p style="margin:2px 0 0 0;font-size:12.5px;color:#64748b;">
                                Toggle <strong>Multi-Term Pricing</strong> ON to offer multiple billing terms (e.g., Monthly, 6 Months, 12 Months, 36 Months), or leave OFF for a single price / one-time charge.
                            </p>
                        </div>
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;background:#e0f2fe;padding:8px 14px;border-radius:999px;border:1px solid #bae6fd;">
                            <input type="checkbox" id="scp-cp-has-terms-toggle" style="width:18px;height:18px;margin:0;" />
                            <strong style="font-size:13px;color:#0369a1;">Enable Multi-Term Pricing (Monthly / 6 Mo / 12 Mo / 36 Mo)</strong>
                        </label>
                    </div>

                    <!-- Mode A: Single Price / One-Time Charge (when Multi-Term is OFF) -->
                    <div id="scp-cp-single-price-panel" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;">
                        <div class="scp-form-row">
                            <div class="scp-form-group" style="flex:1;">
                                <label>Billing Mode</label>
                                <select id="scp-cp-billing-mode">
                                    <option value="recurring">Recurring Subscription</option>
                                    <option value="one_time">One-Time Charge</option>
                                </select>
                            </div>
                            <div class="scp-form-group" style="flex:0.9;">
                                <label>Price Amount ($)</label>
                                <input type="number" step="0.01" min="0" id="scp-cp-price-amount" placeholder="6.99" value="0.00" />
                            </div>
                            <div class="scp-form-group" style="flex:0.8;">
                                <label>Price Unit / Suffix</label>
                                <input type="text" id="scp-cp-price-unit" placeholder="/mo or one-time" value="/mo" />
                            </div>
                            <div class="scp-form-group" style="flex:0.9;">
                                <label>Save Badge (Save $ / %)</label>
                                <input type="text" id="scp-cp-save-badge" placeholder="e.g., Save 50% or Save $40" />
                            </div>
                        </div>

                        <div class="scp-form-row">
                            <div class="scp-form-group" style="flex:1;">
                                <label>Term Length Text (Optional)</label>
                                <input type="text" id="scp-cp-term-length" placeholder="e.g., For 36 month term or One-time charge" />
                            </div>
                            <div class="scp-form-group" style="flex:1;">
                                <label>Renews At Text (Optional)</label>
                                <input type="text" id="scp-cp-renews-at" placeholder="e.g., Renews at $13.99/mo" />
                            </div>
                            <div class="scp-form-group" style="flex:1.5;">
                                <label>Stripe Hook / Link (Payment Link URL or Stripe Price ID)</label>
                                <input type="text" id="scp-cp-stripe-hook" list="scp-stripe-price-hooks-datalist" placeholder="https://buy.stripe.com/... or price_..." />
                            </div>
                        </div>
                    </div>

                    <!-- Mode B: Multi-Term Pricing Rows (when Multi-Term is ON) -->
                    <div id="scp-cp-multi-terms-panel" style="display:none;background:#f8fafc;border:1px solid #bae6fd;border-radius:10px;padding:16px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                            <div style="font-size:13px;font-weight:700;color:#0f172a;">
                                Configure Term Tiers (Each term has its own price, renewal text, Save $/% badge, and Stripe Link/Hook)
                            </div>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                <button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-cp-preset-term-btn" data-term="Monthly" data-unit="/mo" data-len="Billed monthly">+ Monthly</button>
                                <button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-cp-preset-term-btn" data-term="6 Months" data-unit="/mo" data-len="For 6 month term">+ 6 Months</button>
                                <button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-cp-preset-term-btn" data-term="12 Months" data-unit="/mo" data-len="For 12 month term">+ 12 Months</button>
                                <button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-cp-preset-term-btn" data-term="36 Months" data-unit="/mo" data-len="For 36 month term">+ 36 Months</button>
                                <button type="button" class="scp-btn scp-btn-xs scp-btn-primary" id="scp-cp-add-term-row-btn">+ Add Custom Term</button>
                            </div>
                        </div>

                        <div id="scp-cp-terms-rows-container"></div>
                    </div>
                </div>

                <!-- 3. Features & Section Headings Builder -->
                <div class="scp-builder-section" style="margin-bottom:0;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
                        <div>
                            <h4 class="scp-builder-section-title" style="margin:0;">3. Product Features &amp; Optional Section Headings</h4>
                            <p style="margin:2px 0 0 0;font-size:12.5px;color:#64748b;">
                                Group features under bold section headings (no bullet on headings) or turn headings OFF so features load in flat order. Click <strong>B</strong> or <em>I</em> to bold/italicize any feature, <strong>New</strong> to display a "New" badge, or <strong>&#9432; Hint</strong> to add mouse-over extra information.
                            </p>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;background:#f1f5f9;padding:6px 12px;border-radius:999px;border:1px solid #cbd5e1;">
                                <input type="checkbox" id="scp-cp-use-feature-headings" checked style="width:16px;height:16px;margin:0;" />
                                <strong style="font-size:12.5px;color:#0f172a;">Show Feature Section Headings</strong>
                            </label>
                            <button type="button" class="scp-btn scp-btn-xs scp-btn-outline" id="scp-cp-add-feature-group-btn">
                                + Add Feature Heading Group
                            </button>
                            <button type="button" class="scp-btn scp-btn-xs scp-btn-primary" id="scp-cp-add-flat-feature-btn">
                                + Add Feature Row
                            </button>
                        </div>
                    </div>

                    <div id="scp-cp-features-groups-container"></div>
                </div>

                <div class="scp-modal-footer" style="margin-top:20px;">
                    <button type="button" class="scp-btn scp-btn-outline scp-modal-close">Cancel / Close</button>
                    <button type="submit" id="scp-cp-save-submit-btn" class="scp-btn scp-btn-primary">Save Product to Catalog</button>
                </div>
            </form>
        </div>
    </div>
</div>
