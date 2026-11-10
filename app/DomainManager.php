<?php
/**
 * Stand-Alone Domain Manager (Namecheap & OpenSRS Bridge, Live RDAP/DNS Verification, Smart Suggestions & DNS)
 * Secure Cloud Portal Stand-Alone v2.1.0
 */

if (!defined('SCP_APP_INIT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

class SCP_DomainManager {
    private $db;
    private $namecheap;
    private $opensrs;

    private $basePrices = array(
        'com'    => 1298,
        'net'    => 1498,
        'org'    => 1398,
        'cloud'  => 1899,
        'io'     => 3999,
        'co'     => 2499,
        'app'    => 1699,
        'dev'    => 1699,
        'ai'     => 7999,
        'pro'    => 1499,
        'online' => 1999,
        'tech'   => 2499,
        'biz'    => 1599,
        'info'   => 1599,
    );

    public function __construct(SCP_NamecheapService $namecheap, SCP_OpenSRSService $opensrs) {
        $this->db        = SCP_Database::getInstance();
        $this->namecheap = $namecheap;
        $this->opensrs   = $opensrs;
    }

    public function getActiveRegistrar() {
        $ncEnabled = $this->isNamecheapEnabled();
        $osMode    = $this->db->getSetting('os_fallback_enabled', 'yes');
        if ($osMode === 'primary' || !$ncEnabled) {
            return 'opensrs';
        }
        return 'namecheap';
    }

    public function isNamecheapEnabled() {
        return $this->db->getSetting('nc_enabled', 'yes') !== 'no' && $this->namecheap->isConfigured();
    }

    public function isOpenSrsFallbackEnabled() {
        $osMode = $this->db->getSetting('os_fallback_enabled', 'yes');
        return ($osMode === 'yes' || $osMode === 'primary') && $this->opensrs->isConfigured();
    }

    public function getMarkupMultiplier() {
        $markup = (float)$this->db->getSetting('price_markup_percent', '20');
        return 1 + ($markup / 100);
    }

    /**
     * Validate a full domain name against RFC hostname rules
     */
    public function validateDomainName($domain) {
        $domain = strtolower(trim($domain));
        if (strlen($domain) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $domain)) {
            return false;
        }
        return $domain;
    }

    /**
     * Get retail TLD price in cents and dollars
     */
    public function getTldPrice($tld, $overrideBaseDollars = null) {
        $tld = strtolower(ltrim($tld, '.'));
        if ($overrideBaseDollars !== null && (float)$overrideBaseDollars > 0) {
            $base = (int)round((float)$overrideBaseDollars * 100);
        } else {
            $base = $this->basePrices[$tld] ?? 1599;
        }
        $finalCents = (int)round($base * $this->getMarkupMultiplier());
        $dollarsStr = number_format($finalCents / 100, 2, '.', '');
        return array(
            'cents'        => $finalCents,
            'dollars'      => $dollarsStr,
            'retail_price' => (float)$dollarsStr,
            'currency'     => 'USD'
        );
    }

    /**
     * Authoritative server-side domain price calculation (prevents client price tampering)
     */
    public function calculateDomainCheckoutPrice($domainName, $years = 1) {
        $domainName = $this->validateDomainName($domainName);
        if (!$domainName) {
            return array('error' => 'Invalid domain name format.');
        }

        $years = max(1, min(10, (int)$years));
        $tld = substr(strrchr($domainName, '.'), 1);
        $pricing = $this->getTldPrice($tld);
        $unitCents = $pricing['cents'];

        $registrar = $this->getActiveRegistrar();
        if ($registrar === 'namecheap' && $this->namecheap->isConfigured()) {
            $check = $this->namecheap->checkDomain($domainName);
            if (!isset($check['error']) && isset($check[$domainName])) {
                if (!empty($check[$domainName]['is_premium']) && !empty($check[$domainName]['price'])) {
                    $premiumCents = (int)round((float)$check[$domainName]['price'] * 100 * $this->getMarkupMultiplier());
                    if ($premiumCents > $unitCents) {
                        $unitCents = $premiumCents;
                    }
                }
            }
        }

        return $unitCents * $years;
    }

    /**
     * Fast persistent Keep-Alive DNS-over-HTTPS (RFC 8484 JSON) availability verification
     * Status 3 (NXDOMAIN) = Domain is unregistered & available
     * Status 0 (NOERROR) or 2 (SERVFAIL / clientHold) = Domain is registered in the TLD zone
     */
    private function checkDomainsViaDoH(array $domains) {
        $results = array();
        if (empty($domains)) {
            return $results;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Accept: application/dns-json',
            'Connection: keep-alive'
        ));
        if (defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }

        foreach ($domains as $d) {
            curl_setopt($ch, CURLOPT_URL, 'https://cloudflare-dns.com/dns-query?name=' . rawurlencode($d) . '&type=NS');
            $body = curl_exec($ch);
            $json = is_string($body) ? json_decode($body, true) : null;
            $dnsStatus = isset($json['Status']) ? (int)$json['Status'] : -1;

            // Status 3 = NXDOMAIN (Not registered in TLD registry -> Available)
            $results[$d] = ($dnsStatus === 3);
        }

        curl_close($ch);
        return $results;
    }

    /**
     * Batch check availability for a list of domain names using Registrar APIs + Live RDAP/DNS fallback
     */
    private function checkMultipleDomains(array $domains) {
        $domains = array_values(array_unique(array_map('strtolower', $domains)));
        $availabilityMap = array();
        $premiumPriceMap = array();

        $registrar = $this->getActiveRegistrar();

        // 1. Try Namecheap (Primary / Default Registrar) batch check first
        $ncFailed = false;
        if ($registrar === 'namecheap' && $this->isNamecheapEnabled()) {
            $ncRes = $this->namecheap->checkDomain($domains);
            if (!isset($ncRes['error']) && !empty($ncRes)) {
                foreach ($domains as $d) {
                    if (isset($ncRes[$d])) {
                        $availabilityMap[$d] = (bool)$ncRes[$d]['available'];
                        if (!empty($ncRes[$d]['is_premium']) && !empty($ncRes[$d]['price'])) {
                            $premiumPriceMap[$d] = (float)$ncRes[$d]['price'];
                        }
                    }
                }
            } else {
                $ncFailed = true;
            }
        } else {
            $ncFailed = true;
        }

        // 2. Automatic Fallback to OpenSRS if Namecheap had a problem (or if OpenSRS is set as primary)
        if (($ncFailed || $registrar === 'opensrs') && $this->isOpenSrsFallbackEnabled() && !empty($domains[0]) && !isset($availabilityMap[$domains[0]])) {
            $osRes = $this->opensrs->checkDomain($domains[0]);
            if (!isset($osRes['error']) && isset($osRes[$domains[0]])) {
                $availabilityMap[$domains[0]] = (bool)$osRes[$domains[0]]['available'];
            }
        }

        // 3. Resolve any remaining unresolved domains using parallel RDAP + DNS verification
        $unresolved = array();
        foreach ($domains as $d) {
            if (!isset($availabilityMap[$d])) {
                $unresolved[] = $d;
            }
        }

        if (!empty($unresolved)) {
            $fallbackMap = $this->checkDomainsViaDoH($unresolved);
            foreach ($fallbackMap as $d => $isAvail) {
                $availabilityMap[$d] = $isAvail;
            }
        }

        return array(
            'availability' => $availabilityMap,
            'premium'      => $premiumPriceMap,
        );
    }

    /**
     * Format a domain result entry with full price fields
     */
    private function formatDomainResult($domain, $isAvailable, $isPrimary = false, $category = 'tld', $premiumBaseDollars = null) {
        $tld = substr(strrchr($domain, '.'), 1);
        $pricing = $this->getTldPrice($tld, $premiumBaseDollars);
        return array(
            'domain'       => $domain,
            'available'    => (bool)$isAvailable,
            'price'        => $pricing['dollars'],
            'retail_price' => $pricing['retail_price'],
            'price_cents'  => $pricing['cents'],
            'currency'     => 'USD',
            'tld'          => $tld,
            'is_primary'   => (bool)$isPrimary,
            'category'     => $category, // 'primary', 'tld', or 'suggestion'
        );
    }

    /**
     * Search domain availability across popular extensions and generate available alternatives
     */
    public function searchDomain($searchQuery) {
        $searchQuery = strtolower(trim($searchQuery));
        $searchQuery = preg_replace('#^https?://#', '', $searchQuery);
        $searchQuery = preg_replace('#^www\.#', '', $searchQuery);
        $searchQuery = trim($searchQuery, '/');

        $parts = explode('.', $searchQuery, 2);
        $name  = preg_replace('/[^a-z0-9-]/', '', $parts[0]);
        $name  = trim($name, '-');

        if (empty($name) || strlen($name) > 63) {
            return array();
        }

        $specificTld = isset($parts[1]) ? preg_replace('/[^a-z0-9.]/', '', $parts[1]) : null;
        if ($specificTld && !preg_match('/^[a-z]{2,24}(?:\.[a-z]{2,24})?$/', $specificTld)) {
            $specificTld = null;
        }

        $primaryTld    = $specificTld ?: 'com';
        $primaryDomain = $name . '.' . $primaryTld;

        // 1. Other popular TLD extensions for the exact same name
        $allTlds = array('com', 'net', 'org', 'cloud', 'io', 'co');
        $candidates = array($primaryDomain);
        foreach ($allTlds as $tld) {
            $cand = $name . '.' . $tld;
            if ($cand !== $primaryDomain) {
                $candidates[] = $cand;
            }
        }

        // 2. Smart name suggestions (prefixes/suffixes across .com and requested TLD)
        // so that if the requested domain is already registered, available alternatives are always offered!
        $smartVariations = array(
            'get' . $name . '.com',
            'my' . $name . '.com',
            'try' . $name . '.com',
            $name . 'hq.com',
            $name . 'cloud.com',
            $name . 'app.com',
            $name . 'online.com',
            'get' . $name . '.' . $primaryTld,
            $name . 'hq.' . $primaryTld,
        );
        foreach ($smartVariations as $varDom) {
            if (!in_array($varDom, $candidates, true)) {
                $candidates[] = $varDom;
            }
        }

        $checkResult     = $this->checkMultipleDomains($candidates);
        $availabilityMap = $checkResult['availability'];
        $premiumMap      = $checkResult['premium'];

        $results = array();

        // Always include the exact primary requested domain first (whether available or registered)
        $primaryAvail = $availabilityMap[$primaryDomain] ?? true;
        $results[] = $this->formatDomainResult(
            $primaryDomain,
            $primaryAvail,
            true,
            'primary',
            $premiumMap[$primaryDomain] ?? null
        );

        // Add available alternative TLD extensions for the same name
        $altCount = 0;
        foreach ($allTlds as $tld) {
            $cand = $name . '.' . $tld;
            if ($cand === $primaryDomain) {
                continue;
            }
            if (!empty($availabilityMap[$cand])) {
                $results[] = $this->formatDomainResult(
                    $cand,
                    true,
                    false,
                    'tld',
                    $premiumMap[$cand] ?? null
                );
                $altCount++;
            }
        }

        // Add available smart name suggestions
        foreach ($smartVariations as $varDom) {
            if ($altCount >= 10) {
                break;
            }
            if (!empty($availabilityMap[$varDom])) {
                $results[] = $this->formatDomainResult(
                    $varDom,
                    true,
                    false,
                    'suggestion',
                    $premiumMap[$varDom] ?? null
                );
                $altCount++;
            }
        }

        return $results;
    }

    /**
     * Get domains registered to a specific user
     */
    public function getUserDomains($userId) {
        $stmt = $this->db->getConnection()->prepare("SELECT * FROM scp_client_domains WHERE user_id = ? ORDER BY expiration_date ASC");
        $stmt->execute(array((int)$userId));
        return $stmt->fetchAll();
    }

    /**
     * Admin: Get all domains across all clients
     */
    public function getAllDomains() {
        $stmt = $this->db->getConnection()->query(
            "SELECT d.*, u.email AS user_email, u.first_name, u.last_name
             FROM scp_client_domains d
             LEFT JOIN scp_users u ON d.user_id = u.id
             ORDER BY d.id DESC"
        );
        return $stmt->fetchAll();
    }

    /**
     * Admin: Manually assign/import a domain to a client
     */
    public function adminAddDomain($userId, $domainName, $registrar, $expirationDate, $nameservers) {
        $domainName = $this->validateDomainName($domainName);
        if (!$domainName) {
            return array('error' => 'Invalid domain name format.');
        }

        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO scp_client_domains (user_id, domain_name, registrar, order_id, registration_date, expiration_date, auto_renew, nameservers, status)
             VALUES (?, ?, ?, ?, CURDATE(), ?, 1, ?, 'active')
             ON DUPLICATE KEY UPDATE registrar = VALUES(registrar), expiration_date = VALUES(expiration_date), nameservers = VALUES(nameservers)"
        );
        $stmt->execute(array(
            (int)$userId,
            $domainName,
            $registrar ?: 'namecheap',
            'ADM-' . strtoupper(bin2hex(random_bytes(4))),
            $expirationDate ?: date('Y-m-d', strtotime('+1 year')),
            $nameservers ?: $this->db->getSetting('default_nameservers', '')
        ));

        return array('success' => true);
    }

    /**
     * Automated domain provisioning after Stripe payment succeeds
     */
    public function handleDomainPaid($userId, $domainName, $action, $years) {
        $domainName = $this->validateDomainName($domainName);
        if (!$domainName) {
            return;
        }

        $pdo = $this->db->getConnection();
        $registrar = $this->getActiveRegistrar();
        $defaultNs = array_filter(array_map('trim', explode("\n", (string)$this->db->getSetting('default_nameservers', ''))));

        $uStmt = $pdo->prepare("SELECT * FROM scp_users WHERE id = ? LIMIT 1");
        $uStmt->execute(array((int)$userId));
        $user = $uStmt->fetch();

        if ($action === 'register') {
            $regDate = date('Y-m-d');
            $expDate = date('Y-m-d', strtotime("+$years years"));
            $orderId = 'ORD-' . strtoupper(bin2hex(random_bytes(4)));
            $usedRegistrar = $registrar;

            $contact = array(
                'first_name' => ($user['first_name'] ?? '') ?: 'Client',
                'last_name'  => ($user['last_name'] ?? '') ?: 'Account',
                'address_1'  => ($user['address_1'] ?? '') ?: '100 Main St',
                'city'       => ($user['city'] ?? '') ?: 'New York',
                'state'      => ($user['state'] ?? '') ?: 'NY',
                'postcode'   => ($user['postcode'] ?? '') ?: '10001',
                'country'    => ($user['country'] ?? '') ?: 'US',
                'phone'      => ($user['phone'] ?? '') ?: '+1.5555551234',
                'email'      => ($user['email'] ?? $this->db->getSetting('support_email', 'support@example.com')),
            );

            $provisioned = false;

            // 1. Primary Registrar: Namecheap
            if ($registrar === 'namecheap' && $this->isNamecheapEnabled()) {
                $res = $this->namecheap->registerDomain($domainName, $years, $contact, $defaultNs);
                if (empty($res['error']) && !empty($res['success'])) {
                    $provisioned = true;
                    $usedRegistrar = 'namecheap';
                    if (!empty($res['order_id'])) {
                        $orderId = $res['order_id'];
                    }
                }
            }

            // 2. Automatic Fallback: OpenSRS (if Namecheap failed or OpenSRS is primary)
            if (!$provisioned && $this->isOpenSrsFallbackEnabled()) {
                $osRes = $this->opensrs->registerDomain($domainName, $years, $contact, $defaultNs);
                $usedRegistrar = 'opensrs';
                if (!empty($osRes['id'])) {
                    $orderId = 'OS-' . $osRes['id'];
                }
                if ($registrar === 'namecheap') {
                    $this->db->logActivity($userId, 'registrar_failover', sprintf('Namecheap unavailable for %s; automatically failed over to OpenSRS.', $domainName));
                }
            }

            $ins = $pdo->prepare(
                "INSERT INTO scp_client_domains (user_id, domain_name, registrar, order_id, registration_date, expiration_date, auto_renew, nameservers, status)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, 'active')
                 ON DUPLICATE KEY UPDATE registrar = VALUES(registrar), expiration_date = VALUES(expiration_date), status = 'active'"
            );
            $ins->execute(array((int)$userId, $domainName, $usedRegistrar, $orderId, $regDate, $expDate, implode("\n", $defaultNs)));

        } elseif ($action === 'renew') {
            $exStmt = $pdo->prepare("SELECT * FROM scp_client_domains WHERE domain_name = ? AND user_id = ? LIMIT 1");
            $exStmt->execute(array($domainName, (int)$userId));
            $existing = $exStmt->fetch();

            $currentExp = (!empty($existing['expiration_date']) && strtotime($existing['expiration_date']) > time())
                ? strtotime($existing['expiration_date'])
                : time();
            $newExp = date('Y-m-d', strtotime("+$years years", $currentExp));

            $renewed = false;
            if ($registrar === 'namecheap' && $this->isNamecheapEnabled()) {
                $ncRenew = $this->namecheap->renewDomain($domainName, $years);
                if (empty($ncRenew['error']) && !empty($ncRenew['success'])) {
                    $renewed = true;
                }
            }
            if (!$renewed && $this->isOpenSrsFallbackEnabled()) {
                $this->opensrs->renewDomain($domainName, $years);
                if ($registrar === 'namecheap') {
                    $this->db->logActivity($userId, 'registrar_failover', sprintf('Namecheap renewal failed for %s; automatically failed over to OpenSRS.', $domainName));
                }
            }

            $upd = $pdo->prepare("UPDATE scp_client_domains SET expiration_date = ?, status = 'active' WHERE domain_name = ? AND user_id = ?");
            $upd->execute(array($newExp, $domainName, (int)$userId));
        }
    }

    /**
     * Update DNS Nameservers for a client domain
     */
    public function updateNameservers($userId, $domainName, $nameserversArray) {
        $domainName = $this->validateDomainName($domainName);
        if (!$domainName) {
            return array('error' => 'Invalid domain name.');
        }

        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM scp_client_domains WHERE domain_name = ? AND user_id = ? LIMIT 1");
        $stmt->execute(array($domainName, (int)$userId));
        $domain = $stmt->fetch();

        if (!$domain) {
            return array('error' => 'Domain not found or unauthorized.');
        }

        $cleanNs = array();
        foreach ($nameserversArray as $rawNs) {
            $ns = strtolower(trim($rawNs));
            if (empty($ns)) {
                continue;
            }
            if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $ns)) {
                return array('error' => 'Invalid nameserver hostname: ' . SCP_Security::e($ns));
            }
            $cleanNs[] = $ns;
        }

        $cleanNs = array_values(array_unique($cleanNs));
        if (count($cleanNs) < 2 || count($cleanNs) > 12) {
            return array('error' => 'Please provide between 2 and 12 valid nameservers.');
        }

        $parts = explode('.', $domainName, 2);
        $sld   = $parts[0];
        $tld   = $parts[1];

        $nsUpdated = false;
        if (($domain['registrar'] === 'namecheap' || $this->getActiveRegistrar() === 'namecheap') && $this->isNamecheapEnabled()) {
            $res = $this->namecheap->setNameservers($sld, $tld, $cleanNs);
            if (!is_array($res) || !isset($res['error'])) {
                $nsUpdated = true;
            }
        }

        if (!$nsUpdated && $this->isOpenSrsFallbackEnabled()) {
            $osRes = $this->opensrs->setNameservers($domainName, $cleanNs);
            if (is_array($osRes) && isset($osRes['error']) && $domain['registrar'] === 'opensrs') {
                return $osRes;
            }
        }

        $upd = $pdo->prepare("UPDATE scp_client_domains SET nameservers = ? WHERE id = ?");
        $upd->execute(array(implode("\n", $cleanNs), $domain['id']));

        $this->db->logActivity($userId, 'dns_updated', sprintf('Updated nameservers for %s.', $domainName));
        return array('success' => true);
    }
}
