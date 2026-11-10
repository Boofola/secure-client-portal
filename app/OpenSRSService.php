<?php
/**
 * Stand-Alone OpenSRS XML-over-HTTPS API Client
 * Secure Cloud Portal Stand-Alone v2.1.0
 */

if (!defined('SCP_APP_INIT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

class SCP_OpenSRSService {
    private $db;
    private $apiUrl;

    public function __construct() {
        $this->db = SCP_Database::getInstance();
        $env = $this->db->getSetting('os_environment', 'live');
        $this->apiUrl = ($env === 'live')
            ? 'https://rr-n1-tor.opensrs.net:55443'
            : 'https://horizon.opensrs.net:55443';
    }

    public function getUsername() {
        return SCP_Security::getOpenSrsUsername();
    }

    public function getPrivateKey() {
        return SCP_Security::getOpenSrsKey();
    }

    public function isConfigured() {
        return !empty($this->getUsername()) && !empty($this->getPrivateKey());
    }

    public function sendRequest($action, $object, $attributes = array()) {
        if (!$this->isConfigured()) {
            return array('error' => 'OpenSRS API credentials are not configured.');
        }

        $xmlPayload = $this->buildXmlPayload($action, $object, $attributes);
        $username   = $this->getUsername();
        $privateKey = $this->getPrivateKey();

        // OpenSRS signature algorithm: md5(md5(xml + key) + key)
        $signature = md5(md5($xmlPayload . $privateKey) . $privateKey);

        $headers = array(
            'Content-Type' => 'text/xml',
            'X-Username'   => $username,
            'X-Signature'  => $signature,
        );

        $resp = SCP_HttpClient::request($this->apiUrl, 'POST', $xmlPayload, $headers, 25);
        if (empty($resp['body'])) {
            return array('error' => $resp['error'] ?: 'Empty response from OpenSRS.');
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($resp['body'], 'SimpleXMLElement', LIBXML_NONET);
        if (!$xml) {
            return array('error' => 'Failed to parse OpenSRS XML response.');
        }

        // Extract key-value items from OPS_envelope
        $parsed = array('_raw' => $resp['body']);
        $items = $xml->xpath('//data_block/dt_assoc/item');
        if (is_array($items)) {
            foreach ($items as $item) {
                $k = (string)$item['key'];
                if ($k !== '') {
                    $parsed[$k] = trim((string)$item);
                }
            }
        }

        if (isset($parsed['is_success']) && $parsed['is_success'] === '0') {
            return array('error' => $parsed['response_text'] ?? 'OpenSRS API returned an error.');
        }

        return $parsed;
    }

    private function buildXmlPayload($action, $object, $attributes) {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="no" ?>' . "\n";
        $xml .= '<!DOCTYPE OPS_envelope SYSTEM "ops.dtd">' . "\n";
        $xml .= '<OPS_envelope>' . "\n";
        $xml .= '  <header><version>0.9</version></header>' . "\n";
        $xml .= '  <body>' . "\n";
        $xml .= '    <data_block>' . "\n";
        $xml .= '      <dt_assoc>' . "\n";
        $xml .= '        <item key="protocol">XCP</item>' . "\n";
        $xml .= '        <item key="action">' . SCP_Security::e($action) . '</item>' . "\n";
        $xml .= '        <item key="object">' . SCP_Security::e($object) . '</item>' . "\n";
        $xml .= '        <item key="attributes">' . "\n";
        $xml .= $this->assocToXml($attributes, 5);
        $xml .= '        </item>' . "\n";
        $xml .= '      </dt_assoc>' . "\n";
        $xml .= '    </data_block>' . "\n";
        $xml .= '  </body>' . "\n";
        $xml .= '</OPS_envelope>';
        return $xml;
    }

    private function assocToXml($data, $indent = 0) {
        $pad = str_repeat('  ', $indent);
        $xml = $pad . "<dt_assoc>\n";
        foreach ($data as $key => $val) {
            $xml .= $pad . '  <item key="' . SCP_Security::e($key) . '">';
            if (is_array($val)) {
                $xml .= "\n" . $this->assocToXml($val, $indent + 2) . $pad . '  ';
            } else {
                $xml .= SCP_Security::e((string)$val);
            }
            $xml .= "</item>\n";
        }
        $xml .= $pad . "</dt_assoc>\n";
        return $xml;
    }

    public function checkDomain($domain) {
        $res = $this->sendRequest('LOOKUP', 'DOMAIN', array('domain' => $domain));
        if (isset($res['error'])) {
            return $res;
        }

        // OpenSRS response_code 210 = Domain available; 211 = Domain taken
        $code = $res['response_code'] ?? '';
        $raw  = $res['_raw'] ?? '';
        if ($code === '210' || stripos($raw, '>210<') !== false || stripos($raw, 'AVAILABLE') !== false) {
            $available = true;
        } elseif ($code === '211' || stripos($raw, '>211<') !== false || stripos($raw, 'TAKEN') !== false) {
            $available = false;
        } else {
            return array('error' => 'Unrecognized OpenSRS lookup status.');
        }

        return array(
            $domain => array(
                'domain'    => $domain,
                'available' => $available,
                'price'     => null
            )
        );
    }

    public function registerDomain($domain, $years = 1, $contact = array(), $nameservers = array()) {
        $attrs = array(
            'domain'              => $domain,
            'period'              => (int)$years,
            'reg_type'            => 'new',
            'custom_tech_contact' => 0,
            'auto_renew'          => 1
        );
        if (!empty($nameservers) && is_array($nameservers)) {
            $attrs['custom_nameservers'] = 1;
            $nsList = array();
            foreach (array_values($nameservers) as $idx => $ns) {
                $nsList['ns' . ($idx + 1)] = $ns;
            }
            $attrs['nameserver_list'] = $nsList;
        }
        return $this->sendRequest('SW_REGISTER', 'DOMAIN', $attrs);
    }

    public function renewDomain($domain, $years = 1) {
        return $this->sendRequest('RENEW', 'DOMAIN', array(
            'domain'  => $domain,
            'period'  => (int)$years,
            'curdate' => date('Y-m-d')
        ));
    }

    public function setNameservers($domain, array $nameservers) {
        $assignNs = array();
        foreach (array_values($nameservers) as $idx => $ns) {
            $assignNs['ns' . ($idx + 1)] = $ns;
        }
        return $this->sendRequest('ADVANCED_UPDATE_NAMESERVERS', 'DOMAIN', array(
            'domain'    => $domain,
            'op_type'   => 'assign',
            'assign_ns' => $assignNs
        ));
    }
}
