<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn;

use DateTimeInterface;

class BunnyAPIPull extends BunnyAPI
{
    public const string LOGGING_URL = 'https://logging.bunnycdn.com/';

    public function listPullZones(int $page = 0, int $per_page = 1000, bool $include_cert = false, ?string $search = null): array
    {
        return $this->APIcall('GET', 'pullzone', ['page' => $page, 'perPage' => $per_page, 'search' => $search, 'includeCertificate' => $include_cert]);
    }

    public function getPullZone(int $id, bool $include_cert = false): array
    {
        return $this->APIcall('GET', "pullzone/$id", ['includeCertificate' => $include_cert]);
    }

    /**
     * @param array<string, mixed> $args Any pull zone field from https://docs.bunny.net/reference/pullzonepublic_add
     */
    public function createPullZone(string $name, ?string $origin = null, array $args = []): array
    {
        return $this->APIcall('POST', 'pullzone', json: array_merge(array_filter(['Name' => $name, 'OriginUrl' => $origin], static fn ($v) => $v !== null), $args));
    }

    /**
     * @param array<string, mixed> $args Any pull zone field from https://docs.bunny.net/reference/pullzonepublic_updatepullzone
     */
    public function updatePullZone(int $id, array $args = []): array
    {
        return $this->APIcall('POST', "pullzone/$id", json: $args);
    }

    public function pullZoneData(int $id): array
    {
        return $this->getPullZone($id);
    }

    public function checkPullZoneAvailability(string $name): array
    {
        return $this->APIcall('POST', 'pullzone/checkavailability', json: ['Name' => $name]);
    }

    public function purgePullZone(int $id, ?string $cache_tag = null): array
    {
        return $this->APIcall('POST', "pullzone/$id/purgeCache", json: $cache_tag === null ? null : ['CacheTag' => $cache_tag]);
    }

    public function deletePullZone(int $id): array
    {
        return $this->APIcall('DELETE', "pullzone/$id");
    }

    public function pullZoneHostnames(int $id): array
    {
        $data = $this->pullZoneData($id);
        if (!isset($data['Hostnames'])) {
            return ['hostname_count' => 0, 'hostnames' => []];
        }
        $hn_arr = [];
        foreach ($data['Hostnames'] as $a_hn) {
            $hn_arr[] = [
                'id' => $a_hn['Id'],
                'hostname' => $a_hn['Value'],
                'force_ssl' => $a_hn['ForceSSL'],
            ];
        }
        return [
            'hostname_count' => count($hn_arr),
            'hostnames' => $hn_arr,
        ];
    }

    public function addHostnamePullZone(int $id, string $hostname): array
    {
        return $this->APIcall('POST', "pullzone/$id/addHostname", json: ['Hostname' => $hostname]);
    }

    public function removeHostnamePullZone(int $id, string $hostname): array
    {
        return $this->APIcall('DELETE', "pullzone/$id/removeHostname", json: ['Hostname' => $hostname]);
    }

    public function addFreeSSLCertificate(string $hostname, bool $only_http01 = false): array
    {
        return $this->APIcall('GET', 'pullzone/loadFreeCertificate', ['hostname' => $hostname, 'useOnlyHttp01' => $only_http01]);
    }

    /**
     * Add a custom certificate. Certificate and key are PEM strings; they are base64 encoded for you.
     */
    public function addCertificate(int $id, string $hostname, string $certificate, string $certificate_key): array
    {
        return $this->APIcall('POST', "pullzone/$id/addCertificate", json: [
            'Hostname' => $hostname,
            'Certificate' => base64_encode($certificate),
            'CertificateKey' => base64_encode($certificate_key),
        ]);
    }

    public function removeCertificate(int $id, string $hostname): array
    {
        return $this->APIcall('DELETE', "pullzone/$id/removeCertificate", json: ['Hostname' => $hostname]);
    }

    public function forceSSLPullZone(int $id, string $hostname, bool $force_ssl = true): array
    {
        return $this->APIcall('POST', "pullzone/$id/setForceSSL", json: ['Hostname' => $hostname, 'ForceSSL' => $force_ssl]);
    }

    public function listBlockedIpPullZone(int $id): array
    {
        $ips = array_values($this->pullZoneData($id)['BlockedIps'] ?? []);
        return [
            'blocked_ip_count' => count($ips),
            'ips' => $ips,
        ];
    }

    public function resetTokenKey(int $id, ?string $security_key = null): array
    {
        return $this->APIcall('POST', "pullzone/$id/resetSecurityKey", json: $security_key === null ? null : ['SecurityKey' => $security_key]);
    }

    public function addBlockedIpPullZone(int $id, string $ip): array
    {
        return $this->APIcall('POST', "pullzone/$id/addBlockedIp", json: ['BlockedIp' => $ip]);
    }

    public function unBlockedIpPullZone(int $id, string $ip): array
    {
        return $this->APIcall('POST', "pullzone/$id/removeBlockedIp", json: ['BlockedIp' => $ip]);
    }

    public function addAllowedReferrer(int $id, string $hostname): array
    {
        return $this->APIcall('POST', "pullzone/$id/addAllowedReferrer", json: ['Hostname' => $hostname]);
    }

    public function removeAllowedReferrer(int $id, string $hostname): array
    {
        return $this->APIcall('POST', "pullzone/$id/removeAllowedReferrer", json: ['Hostname' => $hostname]);
    }

    public function addBlockedReferrer(int $id, string $hostname): array
    {
        return $this->APIcall('POST', "pullzone/$id/addBlockedReferrer", json: ['Hostname' => $hostname]);
    }

    public function removeBlockedReferrer(int $id, string $hostname): array
    {
        return $this->APIcall('POST', "pullzone/$id/removeBlockedReferrer", json: ['Hostname' => $hostname]);
    }

    /**
     * Add or update an edge rule. Pass 'Guid' in $rule to update an existing rule.
     *
     * @param array<string, mixed> $rule Fields from https://docs.bunny.net/reference/pullzonepublic_addedgerule
     */
    public function addOrUpdateEdgeRule(int $id, array $rule): array
    {
        return $this->APIcall('POST', "pullzone/$id/edgerules/addOrUpdate", json: $rule);
    }

    public function deleteEdgeRule(int $id, string $edge_rule_guid): array
    {
        return $this->APIcall('DELETE', "pullzone/$id/edgerules/$edge_rule_guid");
    }

    public function setEdgeRuleEnabled(int $id, string $edge_rule_guid, bool $enabled): array
    {
        return $this->APIcall('POST', "pullzone/$id/edgerules/$edge_rule_guid/setEdgeRuleEnabled", json: ['Id' => $id, 'Value' => $enabled]);
    }

    /**
     * Legacy (v1) access log for a single day.
     *
     * @param string|DateTimeInterface $date DateTime, or a string already formatted as mm-dd-yy
     */
    public function pullZoneLogs(int $id, string|DateTimeInterface $date): array
    {
        $this->assertKey($this->api_key, 'API key', 'apiKey()');
        if ($date instanceof DateTimeInterface) {
            $date = $date->format('m-d-y');
        }
        $result = $this->send('GET', self::LOGGING_URL . "$date/$id.log", ['AccessKey' => $this->api_key])->body;

        $lines = [];
        foreach (explode("\n", $result) as $row) {
            $log_format = explode('|', trim($row));
            if (count($log_format) < 12) {
                continue;
            }
            $lines[] = [
                'cache_result' => $log_format[0],
                'status' => (int)$log_format[1],
                'datetime' => date('Y-m-d H:i:s', intdiv((int)$log_format[2], 1000)),
                'bytes' => (int)$log_format[3],
                'ip' => $log_format[5],
                'referer' => $log_format[6],
                'file_url' => $log_format[7],
                'user_agent' => $log_format[9],
                'request_id' => $log_format[10],
                'cdn_dc' => $log_format[8],
                'zone_id' => (int)$log_format[4],
                'country_code' => $log_format[11],
            ];
        }
        return $lines;
    }

    /**
     * Logging API v2 (JSON, max 3 day range).
     *
     * @param array<string, mixed> $filters e.g. ['status' => '4xx,5xx', 'country' => 'DE', 'limit' => 100]
     */
    public function pullZoneLogsV2(int $id, DateTimeInterface $from, DateTimeInterface $to, array $filters = []): array
    {
        $this->assertKey($this->api_key, 'API key', 'apiKey()');
        $query = array_merge(['from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM)], $filters);
        return $this->decode($this->jsonRequest('GET', self::LOGGING_URL . "v2/pullzones/$id/logs", $this->api_key, $query));
    }
}
