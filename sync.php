#!/usr/bin/env php
<?php
/**
 * Syncs all existing Plesk domains to ClouDNS as slave zones.
 *
 * Run this once after installing the EventListener to bring ClouDNS in sync
 * with domains that already exist in Plesk.
 *
 * Usage:
 *   php sync.php [--dry-run]
 *
 *   --dry-run  List domains that would be synced without actually calling the
 *              ClouDNS API.
 */

require_once __DIR__ . '/PleskClient.php';

// ============================================================
// Configuration — fill these in before running
// ============================================================

$pleskHost   = '';          // Plesk hostname or IP, e.g. 'plesk.example.com'
$pleskApiKey = '';          // Plesk API key (Tools & Settings > API Keys)

$clouDnsAuthId  = '';       // ClouDNS auth-id
$clouDnsAuthKey = '';       // ClouDNS auth-password
$masterIp       = '';       // (Optional) Primary IP that ClouDNS will transfer from

// ============================================================

$dryRun = in_array('--dry-run', $argv ?? [], true);

if ($dryRun) {
    echo "[dry-run] No changes will be made to ClouDNS.\n\n";
}

// Validate configuration.
foreach (['pleskHost', 'pleskApiKey', 'clouDnsAuthId', 'clouDnsAuthKey'] as $var) {
    if (empty($$var)) {
        echo "Error: \${$var} is not configured in sync.php.\n";
        exit(1);
    }
}

// Connect to Plesk and fetch all domains.
$plesk = new PleskClient($pleskHost, $pleskApiKey);

echo "Connecting to Plesk at {$pleskHost}...\n";

if (!$plesk->testConnection()) {
    echo "Error: Could not connect to Plesk. Check your host and API key.\n";
    exit(1);
}

echo "Connected.\n\n";

$domains = $plesk->getDomains();
$total   = count($domains);

echo "Found {$total} domain(s) in Plesk.\n\n";

if ($total === 0) {
    echo "Nothing to sync.\n";
    exit(0);
}

$clouDnsBase = 'https://api.cloudns.net/';
$ok          = 0;
$failed      = 0;

foreach ($domains as $domain) {
    echo "  {$domain} ... ";

    if ($dryRun) {
        echo "[skipped]\n";
        continue;
    }

    $params = http_build_query([
        'auth-id'       => $clouDnsAuthId,
        'auth-password' => $clouDnsAuthKey,
        'domain-name'   => $domain,
        'zone-type'     => 'slave',
        'master-ip'     => $masterIp,
    ]);

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => $clouDnsBase . 'dns/register.json',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
    ]);

    $body     = curl_exec($curl);
    $curlErr  = curl_error($curl);
    curl_close($curl);

    if ($curlErr) {
        echo "FAILED (cURL: {$curlErr})\n";
        $failed++;
        continue;
    }

    $result = json_decode($body, true);

    if (isset($result['status']) && $result['status'] === 'Failed') {
        echo "FAILED ({$result['statusDescription']})\n";
        $failed++;
    } else {
        echo "OK\n";
        $ok++;
    }
}

echo "\nDone. {$ok} created, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
