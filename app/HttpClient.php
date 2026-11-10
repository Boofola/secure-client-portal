<?php
/**
 * Native cURL HTTP/HTTPS Client for Stripe, Namecheap, OpenSRS & reCAPTCHA APIs
 * Secure Cloud Portal Stand-Alone v2.0.0
 */

if (!defined('SCP_APP_INIT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

class SCP_HttpClient {

    /**
     * Perform an HTTP/HTTPS request using PHP cURL
     *
     * @param string $url
     * @param string $method 'GET', 'POST', 'DELETE'
     * @param array|string|null $body
     * @param array $headers Associative array of headers
     * @param int $timeout
     * @return array ['success' => bool, 'code' => int, 'body' => string, 'json' => array|null, 'error' => string|null]
     */
    public static function request($url, $method = 'GET', $body = null, $headers = array(), $timeout = 35) {
        $method = strtoupper($method);

        if ($method === 'GET' && is_array($body) && !empty($body)) {
            $sep = (strpos($url, '?') === false) ? '?' : '&';
            $url .= $sep . http_build_query($body);
            $body = null;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $baseUrl = class_exists('SCP_Security') ? SCP_Security::getBaseUrl() : 'https://localhost';
        curl_setopt($ch, CURLOPT_USERAGENT, 'CloudPortal/' . (defined('SCP_VERSION') ? SCP_VERSION : '3.5.0') . ' (+' . $baseUrl . ')');
        if (defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $formattedHeaders = array();
        foreach ($headers as $k => $v) {
            $formattedHeaders[] = $k . ': ' . $v;
        }

        if ($body !== null) {
            if (is_array($body)) {
                $body = http_build_query($body);
                if (!isset($headers['Content-Type'])) {
                    $formattedHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
                }
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if (!empty($formattedHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
        }

        $rawBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($rawBody === false) {
            return array(
                'success' => false,
                'code'    => 0,
                'body'    => '',
                'json'    => null,
                'error'   => $curlErr ?: 'Network transport error'
            );
        }

        $json = json_decode($rawBody, true);

        return array(
            'success' => ($httpCode >= 200 && $httpCode < 300),
            'code'    => $httpCode,
            'status'  => $httpCode,
            'body'    => $rawBody,
            'json'    => is_array($json) ? $json : null,
            'error'   => ($httpCode >= 400) ? ($json['error']['message'] ?? 'HTTP Error ' . $httpCode) : null
        );
    }
}
