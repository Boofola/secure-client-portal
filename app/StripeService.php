<?php
/**
 * Stand-Alone Stripe Service (API, Customer Sync, Subscriptions, Invoices, Quotes, Checkout, Webhooks)
 * Secure Cloud Portal Stand-Alone v2.0.0
 */

if (!defined('SCP_APP_INIT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

class SCP_StripeService {
    private $db;
    private $apiUrl = 'https://api.stripe.com/v1';

    public function __construct() {
        $this->db = SCP_Database::getInstance();
    }

    public function getMode() {
        return SCP_Security::getStripeMode();
    }

    public function getSecretKey() {
        return SCP_Security::getStripeSecretKey($this->getMode());
    }

    public function getPublishableKey() {
        return SCP_Security::getStripePublishableKey($this->getMode());
    }

    public function getWebhookSecret() {
        if (defined('SCP_STRIPE_WEBHOOK_SECRET') && !empty(SCP_STRIPE_WEBHOOK_SECRET)) {
            return SCP_STRIPE_WEBHOOK_SECRET;
        }
        return $this->db->getSetting('stripe_webhook_secret', '');
    }

    public function getCurrency() {
        return strtolower($this->db->getSetting('stripe_currency', 'usd'));
    }

    public function getSiteUrl() {
        return SCP_Security::getBaseUrl();
    }

    /**
     * Validate a Stripe hook value (Price ID or HTTPS URL) when saving or executing checkout
     */
    public static function validateStripeHookValue($hook) {
        $hook = trim((string)$hook);
        if ($hook === '') {
            return '';
        }
        if (preg_match('/^price_[a-zA-Z0-9]+$/', $hook)) {
            return $hook;
        }
        $validUrl = SCP_Security::validateHttpUrl($hook, true);
        if ($validUrl) {
            return $validUrl;
        }
        return false;
    }

    /**
     * Verify that a requested checkout URL is either a trusted Stripe Payment Link domain
     * OR an exact active stripe_hook configured by the administrator in scp_catalog_products.
     */
    public function validateCatalogCheckoutUrl($requestedUrl) {
        $validUrl = SCP_Security::validateHttpUrl($requestedUrl, true);
        if (!$validUrl) {
            return array('error' => 'Invalid checkout link. Only secure HTTPS checkout URLs are permitted.');
        }

        $host = strtolower((string)parse_url($validUrl, PHP_URL_HOST));
        $isStripeHosted = in_array($host, array('buy.stripe.com', 'checkout.stripe.com', 'invoice.stripe.com', 'billing.stripe.com'), true);

        // Check if this URL is registered in scp_catalog_products
        $isRegisteredInCatalog = false;
        try {
            $pdo = $this->db->getConnection();
            $rows = $pdo->query("SELECT stripe_hook, terms_json FROM scp_catalog_products WHERE portal_visible = 1")->fetchAll();
            foreach ($rows as $r) {
                if (!empty($r['stripe_hook']) && trim($r['stripe_hook']) === $validUrl) {
                    $isRegisteredInCatalog = true;
                    break;
                }
                $tArr = json_decode($r['terms_json'] ?? '[]', true);
                if (is_array($tArr)) {
                    foreach ($tArr as $t) {
                        if (!empty($t['stripe_hook']) && trim($t['stripe_hook']) === $validUrl) {
                            $isRegisteredInCatalog = true;
                            break 2;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore
        }

        if (!$isRegisteredInCatalog && !$isStripeHosted) {
            return array('error' => 'Unauthorized checkout redirect URL.');
        }

        return array(
            'url'              => $validUrl,
            'is_stripe_hosted' => $isStripeHosted,
        );
    }

    /**
     * Send API request to Stripe
     */
    public function request($endpoint, $method = 'GET', $body = null) {
        $secretKey = $this->getSecretKey();
        if (empty($secretKey)) {
            return array('error' => 'Stripe Secret Key is not configured.');
        }

        $url = $this->apiUrl . '/' . ltrim($endpoint, '/');
        $headers = array(
            'Authorization'  => 'Bearer ' . $secretKey,
            'Stripe-Version' => '2023-10-16',
        );

        $resp = SCP_HttpClient::request($url, $method, $body, $headers, 30);
        if (!$resp['success']) {
            return array('error' => $resp['error'] ?: 'Stripe API request failed.');
        }

        return $resp['json'] ?: array();
    }

    /**
     * Find existing customer in Stripe by email address
     */
    public function findCustomerByEmail($email) {
        if (empty($email)) {
            return null;
        }
        $resp = $this->request('customers', 'GET', array(
            'email' => strtolower(trim($email)),
            'limit' => 1
        ));
        if (empty($resp['error']) && !empty($resp['data'][0])) {
            return $resp['data'][0];
        }
        return null;
    }

    /**
     * Sync and import customer billing profile from Stripe into MySQL scp_users
     */
    public function syncCustomerDataFromStripe($userId, $customer = null) {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM scp_users WHERE id = ? LIMIT 1");
        $stmt->execute(array((int)$userId));
        $user = $stmt->fetch();
        if (!$user) {
            return false;
        }

        if (!$customer) {
            if (!empty($user['stripe_customer_id'])) {
                $customer = $this->request('customers/' . $user['stripe_customer_id'], 'GET');
            } else {
                $customer = $this->findCustomerByEmail($user['email']);
            }
        }

        if (!$customer || !empty($customer['error']) || empty($customer['id']) || !empty($customer['deleted'])) {
            return false;
        }

        // Cross-account hijacking protection
        if (!empty($customer['metadata']['scp_user_id']) && (int)$customer['metadata']['scp_user_id'] !== (int)$userId) {
            $checkStmt = $pdo->prepare("SELECT id FROM scp_users WHERE id = ? LIMIT 1");
            $checkStmt->execute(array((int)$customer['metadata']['scp_user_id']));
            if ($checkStmt->fetch()) {
                return false;
            }
        }

        $customerId = $customer['id'];

        $firstName = $user['first_name'];
        $lastName  = $user['last_name'];
        if (!empty($customer['name']) && (empty($firstName) && empty($lastName))) {
            $parts = explode(' ', trim($customer['name']), 2);
            $firstName = $parts[0] ?? '';
            $lastName  = $parts[1] ?? '';
        }

        $phone = !empty($customer['phone']) ? $customer['phone'] : $user['phone'];
        $addr  = !empty($customer['address']) ? $customer['address'] : (!empty($customer['shipping']['address']) ? $customer['shipping']['address'] : array());

        $addr1    = !empty($addr['line1']) ? $addr['line1'] : $user['address_1'];
        $addr2    = !empty($addr['line2']) ? $addr['line2'] : $user['address_2'];
        $city     = !empty($addr['city']) ? $addr['city'] : $user['city'];
        $state    = !empty($addr['state']) ? $addr['state'] : $user['state'];
        $postcode = !empty($addr['postal_code']) ? $addr['postal_code'] : $user['postcode'];
        $country  = !empty($addr['country']) ? $addr['country'] : ($user['country'] ?: 'US');

        $company = $user['company'];
        if (!empty($customer['metadata']['company'])) {
            $company = $customer['metadata']['company'];
        } elseif (!empty($customer['shipping']['name']) && (!empty($customer['name']) && $customer['shipping']['name'] !== $customer['name'])) {
            $company = $customer['shipping']['name'];
        }

        $upd = $pdo->prepare(
            "UPDATE scp_users SET
                stripe_customer_id = ?,
                stripe_last_synced = ?,
                first_name = ?,
                last_name = ?,
                company = ?,
                phone = ?,
                address_1 = ?,
                address_2 = ?,
                city = ?,
                state = ?,
                postcode = ?,
                country = ?
             WHERE id = ?"
        );
        $upd->execute(array(
            $customerId,
            time(),
            $firstName,
            $lastName,
            $company,
            $phone,
            $addr1,
            $addr2,
            $city,
            $state,
            $postcode,
            $country,
            (int)$userId
        ));

        // Tag Stripe customer metadata with scp_user_id
        if (empty($customer['metadata']['scp_user_id']) || (int)$customer['metadata']['scp_user_id'] !== (int)$userId) {
            $meta = !empty($customer['metadata']) ? $customer['metadata'] : array();
            $meta['scp_user_id'] = (int)$userId;
            $this->request('customers/' . $customerId, 'POST', array('metadata' => $meta));
        }

        $this->db->logActivity($userId, 'stripe_sync_completed', sprintf('Synced Stripe customer (%s). Imported billing profile, invoices, subscriptions, and quotes.', $customerId));
        return $customerId;
    }

    /**
     * Push updated local profile details to Stripe Customer record
     */
    public function pushProfileToStripe($userId) {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM scp_users WHERE id = ? LIMIT 1");
        $stmt->execute(array((int)$userId));
        $user = $stmt->fetch();
        if (!$user || empty($user['stripe_customer_id'])) {
            return;
        }

        $body = array(
            'email' => $user['email'],
            'name'  => trim($user['first_name'] . ' ' . $user['last_name']) ?: $user['email'],
            'phone' => $user['phone'],
            'address' => array(
                'line1'       => $user['address_1'],
                'line2'       => $user['address_2'],
                'city'        => $user['city'],
                'state'       => $user['state'],
                'postal_code' => $user['postcode'],
                'country'     => $user['country'] ?: 'US',
            ),
            'metadata' => array(
                'scp_user_id' => $user['id'],
                'company'     => $user['company']
            )
        );

        $this->request('customers/' . $user['stripe_customer_id'], 'POST', $body);
    }

    /**
     * Create or retrieve Stripe Customer ID for user (matches existing Stripe customer by email first)
     */
    public function createOrGetCustomer($userId) {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM scp_users WHERE id = ? LIMIT 1");
        $stmt->execute(array((int)$userId));
        $user = $stmt->fetch();
        if (!$user) {
            return array('error' => 'Invalid user account.');
        }

        if (!empty($user['stripe_customer_id'])) {
            $customer = $this->request('customers/' . $user['stripe_customer_id'], 'GET');
            if (empty($customer['error']) && empty($customer['deleted'])) {
                $this->syncCustomerDataFromStripe($userId, $customer);
                return $user['stripe_customer_id'];
            }
        }

        // Check if client already exists in Stripe by email
        $existing = $this->findCustomerByEmail($user['email']);
        if ($existing && !empty($existing['id'])) {
            $syncedId = $this->syncCustomerDataFromStripe($userId, $existing);
            if ($syncedId) {
                return $syncedId;
            }
        }

        // Create new customer in Stripe
        $name = trim($user['first_name'] . ' ' . $user['last_name']) ?: $user['email'];
        $body = array(
            'email' => $user['email'],
            'name'  => $name,
            'metadata' => array(
                'scp_user_id' => $user['id'],
                'company'     => $user['company']
            )
        );
        if (!empty($user['phone'])) {
            $body['phone'] = $user['phone'];
        }
        if (!empty($user['address_1'])) {
            $body['address'] = array(
                'line1'       => $user['address_1'],
                'line2'       => $user['address_2'],
                'city'        => $user['city'],
                'state'       => $user['state'],
                'postal_code' => $user['postcode'],
                'country'     => $user['country'] ?: 'US',
            );
        }

        $created = $this->request('customers', 'POST', $body);
        if (!empty($created['error']) || empty($created['id'])) {
            return $created;
        }

        $upd = $pdo->prepare("UPDATE scp_users SET stripe_customer_id = ?, stripe_last_synced = ? WHERE id = ?");
        $upd->execute(array($created['id'], time(), (int)$userId));

        $this->db->logActivity($userId, 'stripe_customer_created', sprintf('Created new Stripe Customer (%s) for portal client %s.', $created['id'], $user['email']));
        return $created['id'];
    }

    /**
     * Get active subscriptions for customer
     */
    public function getActiveSubscriptions($customerId) {
        if (empty($customerId)) {
            return array();
        }
        $resp = $this->request('subscriptions', 'GET', array(
            'customer' => $customerId,
            'status'   => 'all',
            'limit'    => 25,
            'expand'   => array('data.default_payment_method', 'data.items.data.price.product')
        ));
        return $resp['data'] ?? array();
    }

    /**
     * Ensure local product catalog & category tables exist and sync products from Stripe if empty
     */
    private function ensureProductTablesAndSeed() {
        try {
            $this->db->installSchema();
            $pdo = $this->db->getConnection();
            $count = (int)$pdo->query("SELECT COUNT(*) FROM scp_stripe_products")->fetchColumn();
            if ($count === 0) {
                $productsList = $this->fetchPaginatedList('products', array('active' => 'true'), 3);
                $pricesList   = $this->fetchPaginatedList('prices', array('active' => 'true'), 3);
                $prodMap = array();
                foreach ($productsList as $p) {
                    if (!empty($p['id'])) {
                        $prodMap[$p['id']] = $p;
                    }
                }
                $insProd = $pdo->prepare(
                    "INSERT INTO scp_stripe_products (price_id, product_id, name, description, unit_amount, currency, interval_unit, interval_count, is_recurring, active, portal_visible)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)
                     ON DUPLICATE KEY UPDATE
                        product_id = VALUES(product_id),
                        name = VALUES(name),
                        description = VALUES(description),
                        unit_amount = VALUES(unit_amount),
                        currency = VALUES(currency),
                        interval_unit = VALUES(interval_unit),
                        interval_count = VALUES(interval_count),
                        is_recurring = VALUES(is_recurring),
                        active = 1"
                );
                foreach ($pricesList as $pr) {
                    $prodId = is_array($pr['product'] ?? null) ? ($pr['product']['id'] ?? '') : ($pr['product'] ?? '');
                    if (empty($pr['id']) || empty($prodId) || !isset($prodMap[$prodId]) || $pr['unit_amount'] === null) {
                        continue;
                    }
                    $prodObj = $prodMap[$prodId];
                    $insProd->execute(array(
                        $pr['id'],
                        $prodId,
                        $prodObj['name'] ?? 'Cloud Product',
                        $prodObj['description'] ?? '',
                        (int)$pr['unit_amount'],
                        strtoupper($pr['currency'] ?? 'USD'),
                        $pr['recurring']['interval'] ?? 'one_time',
                        (int)($pr['recurring']['interval_count'] ?? 1),
                        !empty($pr['recurring']) ? 1 : 0
                    ));
                }
            }
        } catch (Exception $e) {
            // Ignore errors
        }
    }

    /**
     * Normalize a term label into a consistent slug key (e.g. "12 Months" -> "12-months", "Monthly" -> "1-month")
     */
    public static function normalizeTermKey($label) {
        $raw = strtolower(trim((string)$label));
        if ($raw === 'monthly' || $raw === '1 month' || $raw === 'month' || $raw === '1 mo') {
            return '1-month';
        }
        if ($raw === 'quarterly' || $raw === '3 months' || $raw === '3 month' || $raw === '3 mo') {
            return '3-months';
        }
        if ($raw === 'semi-annual' || $raw === '6 months' || $raw === '6 month' || $raw === '6 mo') {
            return '6-months';
        }
        if ($raw === 'yearly' || $raw === 'annual' || $raw === '12 months' || $raw === '12 month' || $raw === '1 year' || $raw === '12 mo') {
            return '12-months';
        }
        if ($raw === '24 months' || $raw === '24 month' || $raw === '2 years' || $raw === '2 year') {
            return '24-months';
        }
        if ($raw === '36 months' || $raw === '36 month' || $raw === '3 years' || $raw === '3 year') {
            return '36-months';
        }
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $raw), '-');
        return $slug ?: 'custom-term';
    }

    /**
     * Automatically seed scp_catalog_products from existing scp_stripe_products (if empty and not yet seeded)
     * Preserves existing category assignments and visibility settings while grouping multi-price products into terms.
     */
    public function ensureCatalogProductsSeeded() {
        $this->ensureProductTablesAndSeed();
        try {
            $pdo = $this->db->getConnection();
            $catProdCount = (int)$pdo->query("SELECT COUNT(*) FROM scp_catalog_products")->fetchColumn();
            $alreadySeeded = $this->db->getSetting('catalog_v3_seeded', '');
            if ($catProdCount === 0 && empty($alreadySeeded)) {
                $this->importStripeProductsToCatalog();
                $this->db->setSetting('catalog_v3_seeded', '1');
            }
        } catch (Exception $e) {
            // Ignore
        }
    }

    /**
     * Import any unlisted Stripe products from scp_stripe_products into scp_catalog_products (grouped by product_id)
     */
    public function importStripeProductsToCatalog() {
        $this->ensureProductTablesAndSeed();
        $pdo = $this->db->getConnection();
        $stripeRows = $pdo->query("SELECT * FROM scp_stripe_products WHERE active = 1 ORDER BY name ASC, unit_amount ASC")->fetchAll();
        if (empty($stripeRows)) {
            return array('imported' => 0);
        }

        $oldCatMap = $this->getProductCategoryMappings();
        $grouped = array();
        foreach ($stripeRows as $r) {
            $prodId = !empty($r['product_id']) ? $r['product_id'] : $r['price_id'];
            if (!isset($grouped[$prodId])) {
                $grouped[$prodId] = array();
            }
            $grouped[$prodId][] = $r;
        }

        $existingHooks = array();
        $existingProdIds = array();
        $catRows = $pdo->query("SELECT stripe_product_id, stripe_hook, terms_json FROM scp_catalog_products")->fetchAll();
        foreach ($catRows as $cr) {
            if (!empty($cr['stripe_product_id'])) {
                $existingProdIds[$cr['stripe_product_id']] = true;
            }
            if (!empty($cr['stripe_hook'])) {
                $existingHooks[$cr['stripe_hook']] = true;
            }
            $tArr = json_decode($cr['terms_json'] ?? '[]', true);
            if (is_array($tArr)) {
                foreach ($tArr as $t) {
                    if (!empty($t['stripe_hook'])) {
                        $existingHooks[$t['stripe_hook']] = true;
                    }
                }
            }
        }

        $ins = $pdo->prepare(
            "INSERT INTO scp_catalog_products
             (stripe_product_id, name, super_title, description, promo_callout, cta_label, is_featured, has_terms, billing_mode,
              price_amount, price_unit, term_length_label, renews_at_label, save_badge_text, stripe_hook, terms_json,
              use_feature_headings, features_json, portal_visible, sort_order)
             VALUES (?, ?, '', ?, '', 'Choose Plan', 0, ?, ?, ?, ?, ?, '', '', ?, ?, 1, '[]', ?, 10)"
        );
        $insCat = $pdo->prepare("INSERT IGNORE INTO scp_catalog_product_categories (product_id, category_id) VALUES (?, ?)");

        $importedCount = 0;
        foreach ($grouped as $prodId => $prices) {
            if (!empty($existingProdIds[$prodId])) {
                continue;
            }
            $first = $prices[0];
            if (!empty($existingHooks[$first['price_id']])) {
                continue;
            }

            $allCatIds = array();
            $anyVisible = false;
            foreach ($prices as $pr) {
                if (!empty($pr['portal_visible'])) {
                    $anyVisible = true;
                }
                $cIds = $oldCatMap[$pr['price_id']]['category_ids'] ?? array();
                foreach ($cIds as $cid) {
                    $allCatIds[(int)$cid] = (int)$cid;
                }
            }

            if (count($prices) > 1) {
                $terms = array();
                foreach ($prices as $idx => $pr) {
                    $amt = round(((int)$pr['unit_amount']) / 100, 2);
                    $isRec = !empty($pr['is_recurring']);
                    $intUnit = strtolower($pr['interval_unit'] ?? 'month');
                    $intCount = max(1, (int)($pr['interval_count'] ?? 1));
                    if (!$isRec) {
                        $tName = 'One-Time';
                        $pUnit = ' one-time';
                        $tLen  = 'One-time charge';
                    } elseif ($intUnit === 'year') {
                        $months = $intCount * 12;
                        $tName = $months . ' Months';
                        $pUnit = '/yr';
                        $tLen  = 'For ' . $months . ' month term';
                    } elseif ($intUnit === 'month' && $intCount > 1) {
                        $tName = $intCount . ' Months';
                        $pUnit = '/' . $intCount . ' mo';
                        $tLen  = 'For ' . $intCount . ' month term';
                    } else {
                        $tName = 'Monthly';
                        $pUnit = '/mo';
                        $tLen  = 'Billed monthly';
                    }
                    $terms[] = array(
                        'term_key'          => self::normalizeTermKey($tName),
                        'term_name'         => $tName,
                        'price_amount'      => number_format($amt, 2, '.', ''),
                        'price_unit'        => $pUnit,
                        'term_length_label' => $tLen,
                        'renews_at_label'   => $isRec ? ('Renews at $' . number_format($amt, 2) . $pUnit) : '',
                        'save_badge_text'   => '',
                        'stripe_hook'       => $pr['price_id'],
                        'billing_mode'      => $isRec ? 'recurring' : 'one_time',
                        'is_default'        => $idx === 0 ? 1 : 0,
                    );
                }
                $def = $terms[0];
                $ins->execute(array(
                    $prodId,
                    $first['name'],
                    $first['description'] ?? '',
                    1,
                    $def['billing_mode'],
                    (float)$def['price_amount'],
                    $def['price_unit'],
                    $def['term_length_label'],
                    $def['stripe_hook'],
                    json_encode($terms),
                    $anyVisible ? 1 : 0
                ));
            } else {
                $amt = round(((int)$first['unit_amount']) / 100, 2);
                $isRec = !empty($first['is_recurring']);
                $intUnit = strtolower($first['interval_unit'] ?? 'month');
                $intCount = max(1, (int)($first['interval_count'] ?? 1));
                if (!$isRec) {
                    $pUnit = ' one-time';
                    $tLen  = 'One-time charge';
                } elseif ($intUnit === 'year') {
                    $pUnit = $intCount > 1 ? ('/' . $intCount . ' yrs') : '/yr';
                    $tLen  = 'For ' . ($intCount * 12) . ' month term';
                } elseif ($intUnit === 'month' && $intCount > 1) {
                    $pUnit = '/' . $intCount . ' mo';
                    $tLen  = 'For ' . $intCount . ' month term';
                } else {
                    $pUnit = '/mo';
                    $tLen  = 'Billed monthly';
                }
                $ins->execute(array(
                    $prodId,
                    $first['name'],
                    $first['description'] ?? '',
                    0,
                    $isRec ? 'recurring' : 'one_time',
                    $amt,
                    $pUnit,
                    $tLen,
                    $first['price_id'],
                    '[]',
                    $anyVisible ? 1 : 0
                ));
            }

            $newCatalogId = (int)$pdo->lastInsertId();
            foreach ($allCatIds as $cid) {
                $insCat->execute(array($newCatalogId, $cid));
            }
            $importedCount++;
        }

        return array('imported' => $importedCount);
    }

    /**
     * Get category mappings for custom catalog products (scp_catalog_product_categories)
     */
    public function getCatalogProductCategoryMappings() {
        $map = array();
        try {
            $pdo = $this->db->getConnection();
            $rows = $pdo->query(
                "SELECT m.product_id, c.id AS category_id, c.name, c.slug, c.description
                 FROM scp_catalog_product_categories m
                 INNER JOIN scp_product_categories c ON m.category_id = c.id
                 ORDER BY c.sort_order ASC, c.name ASC"
            )->fetchAll();
            foreach ($rows as $r) {
                $pid = (int)$r['product_id'];
                if (!isset($map[$pid])) {
                    $map[$pid] = array(
                        'category_ids' => array(),
                        'categories'   => array(),
                    );
                }
                $cid = (int)$r['category_id'];
                $map[$pid]['category_ids'][] = $cid;
                $map[$pid]['categories'][] = array(
                    'id'          => $cid,
                    'name'        => $r['name'],
                    'slug'        => $r['slug'],
                    'description' => $r['description'] ?? '',
                );
            }
        } catch (Exception $e) {
            // Ignore
        }
        return $map;
    }

    /**
     * Get all custom catalog products (or only visible ones for website/portal)
     * Sorted alphabetically A-Z by name
     */
    public function getCatalogProducts($onlyVisible = false) {
        $this->ensureCatalogProductsSeeded();
        try {
            $pdo = $this->db->getConnection();
            $sql = "SELECT * FROM scp_catalog_products";
            if ($onlyVisible) {
                $sql .= " WHERE portal_visible = 1";
            }
            $sql .= " ORDER BY name ASC, id ASC";
            $rows = $pdo->query($sql)->fetchAll();
            $catMap = $this->getCatalogProductCategoryMappings();

            $items = array();
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                $terms = json_decode($r['terms_json'] ?? '[]', true);
                if (!is_array($terms)) {
                    $terms = array();
                }
                $nameCounts = array();
                foreach ($terms as $tRow) {
                    $normLbl = strtolower(trim((string)($tRow['term_name'] ?? 'Monthly')));
                    $nameCounts[$normLbl] = ($nameCounts[$normLbl] ?? 0) + 1;
                }

                $usedTermKeys = array();
                foreach ($terms as $tIdx => &$t) {
                    $t['term_name']          = trim((string)($t['term_name'] ?? 'Monthly'));
                    $canonicalKey            = self::normalizeTermKey($t['term_name']);
                    $t['canonical_term_key'] = $canonicalKey;

                    $uniqueKey = $canonicalKey;
                    if (isset($usedTermKeys[$uniqueKey])) {
                        $uniqueKey = $canonicalKey . '-' . ($tIdx + 1);
                    }
                    $usedTermKeys[$uniqueKey] = true;
                    $t['term_key']          = $uniqueKey;

                    $t['price_amount']      = number_format((float)($t['price_amount'] ?? 0), 2, '.', '');
                    $t['price_unit']        = isset($t['price_unit']) ? (string)$t['price_unit'] : '/mo';
                    $t['term_length_label'] = trim((string)($t['term_length_label'] ?? ''));
                    $t['renews_at_label']   = trim((string)($t['renews_at_label'] ?? ''));
                    $t['save_badge_text']   = trim((string)($t['save_badge_text'] ?? ''));
                    $t['stripe_hook']       = trim((string)($t['stripe_hook'] ?? ''));
                    $t['billing_mode']      = !empty($t['billing_mode']) ? $t['billing_mode'] : 'recurring';
                    $t['is_default']        = !empty($t['is_default']) ? 1 : 0;

                    $normLbl = strtolower($t['term_name']);
                    if (($nameCounts[$normLbl] ?? 0) > 1) {
                        $t['display_label'] = $t['term_name'] . ' ($' . $t['price_amount'] . ')';
                    } else {
                        $t['display_label'] = $t['term_name'];
                    }
                }
                unset($t);

                $features = json_decode($r['features_json'] ?? '[]', true);
                if (!is_array($features)) {
                    $features = array();
                }

                $hasTerms = !empty($r['has_terms']) && !empty($terms);
                $defaultTerm = null;
                $defaultTermIndex = 0;
                if ($hasTerms) {
                    foreach ($terms as $tIdx => $t) {
                        if (!empty($t['is_default'])) {
                            $defaultTerm = $t;
                            $defaultTermIndex = (int)$tIdx;
                            break;
                        }
                    }
                    if (!$defaultTerm) {
                        $defaultTerm = $terms[0];
                        $defaultTermIndex = 0;
                    }
                }

                $displayPrice      = $hasTerms ? $defaultTerm['price_amount'] : number_format((float)($r['price_amount'] ?? 0), 2, '.', '');
                $displayUnit       = $hasTerms ? $defaultTerm['price_unit'] : (string)($r['price_unit'] ?? '/mo');
                $displayTermLength = $hasTerms ? $defaultTerm['term_length_label'] : (string)($r['term_length_label'] ?? '');
                $displayRenewsAt   = $hasTerms ? $defaultTerm['renews_at_label'] : (string)($r['renews_at_label'] ?? '');
                $displaySaveBadge  = $hasTerms ? $defaultTerm['save_badge_text'] : (string)($r['save_badge_text'] ?? '');
                $displayStripeHook = $hasTerms ? $defaultTerm['stripe_hook'] : (string)($r['stripe_hook'] ?? '');
                $displayMode       = $hasTerms ? ($defaultTerm['billing_mode'] ?? 'recurring') : (string)($r['billing_mode'] ?? 'recurring');

                $items[] = array(
                    'id'                   => $id,
                    'price_id'             => $displayStripeHook ?: ('catalog_' . $id),
                    'stripe_product_id'    => $r['stripe_product_id'] ?? '',
                    'name'                 => $r['name'],
                    'super_title'          => $r['super_title'] ?? '',
                    'description'          => $r['description'] ?? '',
                    'promo_callout'        => $r['promo_callout'] ?? '',
                    'cta_label'            => !empty($r['cta_label']) ? $r['cta_label'] : 'Choose Plan',
                    'is_featured'          => !empty($r['is_featured']) ? 1 : 0,
                    'has_terms'            => $hasTerms ? 1 : 0,
                    'billing_mode'         => $displayMode,
                    'is_recurring'         => $displayMode !== 'one_time',
                    'price_amount'         => $displayPrice,
                    'unit_amount'          => (int)round(((float)$displayPrice) * 100),
                    'currency'             => 'USD',
                    'price_unit'           => $displayUnit,
                    'interval'             => ltrim($displayUnit, '/ '),
                    'term_length_label'    => $displayTermLength,
                    'renews_at_label'      => $displayRenewsAt,
                    'save_badge_text'      => $displaySaveBadge,
                    'stripe_hook'          => $displayStripeHook,
                    'default_term_key'     => $hasTerms ? $defaultTerm['term_key'] : '',
                    'default_term_index'   => $hasTerms ? $defaultTermIndex : 0,
                    'terms'                => $terms,
                    'use_feature_headings' => isset($r['use_feature_headings']) ? (int)$r['use_feature_headings'] : 1,
                    'features'             => $features,
                    'portal_visible'       => isset($r['portal_visible']) ? (int)$r['portal_visible'] : 1,
                    'sort_order'           => (int)($r['sort_order'] ?? 10),
                    'categories'           => $catMap[$id]['categories'] ?? array(),
                    'category_ids'         => $catMap[$id]['category_ids'] ?? array(),
                );
            }

            usort($items, function ($a, $b) {
                $cmp = strnatcasecmp($a['name'] ?? '', $b['name'] ?? '');
                return $cmp !== 0 ? $cmp : (($a['unit_amount'] ?? 0) <=> ($b['unit_amount'] ?? 0));
            });

            return $items;
        } catch (Exception $e) {
            return array();
        }
    }

    /**
     * Get active & portal-visible products & recurring/one-time prices (sorted alphabetically A-Z)
     */
    public function getProductsAndPrices() {
        return $this->getCatalogProducts(true);
    }

    /**
     * Get categorized catalog for the public website & client portal
     * - Groups visible (portal_visible = 1) custom catalog products by their assigned categories (supporting multi-category products)
     * - Sorts all products inside each category alphabetically (A-Z)
     * - Computes available_terms per category for the Category-Level Master Term Switcher
     * - Includes a fallback section for any visible products not yet assigned to a category
     */
    public function getCategorizedCatalog() {
        $visibleProducts = $this->getCatalogProducts(true);
        $allCategories   = $this->getCategories();

        $alphaSort = function ($a, $b) {
            $cmp = strnatcasecmp($a['name'] ?? '', $b['name'] ?? '');
            return $cmp !== 0 ? $cmp : (($a['unit_amount'] ?? 0) <=> ($b['unit_amount'] ?? 0));
        };

        $collectCategoryTerms = function ($prods) {
            $termMap = array();
            $defaultKey = '';
            foreach ($prods as $p) {
                if (!empty($p['has_terms']) && !empty($p['terms'])) {
                    foreach ($p['terms'] as $t) {
                        $tk = !empty($t['canonical_term_key']) ? $t['canonical_term_key'] : $t['term_key'];
                        if (!isset($termMap[$tk])) {
                            $termMap[$tk] = array(
                                'term_key'  => $tk,
                                'term_name' => $t['term_name'],
                            );
                        }
                        if (!empty($t['is_default']) && empty($defaultKey)) {
                            $defaultKey = $tk;
                        }
                    }
                }
            }
            if (empty($defaultKey) && !empty($termMap)) {
                $firstTerm = reset($termMap);
                $defaultKey = $firstTerm['term_key'];
            }
            return array(
                'terms'            => array_values($termMap),
                'default_term_key' => $defaultKey,
            );
        };

        $assignedProductIds = array();
        $sections = array();

        foreach ($allCategories as $cat) {
            $catId = (int)$cat['id'];
            $catProducts = array();
            foreach ($visibleProducts as $prod) {
                if (in_array($catId, $prod['category_ids'], true)) {
                    $catProducts[] = $prod;
                    $assignedProductIds[$prod['id']] = true;
                }
            }
            if (!empty($catProducts)) {
                usort($catProducts, $alphaSort);
                $termMeta = $collectCategoryTerms($catProducts);
                $sections[] = array(
                    'id'                => $catId,
                    'name'              => $cat['name'],
                    'slug'              => $cat['slug'],
                    'description'       => $cat['description'] ?? '',
                    'bottom_note_text'  => $cat['bottom_note_text'] ?? '',
                    'bottom_note_title' => $cat['bottom_note_title'] ?? '',
                    'bottom_note_icon'  => $cat['bottom_note_icon'] ?? 'green-exclamation',
                    'bottom_notes'      => $cat['bottom_notes'] ?? array(),
                    'available_terms'   => $termMeta['terms'],
                    'default_term_key'  => $termMeta['default_term_key'],
                    'products'          => $catProducts,
                );
            }
        }

        $uncategorized = array();
        foreach ($visibleProducts as $prod) {
            if (empty($assignedProductIds[$prod['id']])) {
                $uncategorized[] = $prod;
            }
        }

        if (!empty($uncategorized)) {
            usort($uncategorized, $alphaSort);
            $termMeta = $collectCategoryTerms($uncategorized);
            $sections[] = array(
                'id'               => 0,
                'name'             => !empty($sections) ? 'Additional Cloud Plans & Services' : 'Cloud Hosting & Managed Service Plans',
                'slug'             => 'additional-services',
                'description'      => !empty($sections)
                    ? 'Additional cloud hosting packages, maintenance plans, and standalone service add-ons.'
                    : 'Select a plan below to deploy or upgrade your cloud services with instant Stripe checkout.',
                'available_terms'  => $termMeta['terms'],
                'default_term_key' => $termMeta['default_term_key'],
                'products'         => $uncategorized,
            );
        }

        return array(
            'sections'      => $sections,
            'total_visible' => count($visibleProducts),
        );
    }

    /**
     * Get all current and past invoices for customer
     */
    public function getInvoices($customerId) {
        if (empty($customerId)) {
            return array();
        }
        $resp = $this->request('invoices', 'GET', array('customer' => $customerId, 'limit' => 50));
        return $resp['data'] ?? array();
    }

    /**
     * Get all quotes for customer
     */
    public function getQuotes($customerId) {
        if (empty($customerId)) {
            return array();
        }
        $resp = $this->request('quotes', 'GET', array('customer' => $customerId, 'limit' => 50));
        return $resp['data'] ?? array();
    }

    /**
     * Get all payments / charges for customer
     */
    public function getPayments($customerId) {
        if (empty($customerId)) {
            return array();
        }
        $resp = $this->request('charges', 'GET', array('customer' => $customerId, 'limit' => 50));
        return $resp['data'] ?? array();
    }

    /**
     * Create Stripe Checkout Session for a product/subscription
     */
    public function createCheckoutSession($userId, $priceId, $mode = 'subscription') {
        if (!preg_match('/^price_[a-zA-Z0-9]+$/', $priceId)) {
            return array('error' => 'Invalid Stripe Price ID.');
        }
        $mode = ($mode === 'payment') ? 'payment' : 'subscription';

        $customerId = $this->createOrGetCustomer($userId);
        if (is_array($customerId) && isset($customerId['error'])) {
            return $customerId;
        }

        $baseUrl = $this->getSiteUrl();
        $body = array(
            'customer'             => $customerId,
            'payment_method_types' => array('card'),
            'line_items'           => array(array('price' => $priceId, 'quantity' => 1)),
            'mode'                 => $mode,
            'success_url'          => $baseUrl . '/index.php?page=portal&checkout=success&session_id={CHECKOUT_SESSION_ID}#billing',
            'cancel_url'           => $baseUrl . '/index.php?page=portal&checkout=cancel#billing',
            'metadata'             => array(
                'scp_user_id' => $userId,
                'source'      => 'secure_cloud_portal_standalone'
            )
        );

        return $this->request('checkout/sessions', 'POST', $body);
    }

    /**
     * Create Stripe Checkout Session for Domain Registration or Renewal
     */
    public function createDomainCheckoutSession($userId, $domainName, $action = 'register', $years = 1, $amountCents = 1500) {
        $customerId = $this->createOrGetCustomer($userId);
        if (is_array($customerId) && isset($customerId['error'])) {
            return $customerId;
        }

        $baseUrl = $this->getSiteUrl();
        $actionLabel = ($action === 'renew') ? 'Domain Renewal' : 'Domain Registration';

        $body = array(
            'customer'             => $customerId,
            'payment_method_types' => array('card'),
            'line_items'           => array(
                array(
                    'price_data' => array(
                        'currency'     => $this->getCurrency(),
                        'unit_amount'  => (int)$amountCents,
                        'product_data' => array(
                            'name'        => sprintf('%s: %s (%d yr%s)', $actionLabel, $domainName, $years, $years > 1 ? 's' : ''),
                            'description' => sprintf('Secure Cloud Portal domain service for %s', $domainName)
                        )
                    ),
                    'quantity' => 1
                )
            ),
            'mode'        => 'payment',
            'success_url' => $baseUrl . '/index.php?page=portal&domain_success=' . rawurlencode($domainName) . '&session_id={CHECKOUT_SESSION_ID}#domains',
            'cancel_url'  => $baseUrl . '/index.php?page=portal&domain_cancel=1#domains',
            'metadata'    => array(
                'scp_user_id'   => $userId,
                'domain_name'   => $domainName,
                'domain_action' => $action,
                'domain_years'  => $years,
                'service_type'  => 'domain'
            )
        );

        return $this->request('checkout/sessions', 'POST', $body);
    }

    /**
     * Create Stripe Billing Customer Portal Session
     */
    public function createCustomerPortalSession($customerId) {
        $baseUrl = $this->getSiteUrl();
        return $this->request('billing_portal/sessions', 'POST', array(
            'customer'   => $customerId,
            'return_url' => $baseUrl . '/index.php?page=portal#billing'
        ));
    }

    /**
     * Cancel subscription (verifies subscription ownership against user's stripe_customer_id)
     */
    public function cancelSubscription($subscriptionId, $userId) {
        if (!preg_match('/^sub_[a-zA-Z0-9]+$/', $subscriptionId)) {
            return array('error' => 'Invalid subscription ID format.');
        }

        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT stripe_customer_id FROM scp_users WHERE id = ? LIMIT 1");
        $stmt->execute(array((int)$userId));
        $user = $stmt->fetch();

        if (!$user || empty($user['stripe_customer_id'])) {
            return array('error' => 'No Stripe customer record associated with your account.');
        }

        $sub = $this->request('subscriptions/' . $subscriptionId, 'GET');
        if (!empty($sub['error'])) {
            return $sub;
        }

        $subCustomer = is_array($sub['customer'] ?? null) ? ($sub['customer']['id'] ?? '') : ($sub['customer'] ?? '');
        if ($subCustomer !== $user['stripe_customer_id']) {
            return array('error' => 'You are not authorized to modify this subscription.');
        }

        return $this->request('subscriptions/' . $subscriptionId, 'POST', array(
            'cancel_at_period_end' => 'true'
        ));
    }

    /**
     * Handle incoming Stripe Webhook with HMAC-SHA256 verification and Idempotency
     */
    public function handleWebhook($rawPayload, $sigHeader, $domainManager = null) {
        $webhookSecret = $this->getWebhookSecret();
        if (empty($webhookSecret)) {
            return array('code' => 400, 'body' => array('error' => 'Webhook secret not configured'));
        }

        if (!$this->verifyWebhookSignature($rawPayload, $sigHeader, $webhookSecret)) {
            return array('code' => 400, 'body' => array('error' => 'Invalid signature'));
        }

        $event = json_decode($rawPayload, true);
        if (!$event || empty($event['type'])) {
            return array('code' => 400, 'body' => array('error' => 'Invalid payload'));
        }

        $eventId = $event['id'] ?? '';
        if (!empty($eventId)) {
            if (SCP_Security::checkAndMarkIdempotencyKey('wh_evt', $eventId, 259200)) {
                return array('code' => 200, 'body' => array('success' => true, 'duplicate' => true));
            }
        }

        $eventType = $event['type'];
        $eventData = $event['data']['object'] ?? array();
        $pdo = $this->db->getConnection();

        switch ($eventType) {
            case 'checkout.session.completed':
                if (($eventData['metadata']['service_type'] ?? '') === 'domain') {
                    $sessionId  = $eventData['id'] ?? '';
                    $userId     = (int)($eventData['metadata']['scp_user_id'] ?? $eventData['metadata']['wp_user_id'] ?? 0);
                    $domainName = trim($eventData['metadata']['domain_name'] ?? '');
                    $action     = trim($eventData['metadata']['domain_action'] ?? 'register');
                    $years      = max(1, min(10, (int)($eventData['metadata']['domain_years'] ?? 1)));

                    if ($domainManager instanceof SCP_DomainManager && $userId > 0 && !empty($domainName)) {
                        $lockKey = !empty($sessionId) ? $sessionId : ($userId . '_' . $domainName . '_' . $action . '_' . date('YmdH'));
                        if (!SCP_Security::checkAndMarkIdempotencyKey('dom_paid', $lockKey, 604800)) {
                            $domainManager->handleDomainPaid($userId, $domainName, $action, $years);
                            $this->db->logActivity($userId, 'domain_payment_completed', sprintf('Domain payment succeeded for %s (%s, %d yr).', $domainName, $action, $years), 'Stripe-Webhook');
                        }
                    }
                }
                break;

            case 'invoice.payment_succeeded':
                $customerId = $eventData['customer'] ?? '';
                $amountPaid = ($eventData['amount_paid'] ?? 0) / 100;
                $invoiceNum = $eventData['number'] ?? ($eventData['id'] ?? '');
                $stmt = $pdo->prepare("SELECT id FROM scp_users WHERE stripe_customer_id = ? LIMIT 1");
                $stmt->execute(array($customerId));
                $u = $stmt->fetch();
                $this->db->logActivity($u['id'] ?? null, 'invoice_paid', sprintf('Invoice #%s of $%0.2f paid successfully.', $invoiceNum, $amountPaid), 'Stripe-Webhook');
                break;

            case 'customer.subscription.deleted':
                $customerId = $eventData['customer'] ?? '';
                $stmt = $pdo->prepare("SELECT id FROM scp_users WHERE stripe_customer_id = ? LIMIT 1");
                $stmt->execute(array($customerId));
                $u = $stmt->fetch();
                $this->db->logActivity($u['id'] ?? null, 'subscription_canceled', sprintf('Subscription %s was canceled.', $eventData['id'] ?? ''), 'Stripe-Webhook');
                break;

            case 'quote.accepted':
            case 'quote.canceled':
                $customerId = $eventData['customer'] ?? '';
                $quoteNum   = $eventData['number'] ?? ($eventData['id'] ?? '');
                $stmt = $pdo->prepare("SELECT id FROM scp_users WHERE stripe_customer_id = ? LIMIT 1");
                $stmt->execute(array($customerId));
                $u = $stmt->fetch();
                $this->db->logActivity($u['id'] ?? null, str_replace('.', '_', $eventType), sprintf('Quote %s status changed (%s).', $quoteNum, $eventType), 'Stripe-Webhook');
                break;
        }

        return array('code' => 200, 'body' => array('success' => true));
    }

    /**
     * Immediately verify a completed Stripe Checkout Session on redirect return
     * so domains and billing items appear in the client's dashboard right away
     * without waiting for the asynchronous webhook.
     */
    public function verifyAndProcessCheckoutSession($sessionId, $currentUserId, $domainManager = null) {
        $sessionId = trim((string)$sessionId);
        if (!preg_match('/^cs_[a-zA-Z0-9_]+$/', $sessionId)) {
            return false;
        }

        $session = $this->request('checkout/sessions/' . $sessionId, 'GET');
        if (!empty($session['error']) || empty($session['id'])) {
            return false;
        }

        if (($session['payment_status'] ?? '') !== 'paid' && ($session['status'] ?? '') !== 'complete') {
            return false;
        }

        if (($session['metadata']['service_type'] ?? '') === 'domain') {
            $metaUserId = (int)($session['metadata']['scp_user_id'] ?? $session['metadata']['wp_user_id'] ?? 0);
            if ($metaUserId <= 0 || $metaUserId !== (int)$currentUserId) {
                return false;
            }
            $userId     = $metaUserId;
            $domainName = trim($session['metadata']['domain_name'] ?? '');
            $action     = trim($session['metadata']['domain_action'] ?? 'register');
            $years      = max(1, min(10, (int)($session['metadata']['domain_years'] ?? 1)));

            if ($domainManager instanceof SCP_DomainManager && $userId > 0 && !empty($domainName)) {
                if (!SCP_Security::checkAndMarkIdempotencyKey('dom_paid', $sessionId, 604800)) {
                    $domainManager->handleDomainPaid($userId, $domainName, $action, $years);
                    $this->db->logActivity($userId, 'domain_payment_completed', sprintf('Domain payment verified on return for %s (%s, %d yr).', $domainName, $action, $years));
                }
                return true;
            }
        }

        return true;
    }

    private function verifyWebhookSignature($payload, $sigHeader, $secret) {
        if (empty($sigHeader) || empty($secret)) {
            return false;
        }
        $items = explode(',', $sigHeader);
        $timestamp = null;
        $signatures = array();

        foreach ($items as $item) {
            $parts = explode('=', trim($item), 2);
            if (count($parts) === 2) {
                if ($parts[0] === 't') {
                    $timestamp = $parts[1];
                } elseif ($parts[0] === 'v1') {
                    $signatures[] = $parts[1];
                }
            }
        }

        if (!$timestamp || empty($signatures)) {
            return false;
        }
        if (abs(time() - (int)$timestamp) > 600) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fetch paginated list from a Stripe endpoint (up to $maxPages * 100 records)
     */
    private function fetchPaginatedList($endpoint, $params = array(), $maxPages = 5) {
        $all = array();
        $startingAfter = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $query = array_merge(array('limit' => 100), $params);
            if ($startingAfter) {
                $query['starting_after'] = $startingAfter;
            }
            $resp = $this->request($endpoint, 'GET', $query);
            if (!empty($resp['error']) || empty($resp['data']) || !is_array($resp['data'])) {
                break;
            }
            foreach ($resp['data'] as $row) {
                $all[] = $row;
            }
            if (empty($resp['has_more'])) {
                break;
            }
            $last = end($resp['data']);
            $startingAfter = $last['id'] ?? null;
            if (!$startingAfter) {
                break;
            }
        }

        return $all;
    }

    /**
     * Master Two-Way Stripe Import & Synchronization Engine:
     * 1. Imports all existing Stripe Customers into scp_users (creating accounts if not yet registered, or updating existing profiles).
     * 2. Pushes any local scp_users who do not yet exist in Stripe to Stripe (/v1/customers).
     * 3. Imports all Stripe Products & Prices into scp_stripe_products.
     * 4. Imports all Stripe Subscriptions into scp_stripe_subscriptions.
     * 5. Imports all Stripe Invoices into scp_stripe_invoices.
     * 6. Imports all Stripe Payments / Charges into scp_stripe_payments.
     * 7. Imports all Stripe Quotes into scp_stripe_quotes.
     */
    public function importAllFromStripe($actorUserId = null) {
        $secretKey = $this->getSecretKey();
        if (empty($secretKey)) {
            return array('error' => 'Stripe Secret Key is not configured.');
        }

        try {
            $this->db->installSchema();
        } catch (Exception $e) {
            // Continue if schema already installed
        }

        $pdo = $this->db->getConnection();
        $now = time();

        $stats = array(
            'customers_imported'       => 0,
            'customers_updated'        => 0,
            'clients_pushed_to_stripe' => 0,
            'products'                 => 0,
            'subscriptions'            => 0,
            'invoices'                 => 0,
            'payments'                 => 0,
            'quotes'                   => 0,
        );

        // ---------------------------------------------------------------------
        // 1. Import All Existing Customers from Stripe -> scp_users
        // ---------------------------------------------------------------------
        $stripeCustomers = $this->fetchPaginatedList('customers', array(), 5);
        $customerToUserMap = array();

        foreach ($stripeCustomers as $cust) {
            if (!empty($cust['deleted']) || empty($cust['id'])) {
                continue;
            }
            $cid   = $cust['id'];
            $email = strtolower(trim($cust['email'] ?? ''));

            // Find local user by stripe_customer_id or email
            $user = null;
            $stmtCid = $pdo->prepare("SELECT * FROM scp_users WHERE stripe_customer_id = ? LIMIT 1");
            $stmtCid->execute(array($cid));
            $user = $stmtCid->fetch();

            if (!$user && !empty($email)) {
                $stmtEm = $pdo->prepare("SELECT * FROM scp_users WHERE email = ? LIMIT 1");
                $stmtEm->execute(array($email));
                $user = $stmtEm->fetch();
            }

            if ($user) {
                $this->syncCustomerDataFromStripe($user['id'], $cust);
                $customerToUserMap[$cid] = (int)$user['id'];
                $stats['customers_updated']++;
            } elseif (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // Create a new Client Portal account for this existing Stripe Customer
                $firstName = '';
                $lastName  = '';
                if (!empty($cust['name'])) {
                    $parts = explode(' ', trim($cust['name']), 2);
                    $firstName = $parts[0] ?? '';
                    $lastName  = $parts[1] ?? '';
                }
                $company = $cust['metadata']['company'] ?? ($cust['shipping']['name'] ?? '');
                $phone   = $cust['phone'] ?? '';
                $addr    = !empty($cust['address']) ? $cust['address'] : ($cust['shipping']['address'] ?? array());

                $randomPassHash = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
                $insUser = $pdo->prepare(
                    "INSERT INTO scp_users (email, password_hash, role, first_name, last_name, company, phone, address_1, address_2, city, state, postcode, country, stripe_customer_id, stripe_last_synced, status, created_at)
                     VALUES (?, ?, 'client', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())"
                );
                $insUser->execute(array(
                    $email,
                    $randomPassHash,
                    $firstName,
                    $lastName,
                    $company,
                    $phone,
                    $addr['line1'] ?? '',
                    $addr['line2'] ?? '',
                    $addr['city'] ?? '',
                    $addr['state'] ?? '',
                    $addr['postal_code'] ?? '',
                    ($addr['country'] ?? 'US') ?: 'US',
                    $cid,
                    $now
                ));
                $newUserId = (int)$pdo->lastInsertId();
                $customerToUserMap[$cid] = $newUserId;
                $stats['customers_imported']++;
            }
        }

        // ---------------------------------------------------------------------
        // 2. Push Any Portal Clients Not Yet in Stripe -> Stripe Customers API
        // ---------------------------------------------------------------------
        $allLocalUsers = $pdo->query("SELECT * FROM scp_users")->fetchAll();
        foreach ($allLocalUsers as $lu) {
            $uid = (int)$lu['id'];
            if (empty($lu['stripe_customer_id'])) {
                $cidRes = $this->createOrGetCustomer($uid);
                if (is_string($cidRes) && !empty($cidRes)) {
                    $customerToUserMap[$cidRes] = $uid;
                    $stats['clients_pushed_to_stripe']++;
                }
            } else {
                $customerToUserMap[$lu['stripe_customer_id']] = $uid;
            }
        }

        // ---------------------------------------------------------------------
        // 3. Import All Products & Prices from Stripe -> scp_stripe_products
        // ---------------------------------------------------------------------
        $productsList = $this->fetchPaginatedList('products', array('active' => 'true'), 3);
        $pricesList   = $this->fetchPaginatedList('prices', array('active' => 'true'), 3);

        $prodMap = array();
        foreach ($productsList as $p) {
            if (!empty($p['id'])) {
                $prodMap[$p['id']] = $p;
            }
        }

        $insProd = $pdo->prepare(
            "INSERT INTO scp_stripe_products (price_id, product_id, name, description, unit_amount, currency, interval_unit, interval_count, is_recurring, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                product_id = VALUES(product_id),
                name = VALUES(name),
                description = VALUES(description),
                unit_amount = VALUES(unit_amount),
                currency = VALUES(currency),
                interval_unit = VALUES(interval_unit),
                interval_count = VALUES(interval_count),
                is_recurring = VALUES(is_recurring),
                active = 1"
        );

        foreach ($pricesList as $pr) {
            $prodId = is_array($pr['product'] ?? null) ? ($pr['product']['id'] ?? '') : ($pr['product'] ?? '');
            if (empty($pr['id']) || empty($prodId) || !isset($prodMap[$prodId]) || $pr['unit_amount'] === null) {
                continue;
            }
            $prodObj = $prodMap[$prodId];
            $insProd->execute(array(
                $pr['id'],
                $prodId,
                $prodObj['name'] ?? 'Cloud Product',
                $prodObj['description'] ?? '',
                (int)$pr['unit_amount'],
                strtoupper($pr['currency'] ?? 'USD'),
                $pr['recurring']['interval'] ?? 'one_time',
                (int)($pr['recurring']['interval_count'] ?? 1),
                !empty($pr['recurring']) ? 1 : 0
            ));
            $stats['products']++;
        }

        // ---------------------------------------------------------------------
        // 4. Import All Subscriptions from Stripe -> scp_stripe_subscriptions
        // ---------------------------------------------------------------------
        $subsList = $this->fetchPaginatedList('subscriptions', array('status' => 'all'), 5);
        $insSub = $pdo->prepare(
            "INSERT INTO scp_stripe_subscriptions (subscription_id, customer_id, user_id, plan_name, price_id, amount_cents, currency, interval_unit, status, current_period_end, cancel_at_period_end, created_ts)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                customer_id = VALUES(customer_id),
                user_id = VALUES(user_id),
                plan_name = VALUES(plan_name),
                price_id = VALUES(price_id),
                amount_cents = VALUES(amount_cents),
                currency = VALUES(currency),
                interval_unit = VALUES(interval_unit),
                status = VALUES(status),
                current_period_end = VALUES(current_period_end),
                cancel_at_period_end = VALUES(cancel_at_period_end)"
        );

        foreach ($subsList as $sub) {
            if (empty($sub['id'])) {
                continue;
            }
            $cid       = is_array($sub['customer'] ?? null) ? ($sub['customer']['id'] ?? '') : ($sub['customer'] ?? '');
            $uid       = $customerToUserMap[$cid] ?? null;
            $firstItem = $sub['items']['data'][0] ?? array();
            $priceObj  = $firstItem['price'] ?? array();
            $prodId    = is_array($priceObj['product'] ?? null) ? ($priceObj['product']['id'] ?? '') : ($priceObj['product'] ?? '');
            $planName  = $priceObj['nickname']
                ?: (isset($prodMap[$prodId]['name']) ? $prodMap[$prodId]['name'] : ($firstItem['plan']['nickname'] ?? ('Subscription ' . $sub['id'])));

            $insSub->execute(array(
                $sub['id'],
                $cid,
                $uid,
                $planName,
                $priceObj['id'] ?? '',
                (int)($priceObj['unit_amount'] ?? 0),
                strtoupper($priceObj['currency'] ?? ($sub['currency'] ?? 'USD')),
                $priceObj['recurring']['interval'] ?? 'month',
                $sub['status'] ?? 'active',
                !empty($sub['current_period_end']) ? (int)$sub['current_period_end'] : null,
                !empty($sub['cancel_at_period_end']) ? 1 : 0,
                !empty($sub['created']) ? (int)$sub['created'] : $now
            ));
            $stats['subscriptions']++;
        }

        // ---------------------------------------------------------------------
        // 5. Import All Invoices from Stripe -> scp_stripe_invoices
        // ---------------------------------------------------------------------
        $invoicesList = $this->fetchPaginatedList('invoices', array(), 5);
        $insInv = $pdo->prepare(
            "INSERT INTO scp_stripe_invoices (invoice_id, invoice_number, customer_id, user_id, customer_email, amount_due, amount_paid, total_cents, currency, status, hosted_invoice_url, invoice_pdf, created_ts, due_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                invoice_number = VALUES(invoice_number),
                user_id = VALUES(user_id),
                customer_email = VALUES(customer_email),
                amount_due = VALUES(amount_due),
                amount_paid = VALUES(amount_paid),
                total_cents = VALUES(total_cents),
                status = VALUES(status),
                hosted_invoice_url = VALUES(hosted_invoice_url),
                invoice_pdf = VALUES(invoice_pdf),
                due_date = VALUES(due_date)"
        );

        foreach ($invoicesList as $inv) {
            if (empty($inv['id'])) {
                continue;
            }
            $cid = is_array($inv['customer'] ?? null) ? ($inv['customer']['id'] ?? '') : ($inv['customer'] ?? '');
            $uid = $customerToUserMap[$cid] ?? null;
            $insInv->execute(array(
                $inv['id'],
                $inv['number'] ?? $inv['id'],
                $cid,
                $uid,
                $inv['customer_email'] ?? '',
                (int)($inv['amount_due'] ?? 0),
                (int)($inv['amount_paid'] ?? 0),
                (int)($inv['total'] ?? 0),
                strtoupper($inv['currency'] ?? 'USD'),
                $inv['status'] ?? 'draft',
                $inv['hosted_invoice_url'] ?? '',
                $inv['invoice_pdf'] ?? '',
                !empty($inv['created']) ? (int)$inv['created'] : $now,
                !empty($inv['due_date']) ? (int)$inv['due_date'] : null
            ));
            $stats['invoices']++;
        }

        // ---------------------------------------------------------------------
        // 6. Import All Payments / Charges from Stripe -> scp_stripe_payments
        // ---------------------------------------------------------------------
        $chargesList = $this->fetchPaginatedList('charges', array(), 5);
        $insPay = $pdo->prepare(
            "INSERT INTO scp_stripe_payments (charge_id, customer_id, user_id, customer_email, amount_cents, currency, status, paid, refunded, description, receipt_url, card_brand, card_last4, created_ts)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                customer_email = VALUES(customer_email),
                amount_cents = VALUES(amount_cents),
                status = VALUES(status),
                paid = VALUES(paid),
                refunded = VALUES(refunded),
                description = VALUES(description),
                receipt_url = VALUES(receipt_url)"
        );

        foreach ($chargesList as $ch) {
            if (empty($ch['id'])) {
                continue;
            }
            $cid   = is_array($ch['customer'] ?? null) ? ($ch['customer']['id'] ?? '') : ($ch['customer'] ?? '');
            $uid   = $customerToUserMap[$cid] ?? null;
            $email = $ch['billing_details']['email'] ?? ($ch['receipt_email'] ?? '');
            $card  = $ch['payment_method_details']['card'] ?? array();

            $insPay->execute(array(
                $ch['id'],
                $cid,
                $uid,
                $email,
                (int)($ch['amount'] ?? 0),
                strtoupper($ch['currency'] ?? 'USD'),
                $ch['status'] ?? 'succeeded',
                !empty($ch['paid']) ? 1 : 0,
                !empty($ch['refunded']) ? 1 : 0,
                $ch['description'] ?? 'Stripe Payment',
                $ch['receipt_url'] ?? '',
                strtoupper($card['brand'] ?? ''),
                $card['last4'] ?? '',
                !empty($ch['created']) ? (int)$ch['created'] : $now
            ));
            $stats['payments']++;
        }

        // ---------------------------------------------------------------------
        // 7. Import All Quotes from Stripe -> scp_stripe_quotes
        // ---------------------------------------------------------------------
        $quotesList = $this->fetchPaginatedList('quotes', array(), 3);
        $insQt = $pdo->prepare(
            "INSERT INTO scp_stripe_quotes (quote_id, quote_number, customer_id, user_id, description, amount_total, currency, status, hosted_quote_url, invoice_id, created_ts, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                quote_number = VALUES(quote_number),
                user_id = VALUES(user_id),
                description = VALUES(description),
                amount_total = VALUES(amount_total),
                status = VALUES(status),
                hosted_quote_url = VALUES(hosted_quote_url),
                invoice_id = VALUES(invoice_id)"
        );

        foreach ($quotesList as $qt) {
            if (empty($qt['id'])) {
                continue;
            }
            $cid = is_array($qt['customer'] ?? null) ? ($qt['customer']['id'] ?? '') : ($qt['customer'] ?? '');
            $uid = $customerToUserMap[$cid] ?? null;
            $insQt->execute(array(
                $qt['id'],
                $qt['number'] ?? $qt['id'],
                $cid,
                $uid,
                $qt['description'] ?: ($qt['header'] ?: 'Custom Service Quote'),
                (int)($qt['amount_total'] ?? 0),
                strtoupper($qt['currency'] ?? 'USD'),
                $qt['status'] ?? 'open',
                $qt['hosted_quote_url'] ?? '',
                is_string($qt['invoice'] ?? null) ? $qt['invoice'] : '',
                !empty($qt['created']) ? (int)$qt['created'] : $now,
                !empty($qt['expires_at']) ? (int)$qt['expires_at'] : null
            ));
            $stats['quotes']++;
        }

        $this->db->setSetting('last_full_stripe_sync', (string)$now);
        $summary = sprintf(
            'Stripe Import Complete: %d new customers imported, %d existing synced, %d portal clients added to Stripe, %d products/prices, %d subscriptions, %d invoices, %d payments, and %d quotes.',
            $stats['customers_imported'],
            $stats['customers_updated'],
            $stats['clients_pushed_to_stripe'],
            $stats['products'],
            $stats['subscriptions'],
            $stats['invoices'],
            $stats['payments'],
            $stats['quotes']
        );
        $this->db->logActivity($actorUserId, 'stripe_full_import', $summary);

        return array(
            'success' => true,
            'stats'   => $stats,
            'message' => $summary
        );
    }

    /**
     * Admin Read Accessors & Category/Visibility Management for Catalog & Imported Stripe Mirror Tables
     */
    public function getCategories() {
        $this->ensureCatalogProductsSeeded();
        try {
            $pdo = $this->db->getConnection();
            $rows = $pdo->query(
                "SELECT c.*,
                        COUNT(DISTINCT m.product_id) AS product_count
                 FROM scp_product_categories c
                 LEFT JOIN scp_catalog_product_categories m ON c.id = m.category_id
                 GROUP BY c.id
                 ORDER BY c.sort_order ASC, c.name ASC"
            )->fetchAll();
            foreach ($rows as &$r) {
                $notes = array();
                if (!empty($r['bottom_notes_json'])) {
                    $decoded = json_decode((string)$r['bottom_notes_json'], true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $n) {
                            $nTitle = trim((string)($n['title'] ?? ''));
                            $nText  = trim((string)($n['text'] ?? ''));
                            $nIcon  = trim((string)($n['icon'] ?? 'green-exclamation')) ?: 'green-exclamation';
                            if ($nTitle !== '' || $nText !== '') {
                                $notes[] = array(
                                    'icon'  => $nIcon,
                                    'title' => $nTitle,
                                    'text'  => $nText,
                                );
                            }
                        }
                    }
                }
                if (empty($notes) && empty($r['bottom_notes_json'])) {
                    $legTitle = trim((string)($r['bottom_note_title'] ?? ''));
                    $legText  = trim((string)($r['bottom_note_text'] ?? ''));
                    $legIcon  = trim((string)($r['bottom_note_icon'] ?? 'green-exclamation')) ?: 'green-exclamation';
                    if ($legTitle !== '' || $legText !== '') {
                        $notes[] = array(
                            'icon'  => $legIcon,
                            'title' => $legTitle,
                            'text'  => $legText,
                        );
                    }
                }
                $r['bottom_notes'] = $notes;
            }
            unset($r);
            return $rows;
        } catch (Exception $e) {
            return array();
        }
    }

    public function getProductCategoryMappings() {
        $map = array();
        try {
            $pdo = $this->db->getConnection();
            $rows = $pdo->query(
                "SELECT m.price_id, c.id AS category_id, c.name, c.slug, c.description
                 FROM scp_product_category_map m
                 INNER JOIN scp_product_categories c ON m.category_id = c.id
                 ORDER BY c.sort_order ASC, c.name ASC"
            )->fetchAll();
            foreach ($rows as $r) {
                $pid = $r['price_id'];
                if (!isset($map[$pid])) {
                    $map[$pid] = array(
                        'category_ids' => array(),
                        'categories'   => array(),
                    );
                }
                $cid = (int)$r['category_id'];
                $map[$pid]['category_ids'][] = $cid;
                $map[$pid]['categories'][] = array(
                    'id'          => $cid,
                    'name'        => $r['name'],
                    'slug'        => $r['slug'],
                    'description' => $r['description'] ?? '',
                );
            }
        } catch (Exception $e) {
            // Ignore
        }
        return $map;
    }

    public function saveCategory($id, $name, $description = '', $sortOrder = 0, $noteIcon = 'green-exclamation', $noteTitle = '', $noteText = '', $bottomNotesJson = null) {
        $this->ensureProductTablesAndSeed();
        $name        = trim((string)$name);
        $description = trim((string)$description);
        $sortOrder   = (int)$sortOrder;
        $id          = (int)$id;

        if (empty($name)) {
            return array('error' => 'Please enter a category name.');
        }

        $cleanNotes = array();
        if ($bottomNotesJson !== null && $bottomNotesJson !== '') {
            $parsed = is_array($bottomNotesJson) ? $bottomNotesJson : json_decode((string)$bottomNotesJson, true);
            if (is_array($parsed)) {
                foreach ($parsed as $item) {
                    $iIcon  = trim((string)($item['icon'] ?? 'green-exclamation')) ?: 'green-exclamation';
                    $iTitle = trim((string)($item['title'] ?? ''));
                    $iText  = trim((string)($item['text'] ?? ''));
                    if ($iTitle !== '' || $iText !== '') {
                        $cleanNotes[] = array(
                            'icon'  => $iIcon,
                            'title' => $iTitle,
                            'text'  => $iText,
                        );
                    }
                }
            }
        } else {
            $noteIcon  = trim((string)$noteIcon) ?: 'green-exclamation';
            $noteTitle = trim((string)$noteTitle);
            $noteText  = trim((string)$noteText);
            if ($noteTitle !== '' || $noteText !== '') {
                $cleanNotes[] = array(
                    'icon'  => $noteIcon,
                    'title' => $noteTitle,
                    'text'  => $noteText,
                );
            }
        }

        $firstNote = !empty($cleanNotes) ? $cleanNotes[0] : array('icon' => 'green-exclamation', 'title' => '', 'text' => '');
        $noteIcon  = $firstNote['icon'];
        $noteTitle = $firstNote['title'];
        $noteText  = $firstNote['text'];
        $notesJsonEncoded = json_encode($cleanNotes);

        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        if (empty($slug)) {
            $slug = 'category-' . time();
        }

        $pdo = $this->db->getConnection();

        if ($id > 0) {
            // Ensure slug uniqueness excluding this ID
            $chk = $pdo->prepare("SELECT id FROM scp_product_categories WHERE slug = ? AND id != ? LIMIT 1");
            $chk->execute(array($slug, $id));
            if ($chk->fetch()) {
                $slug .= '-' . $id;
            }
            $upd = $pdo->prepare("UPDATE scp_product_categories SET name = ?, slug = ?, description = ?, sort_order = ?, bottom_note_icon = ?, bottom_note_title = ?, bottom_note_text = ?, bottom_notes_json = ? WHERE id = ?");
            $upd->execute(array($name, $slug, $description, $sortOrder, $noteIcon, $noteTitle, $noteText, $notesJsonEncoded, $id));
            return array('success' => true, 'category_id' => $id, 'name' => $name, 'slug' => $slug);
        }

        // Check if category with same name or slug already exists
        $existing = $pdo->prepare("SELECT * FROM scp_product_categories WHERE LOWER(name) = LOWER(?) OR slug = ? LIMIT 1");
        $existing->execute(array($name, $slug));
        $found = $existing->fetch();
        if ($found) {
            // Update description/sort_order/notes if provided, and return existing category ID
            $upd = $pdo->prepare("UPDATE scp_product_categories SET description = ?, sort_order = ?, bottom_note_icon = ?, bottom_note_title = ?, bottom_note_text = ?, bottom_notes_json = ? WHERE id = ?");
            $upd->execute(array($description, $sortOrder, $noteIcon, $noteTitle, $noteText, $notesJsonEncoded, (int)$found['id']));
            return array('success' => true, 'category_id' => (int)$found['id'], 'name' => $found['name'], 'slug' => $found['slug'], 'existed' => true);
        }

        $ins = $pdo->prepare("INSERT INTO scp_product_categories (name, slug, description, sort_order, bottom_note_icon, bottom_note_title, bottom_note_text, bottom_notes_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute(array($name, $slug, $description, $sortOrder, $noteIcon, $noteTitle, $noteText, $notesJsonEncoded));
        $newId = (int)$pdo->lastInsertId();

        return array('success' => true, 'category_id' => $newId, 'name' => $name, 'slug' => $slug);
    }

    public function deleteCategory($categoryId) {
        $categoryId = (int)$categoryId;
        if ($categoryId <= 0) {
            return array('error' => 'Invalid category ID.');
        }
        $pdo = $this->db->getConnection();
        $pdo->prepare("DELETE FROM scp_product_category_map WHERE category_id = ?")->execute(array($categoryId));
        $pdo->prepare("DELETE FROM scp_catalog_product_categories WHERE category_id = ?")->execute(array($categoryId));
        $pdo->prepare("DELETE FROM scp_product_categories WHERE id = ?")->execute(array($categoryId));
        return array('success' => true);
    }

    /**
     * Create or update a custom catalog product in scp_catalog_products
     */
    public function saveCatalogProduct($data) {
        $this->ensureCatalogProductsSeeded();
        $pdo = $this->db->getConnection();

        $id                 = (int)($data['product_id'] ?? ($data['id'] ?? 0));
        $name               = trim((string)($data['name'] ?? ''));
        $superTitle         = trim((string)($data['super_title'] ?? ''));
        $description        = trim((string)($data['description'] ?? ''));
        $promoCallout       = trim((string)($data['promo_callout'] ?? ''));
        $ctaLabel           = trim((string)($data['cta_label'] ?? 'Choose Plan')) ?: 'Choose Plan';
        $isFeatured         = !empty($data['is_featured']) && $data['is_featured'] !== '0' ? 1 : 0;
        $portalVisible      = isset($data['portal_visible']) ? ((!empty($data['portal_visible']) && $data['portal_visible'] !== '0') ? 1 : 0) : 1;
        $hasTerms           = !empty($data['has_terms']) && $data['has_terms'] !== '0' ? 1 : 0;
        $billingMode        = strtolower(trim((string)($data['billing_mode'] ?? 'recurring')));
        if (!in_array($billingMode, array('recurring', 'one_time'), true)) {
            $billingMode = 'recurring';
        }
        $priceAmount        = round((float)($data['price_amount'] ?? 0), 2);
        $priceUnit          = trim((string)($data['price_unit'] ?? ($billingMode === 'one_time' ? ' one-time' : '/mo')));
        $termLengthLabel    = trim((string)($data['term_length_label'] ?? ''));
        $renewsAtLabel      = trim((string)($data['renews_at_label'] ?? ''));
        $saveBadgeText      = trim((string)($data['save_badge_text'] ?? ''));
        $stripeHook         = trim((string)($data['stripe_hook'] ?? ''));
        $useFeatureHeadings = isset($data['use_feature_headings']) ? ((!empty($data['use_feature_headings']) && $data['use_feature_headings'] !== '0') ? 1 : 0) : 1;
        $sortOrder          = (int)($data['sort_order'] ?? 10);

        if (empty($name)) {
            return array('error' => 'Please enter a Product Name.');
        }

        if ($stripeHook !== '' && self::validateStripeHookValue($stripeHook) === false) {
            return array('error' => 'Invalid Stripe Hook / Link. Please enter a Stripe Price ID (price_...) or a valid https:// URL.');
        }

        // Parse and normalize terms_json
        $rawTerms = $data['terms'] ?? ($data['terms_json'] ?? array());
        if (is_string($rawTerms)) {
            $rawTerms = json_decode($rawTerms, true);
        }
        $cleanTerms = array();
        $hasDefault = false;
        if (is_array($rawTerms)) {
            foreach ($rawTerms as $t) {
                $tName = trim((string)($t['term_name'] ?? ''));
                if ($tName === '') {
                    continue;
                }
                $isDef = !empty($t['is_default']) ? 1 : 0;
                if ($isDef) {
                    $hasDefault = true;
                }
                $tMode = strtolower(trim((string)($t['billing_mode'] ?? 'recurring')));
                if (!in_array($tMode, array('recurring', 'one_time'), true)) {
                    $tMode = 'recurring';
                }
                $tHook = trim((string)($t['stripe_hook'] ?? ''));
                if ($tHook !== '' && self::validateStripeHookValue($tHook) === false) {
                    return array('error' => 'Invalid Stripe Hook / Link on term "' . $tName . '". Please enter a Stripe Price ID (price_...) or a valid https:// URL.');
                }
                $cleanTerms[] = array(
                    'term_key'          => self::normalizeTermKey($tName),
                    'term_name'         => $tName,
                    'price_amount'      => number_format((float)($t['price_amount'] ?? 0), 2, '.', ''),
                    'price_unit'        => isset($t['price_unit']) && trim((string)$t['price_unit']) !== '' ? trim((string)$t['price_unit']) : '/mo',
                    'term_length_label' => trim((string)($t['term_length_label'] ?? '')),
                    'renews_at_label'   => trim((string)($t['renews_at_label'] ?? '')),
                    'save_badge_text'   => trim((string)($t['save_badge_text'] ?? '')),
                    'stripe_hook'       => $tHook,
                    'billing_mode'      => $tMode,
                    'is_default'        => $isDef,
                );
            }
        }

        if ($hasTerms && empty($cleanTerms)) {
            return array('error' => 'Please add at least one billing term when Multi-Term Pricing is enabled, or turn off the Term toggle.');
        }

        if ($hasTerms && !empty($cleanTerms)) {
            if (!$hasDefault) {
                $cleanTerms[0]['is_default'] = 1;
            }
            $defTerm = $cleanTerms[0];
            foreach ($cleanTerms as $ct) {
                if (!empty($ct['is_default'])) {
                    $defTerm = $ct;
                    break;
                }
            }
            $priceAmount     = (float)$defTerm['price_amount'];
            $priceUnit       = $defTerm['price_unit'];
            $termLengthLabel = $defTerm['term_length_label'];
            $renewsAtLabel   = $defTerm['renews_at_label'];
            $saveBadgeText   = $defTerm['save_badge_text'];
            $stripeHook      = $defTerm['stripe_hook'];
            $billingMode     = $defTerm['billing_mode'];
        }

        // Parse and normalize features_json
        $rawFeatures = $data['features'] ?? ($data['features_json'] ?? array());
        if (is_string($rawFeatures)) {
            $rawFeatures = json_decode($rawFeatures, true);
        }
        $cleanFeatures = array();
        if (is_array($rawFeatures)) {
            foreach ($rawFeatures as $f) {
                $fText = trim((string)($f['text'] ?? ''));
                $fHeading = trim((string)($f['heading'] ?? ''));
                if ($fText === '') {
                    continue;
                }
                $icon = strtolower(trim((string)($f['icon'] ?? 'check')));
                if (!in_array($icon, array('check', 'cross', 'bullet'), true)) {
                    $icon = 'check';
                }
                $hintIcon = strtolower(trim((string)($f['hint_icon'] ?? 'black-info')));
                $validHintIcons = array(
                    'black-info',
                    'blue-question',
                    'green-checkmark',
                    'red-x',
                    'yellow-triangle',
                    'red-exclamation',
                );
                if (!in_array($hintIcon, $validHintIcons, true)) {
                    $hintIcon = 'black-info';
                }
                $cleanFeatures[] = array(
                    'heading'   => $fHeading,
                    'text'      => $fText,
                    'is_bold'   => !empty($f['is_bold']) ? 1 : 0,
                    'is_italic' => !empty($f['is_italic']) ? 1 : 0,
                    'is_new'    => !empty($f['is_new']) ? 1 : 0,
                    'hint'      => trim((string)($f['hint'] ?? '')),
                    'hint_icon' => $hintIcon,
                    'icon'      => $icon,
                );
            }
        }

        $termsJson    = json_encode($cleanTerms);
        $featuresJson = json_encode($cleanFeatures);

        if ($id > 0) {
            $upd = $pdo->prepare(
                "UPDATE scp_catalog_products
                 SET name = ?, super_title = ?, description = ?, promo_callout = ?, cta_label = ?,
                     is_featured = ?, has_terms = ?, billing_mode = ?, price_amount = ?, price_unit = ?,
                     term_length_label = ?, renews_at_label = ?, save_badge_text = ?, stripe_hook = ?,
                     terms_json = ?, use_feature_headings = ?, features_json = ?, portal_visible = ?, sort_order = ?
                 WHERE id = ?"
            );
            $upd->execute(array(
                $name, $superTitle, $description, $promoCallout, $ctaLabel,
                $isFeatured, $hasTerms, $billingMode, $priceAmount, $priceUnit,
                $termLengthLabel, $renewsAtLabel, $saveBadgeText, $stripeHook,
                $termsJson, $useFeatureHeadings, $featuresJson, $portalVisible, $sortOrder,
                $id
            ));
        } else {
            $ins = $pdo->prepare(
                "INSERT INTO scp_catalog_products
                 (name, super_title, description, promo_callout, cta_label, is_featured, has_terms, billing_mode,
                  price_amount, price_unit, term_length_label, renews_at_label, save_badge_text, stripe_hook,
                  terms_json, use_feature_headings, features_json, portal_visible, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->execute(array(
                $name, $superTitle, $description, $promoCallout, $ctaLabel,
                $isFeatured, $hasTerms, $billingMode, $priceAmount, $priceUnit,
                $termLengthLabel, $renewsAtLabel, $saveBadgeText, $stripeHook,
                $termsJson, $useFeatureHeadings, $featuresJson, $portalVisible, $sortOrder
            ));
            $id = (int)$pdo->lastInsertId();
        }

        // Update category assignments if category_ids was passed
        if (isset($data['category_ids'])) {
            $rawCats = $data['category_ids'];
            if (is_string($rawCats)) {
                $decodedCats = json_decode($rawCats, true);
                $rawCats = is_array($decodedCats) ? $decodedCats : array_filter(explode(',', $rawCats));
            }
            $pdo->prepare("DELETE FROM scp_catalog_product_categories WHERE product_id = ?")->execute(array($id));
            if (is_array($rawCats)) {
                $insCat = $pdo->prepare("INSERT IGNORE INTO scp_catalog_product_categories (product_id, category_id) VALUES (?, ?)");
                foreach ($rawCats as $cid) {
                    $cid = (int)$cid;
                    if ($cid > 0) {
                        $insCat->execute(array($id, $cid));
                    }
                }
            }
        }

        return array(
            'success'    => true,
            'product_id' => $id,
            'message'    => 'Product "' . $name . '" saved.',
        );
    }

    /**
     * Copy all product information to a new product listing and move the specified term/price-point
     * (by 0-based $termIndex) from the source product to the newly created product.
     */
    public function splitTermToNewProduct($productId, $termIndex, $overrideData = array()) {
        $this->ensureCatalogProductsSeeded();
        $productId = (int)$productId;
        $termIndex = (int)$termIndex;
        $pdo = $this->db->getConnection();

        $sourceRow = null;
        $sourceCats = array();
        if ($productId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM scp_catalog_products WHERE id = ? LIMIT 1");
            $stmt->execute(array($productId));
            $sourceRow = $stmt->fetch();
            if (!$sourceRow && empty($overrideData['name'])) {
                return array('error' => 'Original product could not be found.');
            }
            $cStmt = $pdo->prepare("SELECT category_id FROM scp_catalog_product_categories WHERE product_id = ?");
            $cStmt->execute(array($productId));
            foreach ($cStmt->fetchAll() as $cr) {
                $sourceCats[] = (int)$cr['category_id'];
            }
        }

        // Merge live modal overrideData (if provided) with stored DB row
        $name               = isset($overrideData['name']) && trim((string)$overrideData['name']) !== ''
            ? trim((string)$overrideData['name'])
            : trim((string)($sourceRow['name'] ?? ''));
        $superTitle         = isset($overrideData['super_title'])
            ? trim((string)$overrideData['super_title'])
            : trim((string)($sourceRow['super_title'] ?? ''));
        $description        = isset($overrideData['description'])
            ? trim((string)$overrideData['description'])
            : trim((string)($sourceRow['description'] ?? ''));
        $promoCallout       = isset($overrideData['promo_callout'])
            ? trim((string)$overrideData['promo_callout'])
            : trim((string)($sourceRow['promo_callout'] ?? ''));
        $ctaLabel           = isset($overrideData['cta_label']) && trim((string)$overrideData['cta_label']) !== ''
            ? trim((string)$overrideData['cta_label'])
            : trim((string)($sourceRow['cta_label'] ?? 'Choose Plan'));
        $isFeatured         = isset($overrideData['is_featured'])
            ? ((!empty($overrideData['is_featured']) && $overrideData['is_featured'] !== '0') ? 1 : 0)
            : (!empty($sourceRow['is_featured']) ? 1 : 0);
        $portalVisible      = isset($overrideData['portal_visible'])
            ? ((!empty($overrideData['portal_visible']) && $overrideData['portal_visible'] !== '0') ? 1 : 0)
            : (isset($sourceRow['portal_visible']) ? (int)$sourceRow['portal_visible'] : 1);
        $useFeatureHeadings = isset($overrideData['use_feature_headings'])
            ? ((!empty($overrideData['use_feature_headings']) && $overrideData['use_feature_headings'] !== '0') ? 1 : 0)
            : (isset($sourceRow['use_feature_headings']) ? (int)$sourceRow['use_feature_headings'] : 1);
        $sortOrder          = isset($sourceRow['sort_order']) ? (int)$sourceRow['sort_order'] : 10;

        $rawFeatures = $overrideData['features_json'] ?? ($overrideData['features'] ?? ($sourceRow['features_json'] ?? '[]'));
        if (is_string($rawFeatures)) {
            $rawFeatures = json_decode($rawFeatures, true);
        }
        if (!is_array($rawFeatures)) {
            $rawFeatures = array();
        }

        if (isset($overrideData['category_ids'])) {
            $rawCats = $overrideData['category_ids'];
            if (is_string($rawCats)) {
                $decodedCats = json_decode($rawCats, true);
                $rawCats = is_array($decodedCats) ? $decodedCats : array_filter(explode(',', $rawCats));
            }
            if (is_array($rawCats)) {
                $sourceCats = array_map('intval', $rawCats);
            }
        }

        $rawTerms = $overrideData['terms_json'] ?? ($overrideData['terms'] ?? ($sourceRow['terms_json'] ?? '[]'));
        if (is_string($rawTerms)) {
            $rawTerms = json_decode($rawTerms, true);
        }
        if (!is_array($rawTerms)) {
            $rawTerms = array();
        }

        if (count($rawTerms) <= 1) {
            return array('error' => 'This product only has one price/term remaining, so there is no extra price-point to move.');
        }
        if (!isset($rawTerms[$termIndex])) {
            return array('error' => 'Selected price-point/term could not be found.');
        }

        $movedTerm = $rawTerms[$termIndex];
        array_splice($rawTerms, $termIndex, 1);

        // Ensure at least one remaining term is marked default
        $hasDef = false;
        foreach ($rawTerms as $rt) {
            if (!empty($rt['is_default'])) {
                $hasDef = true;
                break;
            }
        }
        if (!$hasDef && !empty($rawTerms)) {
            $rawTerms[0]['is_default'] = 1;
        }

        // If only 1 term remains on the source product, switch it cleanly to single-price mode (has_terms = 0)
        $remainingCount = count($rawTerms);
        $remainingFirst = $rawTerms[0];
        $sourceSavePayload = array(
            'product_id'           => $productId,
            'name'                 => $name,
            'super_title'          => $superTitle,
            'description'          => $description,
            'promo_callout'        => $promoCallout,
            'cta_label'            => $ctaLabel,
            'is_featured'          => $isFeatured,
            'portal_visible'       => $portalVisible,
            'has_terms'            => $remainingCount > 1 ? 1 : 0,
            'billing_mode'         => $remainingFirst['billing_mode'] ?? 'recurring',
            'price_amount'         => $remainingFirst['price_amount'] ?? 0,
            'price_unit'           => $remainingFirst['price_unit'] ?? '/mo',
            'term_length_label'    => $remainingFirst['term_length_label'] ?? '',
            'renews_at_label'      => $remainingFirst['renews_at_label'] ?? '',
            'save_badge_text'      => $remainingFirst['save_badge_text'] ?? '',
            'stripe_hook'          => $remainingFirst['stripe_hook'] ?? '',
            'terms'                => $remainingCount > 1 ? $rawTerms : array(),
            'use_feature_headings' => $useFeatureHeadings,
            'features'             => $rawFeatures,
            'category_ids'         => $sourceCats,
            'sort_order'           => $sortOrder,
        );

        $savedSource = $this->saveCatalogProduct($sourceSavePayload);
        if (!empty($savedSource['error'])) {
            return $savedSource;
        }
        $sourceId = (int)$savedSource['product_id'];

        // Create the new product listing with all product details copied and the moved price-point/term
        $movedPrice = number_format((float)($movedTerm['price_amount'] ?? 0), 2, '.', '');
        $movedUnit  = isset($movedTerm['price_unit']) && trim((string)$movedTerm['price_unit']) !== '' ? trim((string)$movedTerm['price_unit']) : '/mo';
        $movedMode  = !empty($movedTerm['billing_mode']) ? $movedTerm['billing_mode'] : 'recurring';

        $newProductPayload = array(
            'product_id'           => 0,
            'name'                 => $name,
            'super_title'          => $superTitle,
            'description'          => $description,
            'promo_callout'        => $promoCallout,
            'cta_label'            => $ctaLabel,
            'is_featured'          => $isFeatured,
            'portal_visible'       => $portalVisible,
            'has_terms'            => 0,
            'billing_mode'         => $movedMode,
            'price_amount'         => $movedPrice,
            'price_unit'           => $movedUnit,
            'term_length_label'    => trim((string)($movedTerm['term_length_label'] ?? '')),
            'renews_at_label'      => trim((string)($movedTerm['renews_at_label'] ?? '')),
            'save_badge_text'      => trim((string)($movedTerm['save_badge_text'] ?? '')),
            'stripe_hook'          => trim((string)($movedTerm['stripe_hook'] ?? '')),
            'terms'                => array(),
            'use_feature_headings' => $useFeatureHeadings,
            'features'             => $rawFeatures,
            'category_ids'         => $sourceCats,
            'sort_order'           => $sortOrder,
        );

        $savedNew = $this->saveCatalogProduct($newProductPayload);
        if (!empty($savedNew['error'])) {
            return $savedNew;
        }

        return array(
            'success'           => true,
            'source_product_id' => $sourceId,
            'new_product_id'    => (int)$savedNew['product_id'],
            'remaining_terms'   => $rawTerms,
            'has_terms'         => $remainingCount > 1 ? 1 : 0,
            'moved_term'        => $movedTerm,
            'message'           => sprintf(
                'Copied "%s" to a new product listing and moved price $%s%s to the new product!',
                $name,
                $movedPrice,
                $movedUnit
            ),
        );
    }

    public function deleteCatalogProduct($productId) {
        $productId = (int)$productId;
        if ($productId <= 0) {
            return array('error' => 'Invalid product ID.');
        }
        $pdo = $this->db->getConnection();
        $pdo->prepare("DELETE FROM scp_catalog_product_categories WHERE product_id = ?")->execute(array($productId));
        $pdo->prepare("DELETE FROM scp_catalog_products WHERE id = ?")->execute(array($productId));
        return array('success' => true);
    }

    public function setCatalogProductVisibility($productId, $visible) {
        $visVal = !empty($visible) ? 1 : 0;
        if (is_numeric($productId) && (int)$productId > 0) {
            $stmt = $this->db->getConnection()->prepare("UPDATE scp_catalog_products SET portal_visible = ? WHERE id = ?");
            $stmt->execute(array($visVal, (int)$productId));
            return array('success' => true, 'portal_visible' => $visVal);
        }
        return $this->setProductVisibility($productId, $visible);
    }

    public function toggleCatalogProductCategory($productId, $categoryId, $assign = true) {
        $categoryId = (int)$categoryId;
        if (is_numeric($productId) && (int)$productId > 0 && $categoryId > 0) {
            $pid = (int)$productId;
            $pdo = $this->db->getConnection();
            if ($assign) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO scp_catalog_product_categories (product_id, category_id) VALUES (?, ?)");
                $stmt->execute(array($pid, $categoryId));
            } else {
                $stmt = $pdo->prepare("DELETE FROM scp_catalog_product_categories WHERE product_id = ? AND category_id = ?");
                $stmt->execute(array($pid, $categoryId));
            }
            return array('success' => true);
        }
        return $this->toggleProductCategory($productId, $categoryId, $assign);
    }

    public function setProductVisibility($priceId, $visible) {
        $priceId = trim((string)$priceId);
        if (empty($priceId)) {
            return array('error' => 'Invalid product Price ID.');
        }
        $visVal = !empty($visible) ? 1 : 0;
        if (ctype_digit($priceId)) {
            $stmt = $this->db->getConnection()->prepare("UPDATE scp_catalog_products SET portal_visible = ? WHERE id = ?");
            $stmt->execute(array($visVal, (int)$priceId));
            return array('success' => true, 'portal_visible' => $visVal);
        }
        $stmt = $this->db->getConnection()->prepare("UPDATE scp_stripe_products SET portal_visible = ? WHERE price_id = ?");
        $stmt->execute(array($visVal, $priceId));
        return array('success' => true, 'portal_visible' => $visVal);
    }

    public function toggleProductCategory($priceId, $categoryId, $assign = true) {
        $priceId    = trim((string)$priceId);
        $categoryId = (int)$categoryId;
        if (empty($priceId) || $categoryId <= 0) {
            return array('error' => 'Invalid product or category selection.');
        }
        if (ctype_digit($priceId)) {
            return $this->toggleCatalogProductCategory((int)$priceId, $categoryId, $assign);
        }
        $pdo = $this->db->getConnection();
        if ($assign) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO scp_product_category_map (price_id, category_id) VALUES (?, ?)");
            $stmt->execute(array($priceId, $categoryId));
        } else {
            $stmt = $pdo->prepare("DELETE FROM scp_product_category_map WHERE price_id = ? AND category_id = ?");
            $stmt->execute(array($priceId, $categoryId));
        }
        return array('success' => true);
    }

    public function getImportedProducts() {
        $this->ensureProductTablesAndSeed();
        try {
            $rows = $this->db->getConnection()->query("SELECT * FROM scp_stripe_products WHERE active = 1 ORDER BY name ASC, unit_amount ASC")->fetchAll();
            $catMap = $this->getProductCategoryMappings();
            foreach ($rows as &$r) {
                $pid = $r['price_id'];
                $r['portal_visible'] = isset($r['portal_visible']) ? (int)$r['portal_visible'] : 1;
                $r['category_ids']   = $catMap[$pid]['category_ids'] ?? array();
                $r['categories']     = $catMap[$pid]['categories'] ?? array();
            }
            usort($rows, function ($a, $b) {
                $cmp = strnatcasecmp($a['name'] ?? '', $b['name'] ?? '');
                return $cmp !== 0 ? $cmp : (((int)($a['unit_amount'] ?? 0)) <=> ((int)($b['unit_amount'] ?? 0)));
            });
            return $rows;
        } catch (Exception $e) {
            return array();
        }
    }

    public function getImportedSubscriptions() {
        try {
            return $this->db->getConnection()->query(
                "SELECT s.*, u.email AS user_email, u.first_name, u.last_name
                 FROM scp_stripe_subscriptions s
                 LEFT JOIN scp_users u ON s.user_id = u.id
                 ORDER BY s.created_ts DESC LIMIT 100"
            )->fetchAll();
        } catch (Exception $e) {
            return array();
        }
    }

    public function getImportedInvoices() {
        try {
            return $this->db->getConnection()->query(
                "SELECT i.*, u.email AS user_email, u.first_name, u.last_name
                 FROM scp_stripe_invoices i
                 LEFT JOIN scp_users u ON i.user_id = u.id
                 ORDER BY CASE WHEN i.status IN ('open', 'past_due', 'uncollectible') THEN 0 ELSE 1 END ASC, i.created_ts DESC LIMIT 500"
            )->fetchAll();
        } catch (Exception $e) {
            return array();
        }
    }

    public function getImportedPayments() {
        try {
            return $this->db->getConnection()->query(
                "SELECT p.*, u.email AS user_email, u.first_name, u.last_name
                 FROM scp_stripe_payments p
                 LEFT JOIN scp_users u ON p.user_id = u.id
                 ORDER BY p.created_ts DESC LIMIT 100"
            )->fetchAll();
        } catch (Exception $e) {
            return array();
        }
    }

    public function getImportedQuotes() {
        try {
            return $this->db->getConnection()->query(
                "SELECT q.*, u.email AS user_email, u.first_name, u.last_name
                 FROM scp_stripe_quotes q
                 LEFT JOIN scp_users u ON q.user_id = u.id
                 ORDER BY q.created_ts DESC LIMIT 100"
            )->fetchAll();
        } catch (Exception $e) {
            return array();
        }
    }

    /**
     * Determine whether an invoice (from Stripe API or local mirror table) is Past-Due
     */
    public static function isInvoicePastDue($inv) {
        if (!is_array($inv)) {
            return false;
        }
        $status = strtolower((string)($inv['status'] ?? ''));
        if (in_array($status, array('paid', 'void', 'draft'), true)) {
            return false;
        }
        if (in_array($status, array('past_due', 'uncollectible'), true)) {
            return true;
        }
        if ($status === 'open') {
            $now = time();
            $dueDate = !empty($inv['due_date']) ? (int)$inv['due_date'] : 0;
            if ($dueDate > 0) {
                return $dueDate < $now;
            }
            $created = !empty($inv['created_ts']) ? (int)$inv['created_ts'] : (!empty($inv['created']) ? (int)$inv['created'] : 0);
            if ($created > 0 && ($now - $created) > 86400) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determine whether a quote (from Stripe API or local mirror table) has passed its acceptance due date
     */
    public static function isQuotePastDue($qt) {
        if (!is_array($qt)) {
            return false;
        }
        $status = strtolower((string)($qt['status'] ?? 'open'));
        if ($status === 'accepted') {
            return false;
        }
        $expiresAt = !empty($qt['expires_at']) ? (int)$qt['expires_at'] : 0;
        if ($expiresAt > 0 && $expiresAt < time()) {
            return true;
        }
        return false;
    }

    /**
     * Compute Executive Billing Summary for Admin Dashboard Overview
     */
    public function getExecutiveBillingSummary($importedInvoices = array(), $importedPayments = array(), $importedSubscriptions = array(), $importedQuotes = array()) {
        $summary = array(
            'outstanding_balance_cents'  => 0,
            'past_due_balance_cents'     => 0,
            'open_invoices_count'        => 0,
            'past_due_invoices_count'    => 0,
            'open_quotes_count'          => 0,
            'past_due_quotes_count'      => 0,
            'active_subscriptions_count' => 0,
            'active_subs_count'          => 0,
            'mrr_cents'                  => 0,
            'recent_revenue_30d_cents'   => 0,
            'collected_30d_cents'        => 0,
            'total_revenue_cents'        => 0,
            'collected_total_cents'      => 0,
            'recent_payments_30d_count'  => 0,
            'new_purchases_30d_count'    => 0,
            'outstanding_invoices'       => array(),
            'outstanding_items'          => array(),
            'recent_purchases'           => array(),
        );

        $thirtyDaysAgo = time() - (30 * 86400);

        foreach ($importedInvoices as $inv) {
            $status   = strtolower((string)($inv['status'] ?? ''));
            $amtDue   = (int)($inv['amount_due'] ?? 0);
            $amtPaid  = (int)($inv['amount_paid'] ?? 0);
            $unpaid   = max(0, $amtDue - $amtPaid);
            if ($unpaid === 0 && in_array($status, array('open', 'past_due', 'uncollectible'), true)) {
                $unpaid = (int)($inv['total_cents'] ?? $amtDue);
            }
            $isPastDue = self::isInvoicePastDue($inv);

            if (in_array($status, array('open', 'past_due', 'uncollectible'), true) && $unpaid > 0) {
                $summary['outstanding_balance_cents'] += $unpaid;
                $summary['open_invoices_count']++;
                if ($isPastDue) {
                    $summary['past_due_invoices_count']++;
                    $summary['past_due_balance_cents'] += $unpaid;
                }
                $inv['unpaid_cents'] = $unpaid;
                $inv['is_past_due']  = $isPastDue;
                $summary['outstanding_invoices'][] = $inv;
            }
        }

        // Sort outstanding items so Past-Due invoices appear first, then highest balance
        usort($summary['outstanding_invoices'], function ($a, $b) {
            if (!empty($a['is_past_due']) !== !empty($b['is_past_due'])) {
                return !empty($a['is_past_due']) ? -1 : 1;
            }
            return ((int)($b['unpaid_cents'] ?? 0)) <=> ((int)($a['unpaid_cents'] ?? 0));
        });
        $summary['outstanding_items'] = $summary['outstanding_invoices'];

        foreach ($importedQuotes as $qt) {
            $qStatus = strtolower((string)($qt['status'] ?? ''));
            $qPastDue = self::isQuotePastDue($qt);
            if ($qPastDue) {
                $summary['past_due_quotes_count']++;
            }
            if ($qStatus === 'open') {
                $summary['open_quotes_count']++;
            }
        }

        foreach ($importedSubscriptions as $sub) {
            $sStatus = strtolower((string)($sub['status'] ?? ''));
            $subCreatedTs = (int)($sub['created_ts'] ?? 0);
            if (in_array($sStatus, array('active', 'trialing', 'past_due'), true)) {
                $summary['active_subscriptions_count']++;
                $summary['active_subs_count']++;
                $amt = (int)($sub['amount_cents'] ?? 0);
                $interval = strtolower((string)($sub['interval_unit'] ?? 'month'));
                if ($interval === 'year') {
                    $summary['mrr_cents'] += (int)round($amt / 12);
                } else {
                    $summary['mrr_cents'] += $amt;
                }
            }
            if ($subCreatedTs >= $thirtyDaysAgo) {
                $summary['new_purchases_30d_count']++;
            }
            $summary['recent_purchases'][] = array(
                'type'        => 'Subscription',
                'user_id'     => !empty($sub['user_id']) ? (int)$sub['user_id'] : 0,
                'client_name' => trim(($sub['first_name'] ?? '') . ' ' . ($sub['last_name'] ?? '')) ?: ($sub['user_email'] ?: $sub['customer_id']),
                'client_email'=> $sub['user_email'] ?? '',
                'item_name'   => $sub['plan_name'] ?: 'Cloud Subscription',
                'amount_cents'=> (int)($sub['amount_cents'] ?? 0),
                'currency'    => strtoupper($sub['currency'] ?? 'USD'),
                'interval'    => $sub['interval_unit'] ?? 'month',
                'status'      => $sub['status'] ?? 'active',
                'date_ts'     => $subCreatedTs,
                'created_ts'  => $subCreatedTs,
                'link'        => '',
            );
        }

        foreach ($importedPayments as $pay) {
            $pStatus = strtolower((string)($pay['status'] ?? ''));
            $isRefunded = !empty($pay['refunded']);
            $amt = (int)($pay['amount_cents'] ?? 0);
            $ts  = (int)($pay['created_ts'] ?? 0);

            if ($pStatus === 'succeeded' && !$isRefunded) {
                $summary['total_revenue_cents'] += $amt;
                $summary['collected_total_cents'] += $amt;
                if ($ts >= $thirtyDaysAgo) {
                    $summary['recent_revenue_30d_cents'] += $amt;
                    $summary['collected_30d_cents'] += $amt;
                    $summary['recent_payments_30d_count']++;
                }
                $summary['recent_purchases'][] = array(
                    'type'        => 'Payment',
                    'user_id'     => !empty($pay['user_id']) ? (int)$pay['user_id'] : 0,
                    'client_name' => trim(($pay['first_name'] ?? '') . ' ' . ($pay['last_name'] ?? '')) ?: ($pay['user_email'] ?: ($pay['customer_email'] ?: $pay['customer_id'])),
                    'client_email'=> $pay['user_email'] ?: ($pay['customer_email'] ?? ''),
                    'item_name'   => $pay['description'] ?: 'Stripe Product / Invoice Payment',
                    'amount_cents'=> $amt,
                    'currency'    => strtoupper($pay['currency'] ?? 'USD'),
                    'interval'    => '',
                    'status'      => 'succeeded',
                    'date_ts'     => $ts,
                    'created_ts'  => $ts,
                    'link'        => $pay['receipt_url'] ?? '',
                );
            }
        }

        usort($summary['recent_purchases'], function ($a, $b) {
            return ((int)($b['date_ts'] ?? 0)) <=> ((int)($a['date_ts'] ?? 0));
        });
        $summary['recent_purchases'] = array_slice($summary['recent_purchases'], 0, 12);

        return $summary;
    }

    /**
     * Get comprehensive billing, invoice, payment, subscription, and quote history for a single client
     */
    public function getClientBillingHistory($userId) {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return array('error' => 'Invalid client user ID.');
        }

        $pdo  = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM scp_users WHERE id = ? LIMIT 1");
        $stmt->execute(array($userId));
        $user = $stmt->fetch();

        if (!$user) {
            return array('error' => 'Client account not found.');
        }

        $customerId  = trim($user['stripe_customer_id'] ?? '');
        $clientEmail = trim($user['email'] ?? '');
        $clientName  = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $clientEmail;

        // Pull invoices from local mirror table (and merge live from Stripe if customerId is set)
        $invStmt = $pdo->prepare(
            "SELECT * FROM scp_stripe_invoices
             WHERE user_id = ? OR (customer_id != '' AND customer_id = ?) OR (customer_email != '' AND LOWER(customer_email) = LOWER(?))
             ORDER BY created_ts DESC"
        );
        $invStmt->execute(array($userId, $customerId, $clientEmail));
        $invoicesRows = $invStmt->fetchAll();

        if (empty($invoicesRows) && !empty($customerId)) {
            $liveInvs = $this->getInvoices($customerId);
            foreach ($liveInvs as $li) {
                $invoicesRows[] = array(
                    'invoice_id'         => $li['id'] ?? '',
                    'invoice_number'     => $li['number'] ?? ($li['id'] ?? ''),
                    'amount_due'         => (int)($li['amount_due'] ?? 0),
                    'amount_paid'        => (int)($li['amount_paid'] ?? 0),
                    'total_cents'        => (int)($li['total'] ?? 0),
                    'currency'           => strtoupper($li['currency'] ?? 'USD'),
                    'status'             => $li['status'] ?? 'draft',
                    'hosted_invoice_url' => $li['hosted_invoice_url'] ?? '',
                    'invoice_pdf'        => $li['invoice_pdf'] ?? '',
                    'created_ts'         => !empty($li['created']) ? (int)$li['created'] : 0,
                    'due_date'           => !empty($li['due_date']) ? (int)$li['due_date'] : null,
                );
            }
        }

        $payStmt = $pdo->prepare(
            "SELECT * FROM scp_stripe_payments
             WHERE user_id = ? OR (customer_id != '' AND customer_id = ?) OR (customer_email != '' AND LOWER(customer_email) = LOWER(?))
             ORDER BY created_ts DESC"
        );
        $payStmt->execute(array($userId, $customerId, $clientEmail));
        $paymentsRows = $payStmt->fetchAll();

        if (empty($paymentsRows) && !empty($customerId)) {
            $livePays = $this->getPayments($customerId);
            foreach ($livePays as $lp) {
                $card = $lp['payment_method_details']['card'] ?? array();
                $paymentsRows[] = array(
                    'charge_id'    => $lp['id'] ?? '',
                    'amount_cents' => (int)($lp['amount'] ?? 0),
                    'currency'     => strtoupper($lp['currency'] ?? 'USD'),
                    'status'       => $lp['status'] ?? 'succeeded',
                    'paid'         => !empty($lp['paid']) ? 1 : 0,
                    'refunded'     => !empty($lp['refunded']) ? 1 : 0,
                    'description'  => $lp['description'] ?: 'Stripe Payment',
                    'receipt_url'  => $lp['receipt_url'] ?? '',
                    'card_brand'   => strtoupper($card['brand'] ?? ''),
                    'card_last4'   => $card['last4'] ?? '',
                    'created_ts'   => !empty($lp['created']) ? (int)$lp['created'] : 0,
                );
            }
        }

        $subStmt = $pdo->prepare(
            "SELECT * FROM scp_stripe_subscriptions
             WHERE user_id = ? OR (customer_id != '' AND customer_id = ?)
             ORDER BY created_ts DESC"
        );
        $subStmt->execute(array($userId, $customerId));
        $subsRows = $subStmt->fetchAll();

        $qtStmt = $pdo->prepare(
            "SELECT * FROM scp_stripe_quotes
             WHERE user_id = ? OR (customer_id != '' AND customer_id = ?)
             ORDER BY created_ts DESC"
        );
        $qtStmt->execute(array($userId, $customerId));
        $quotesRows = $qtStmt->fetchAll();

        $outstandingCents  = 0;
        $pastDueCents      = 0;
        $openInvCount      = 0;
        $pastDueInvCount   = 0;
        $totalPaidCents    = 0;
        $activeSubsCount   = 0;
        $formattedInvoices = array();
        $outstandingItems  = array();

        foreach ($invoicesRows as $inv) {
            $status    = strtolower((string)($inv['status'] ?? 'draft'));
            $amtDue    = (int)($inv['amount_due'] ?? 0);
            $amtPaid   = (int)($inv['amount_paid'] ?? 0);
            $total     = (int)($inv['total_cents'] ?? $amtDue);
            $unpaid    = max(0, $amtDue - $amtPaid);
            if ($unpaid === 0 && in_array($status, array('open', 'past_due', 'uncollectible'), true)) {
                $unpaid = $total ?: $amtDue;
            }
            $isPastDue = self::isInvoicePastDue($inv);

            $item = array(
                'invoice_id'         => $inv['invoice_id'] ?? '',
                'invoice_number'     => !empty($inv['invoice_number']) ? $inv['invoice_number'] : ($inv['invoice_id'] ?? ''),
                'amount_due'         => $unpaid > 0 ? $unpaid : $amtDue,
                'amount_paid'        => $amtPaid,
                'total_cents'        => $total,
                'unpaid_cents'       => $unpaid,
                'currency'           => strtoupper($inv['currency'] ?? 'USD'),
                'status'             => $status,
                'is_past_due'        => $isPastDue,
                'created_ts'         => !empty($inv['created_ts']) ? (int)$inv['created_ts'] : 0,
                'due_date'           => !empty($inv['due_date']) ? (int)$inv['due_date'] : null,
                'hosted_invoice_url' => $inv['hosted_invoice_url'] ?? '',
                'invoice_pdf'        => $inv['invoice_pdf'] ?? '',
            );
            $formattedInvoices[] = $item;

            if (in_array($status, array('open', 'past_due', 'uncollectible'), true) && $unpaid > 0) {
                $outstandingCents += $unpaid;
                $openInvCount++;
                if ($isPastDue) {
                    $pastDueInvCount++;
                    $pastDueCents += $unpaid;
                }
                $outstandingItems[] = $item;
            }
        }

        $formattedPayments = array();
        foreach ($paymentsRows as $pay) {
            $amt = (int)($pay['amount_cents'] ?? 0);
            $isRefunded = !empty($pay['refunded']);
            $pStatus = strtolower((string)($pay['status'] ?? 'succeeded'));
            if ($pStatus === 'succeeded' && !$isRefunded) {
                $totalPaidCents += $amt;
            }
            $formattedPayments[] = array(
                'charge_id'    => $pay['charge_id'] ?? '',
                'created_ts'   => !empty($pay['created_ts']) ? (int)$pay['created_ts'] : 0,
                'description'  => $pay['description'] ?: 'Stripe Payment',
                'amount_cents' => $amt,
                'currency'     => strtoupper($pay['currency'] ?? 'USD'),
                'card_brand'   => $pay['card_brand'] ?? '',
                'card_last4'   => $pay['card_last4'] ?? '',
                'status'       => $pStatus,
                'refunded'     => $isRefunded ? 1 : 0,
                'receipt_url'  => $pay['receipt_url'] ?? '',
            );
        }

        $formattedSubs = array();
        foreach ($subsRows as $sub) {
            $sStatus = strtolower((string)($sub['status'] ?? 'active'));
            if (in_array($sStatus, array('active', 'trialing', 'past_due'), true)) {
                $activeSubsCount++;
            }
            $formattedSubs[] = array(
                'subscription_id'    => $sub['subscription_id'] ?? '',
                'plan_name'          => $sub['plan_name'] ?? 'Cloud Plan',
                'amount_cents'       => (int)($sub['amount_cents'] ?? 0),
                'currency'           => strtoupper($sub['currency'] ?? 'USD'),
                'interval_unit'      => $sub['interval_unit'] ?? 'month',
                'status'             => $sStatus,
                'current_period_end' => !empty($sub['current_period_end']) ? (int)$sub['current_period_end'] : 0,
            );
        }

        $formattedQuotes = array();
        foreach ($quotesRows as $qt) {
            $isPastDue = self::isQuotePastDue($qt);
            $formattedQuotes[] = array(
                'quote_id'         => $qt['quote_id'] ?? '',
                'quote_number'     => !empty($qt['quote_number']) ? $qt['quote_number'] : ($qt['quote_id'] ?? ''),
                'description'      => $qt['description'] ?: 'Custom Quote',
                'amount_total'     => (int)($qt['amount_total'] ?? 0),
                'currency'         => strtoupper($qt['currency'] ?? 'USD'),
                'status'           => $qt['status'] ?? 'open',
                'is_past_due'      => $isPastDue,
                'created_ts'       => !empty($qt['created_ts']) ? (int)$qt['created_ts'] : 0,
                'expires_at'       => !empty($qt['expires_at']) ? (int)$qt['expires_at'] : 0,
                'hosted_quote_url' => $qt['hosted_quote_url'] ?? '',
            );
        }

        $clientPayload = array(
            'id'                 => (int)$user['id'],
            'name'               => $clientName,
            'email'              => $clientEmail,
            'company'            => $user['company'] ?? '',
            'phone'              => $user['phone'] ?? '',
            'stripe_customer_id' => $customerId,
        );

        $summaryPayload = array(
            'outstanding_balance_cents'  => $outstandingCents,
            'past_due_balance_cents'     => $pastDueCents,
            'open_invoices_count'        => $openInvCount,
            'past_due_invoices_count'    => $pastDueInvCount,
            'total_paid_cents'           => $totalPaidCents,
            'active_subscriptions_count' => $activeSubsCount,
        );

        $billingData = array(
            'user'                 => $clientPayload,
            'client'               => $clientPayload,
            'summary'              => $summaryPayload,
            'outstanding_invoices' => $outstandingItems,
            'invoices'             => $formattedInvoices,
            'payments'             => $formattedPayments,
            'subscriptions'        => $formattedSubs,
            'quotes'               => $formattedQuotes,
        );

        return array(
            'success' => true,
            'billing' => $billingData,
        );
    }
}
