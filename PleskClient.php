<?php
/**
 * Plesk REST API client.
 *
 * Connects to a Plesk server via the REST API (Plesk 17.8+) and provides
 * methods to list domains and webspaces.
 *
 * @package plesk-cloudns
 * @license http://www.gnu.org/licenses/lgpl.txt GNU LESSER GENERAL PUBLIC LICENSE v3
 */
class PleskClient
{
    private $host;
    private $apiKey;
    private $baseUrl;

    /**
     * @param string $host   Plesk hostname or IP (e.g. "plesk.example.com")
     * @param string $apiKey Plesk API key (Tools & Settings > API Keys)
     * @param int    $port   Plesk port (default 8443)
     */
    public function __construct($host, $apiKey, $port = 8443)
    {
        $this->host    = $host;
        $this->apiKey  = $apiKey;
        $this->baseUrl = "https://{$host}:{$port}/api/v2";
    }

    /**
     * Returns all domain names managed by this Plesk server.
     *
     * @return string[]
     * @throws RuntimeException on API error
     */
    public function getDomains()
    {
        $response = $this->get('/domains');
        $domains  = [];
        foreach ($response as $item) {
            if (!empty($item['name'])) {
                $domains[] = $item['name'];
            }
        }
        return $domains;
    }

    /**
     * Verifies that the API credentials are valid.
     *
     * @return bool
     */
    public function testConnection()
    {
        try {
            $this->get('/server');
            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /**
     * Returns basic server information from the Plesk API.
     *
     * @return array
     * @throws RuntimeException on API error
     */
    public function getServerInfo()
    {
        return $this->get('/server');
    }

    /**
     * Performs a GET request against the Plesk REST API.
     *
     * @param string $endpoint  Path relative to /api/v2 (e.g. "/domains")
     * @return array            Decoded JSON response
     * @throws RuntimeException on network or API error
     */
    private function get($endpoint)
    {
        $url  = $this->baseUrl . $endpoint;
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'X-API-Key: ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            // Allow self-signed certificates common on Plesk installs.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $body     = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error    = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new RuntimeException("cURL error connecting to Plesk: {$error}");
        }

        $data = json_decode($body, true);

        if ($httpCode >= 400) {
            $message = isset($data['message']) ? $data['message'] : $body;
            throw new RuntimeException("Plesk API error {$httpCode}: {$message}");
        }

        return $data;
    }
}
