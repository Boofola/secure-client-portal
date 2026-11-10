<?php
/**
 * Stand-Alone Namecheap XML API Client
 * Secure Cloud Portal Stand-Alone v2.0.0
 */

if (!defined('SCP_APP_INIT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

class SCP_NamecheapService {
    private $db;
    private $apiUrl;

    public function __construct() {
        $this->db = SCP_Database::getInstance();
        $sandbox = ($this->db->getSetting('nc_sandbox', 'no') === 'yes');
        $this->apiUrl = $sandbox
            ? 'https://api.sandbox.namecheap.com/xml.response'
            : 'https://api.namecheap.com/xml.response';
    }

    public function getApiUser() {
        return SCP_Security::getNamecheapApiUser();
    }

    public function getApiKey() {
        return SCP_Security::getNamecheapApiKey();
    }

    public function getUsername() {
        return SCP_Security::getNamecheapUsername();
    }

    public function isConfigured() {
        return !empty($this->getApiUser()) && !empty($this->getApiKey()) && !empty($this->getUsername());
    }

    public function request($command, $params = array()) {
        if (!$this->isConfigured()) {
            return array('error' => 'Namecheap API credentials are not configured.');
        }

        $clientIp = $this->db->getSetting('nc_client_ip', '') ?: ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');

        $defaultParams = array(
            'ApiUser'  => $this->getApiUser(),
            'ApiKey'   => $this->getApiKey(),
            'UserName' => $this->getUsername(),
            'ClientIP' => $clientIp,
            'Command'  => $command,
        );

        $body = array_merge($defaultParams, $params);
        $resp = SCP_HttpClient::request($this->apiUrl, 'POST', $body, array(), 45);

        if (empty($resp['body'])) {
            return array('error' => $resp['error'] ?: 'Empty response from Namecheap API.');
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($resp['body'], 'SimpleXMLElement', LIBXML_NONET);
        if (!$xml) {
            return array('error' => 'Failed to parse XML response from Namecheap.');
        }

        $status = (string)$xml['Status'];
        if ($status !== 'OK') {
            $errors = array();
            if (isset($xml->Errors->Error)) {
                foreach ($xml->Errors->Error as $err) {
                    $errors[] = (string)$err;
                }
            }
            return array('error' => !empty($errors) ? implode('; ', $errors) : 'Namecheap API error occurred.');
        }

        return $xml;
    }

    public function checkDomain($domainList) {
        $domainStr = is_array($domainList) ? implode(',', $domainList) : (string)$domainList;
        $xml = $this->request('namecheap.domains.check', array('DomainList' => $domainStr));

        if (is_array($xml) && isset($xml['error'])) {
            return $xml;
        }

        $results = array();
        if (isset($xml->CommandResponse->DomainCheckResult)) {
            foreach ($xml->CommandResponse->DomainCheckResult as $res) {
                $domain    = strtolower((string)$res['Domain']);
                $available = ((string)$res['Available'] === 'true');
                $isPremium = ((string)$res['IsPremiumName'] === 'true');
                $price     = $isPremium ? (float)((string)$res['PremiumRegistrationPrice']) : null;

                $results[$domain] = array(
                    'domain'     => $domain,
                    'available'  => $available,
                    'is_premium' => $isPremium,
                    'price'      => $price,
                );
            }
        }
        return $results;
    }

    public function registerDomain($domainName, $years = 1, $contact = array(), $nameservers = array()) {
        $defaults = array(
            'first_name' => 'Client',
            'last_name'  => 'Account',
            'address_1'  => '100 Main St',
            'city'       => 'New York',
            'state'      => 'NY',
            'postcode'   => '10001',
            'country'    => 'US',
            'phone'      => '+1.5555551234',
            'email'      => $this->db->getSetting('support_email', 'support@example.com'),
        );
        $contact = array_merge($defaults, array_filter($contact));

        $params = array(
            'DomainName' => $domainName,
            'Years'      => (int)$years,
        );
        if (!empty($nameservers)) {
            $params['Nameservers'] = is_array($nameservers) ? implode(',', $nameservers) : $nameservers;
        }

        $roles = array('Registrant', 'Tech', 'Admin', 'AuxBilling');
        foreach ($roles as $role) {
            $params[$role . 'FirstName']     = $contact['first_name'];
            $params[$role . 'LastName']      = $contact['last_name'];
            $params[$role . 'Address1']      = $contact['address_1'];
            $params[$role . 'City']          = $contact['city'];
            $params[$role . 'StateProvince'] = $contact['state'];
            $params[$role . 'PostalCode']    = $contact['postcode'];
            $params[$role . 'Country']       = $contact['country'];
            $params[$role . 'Phone']         = $contact['phone'];
            $params[$role . 'EmailAddress']  = $contact['email'];
        }

        $xml = $this->request('namecheap.domains.create', $params);
        if (is_array($xml) && isset($xml['error'])) {
            return $xml;
        }

        $resp = $xml->CommandResponse->DomainCreateResult;
        return array(
            'success'     => ((string)$resp['Registered'] === 'true'),
            'domain'      => (string)$resp['Domain'],
            'order_id'    => (string)$resp['OrderID'],
            'charged_amt' => (float)$resp['ChargedAmount'],
        );
    }

    public function renewDomain($domainName, $years = 1) {
        $xml = $this->request('namecheap.domains.renew', array(
            'DomainName' => $domainName,
            'Years'      => (int)$years,
        ));
        if (is_array($xml) && isset($xml['error'])) {
            return $xml;
        }
        $resp = $xml->CommandResponse->DomainRenewResult;
        return array(
            'success'  => ((string)$resp['Renew'] === 'true'),
            'domain'   => (string)$resp['DomainName'],
            'order_id' => (string)$resp['OrderID'],
        );
    }

    public function setNameservers($sld, $tld, $nameservers) {
        $nsString = is_array($nameservers) ? implode(',', array_map('trim', $nameservers)) : $nameservers;
        $xml = $this->request('namecheap.domains.dns.setCustom', array(
            'SLD'         => $sld,
            'TLD'         => $tld,
            'Nameservers' => $nsString,
        ));
        if (is_array($xml) && isset($xml['error'])) {
            return $xml;
        }
        $resp = $xml->CommandResponse->DomainDNSSetCustomResult;
        return ((string)$resp['Updated'] === 'true');
    }
}
