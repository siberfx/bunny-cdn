<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn;

class BunnyAPIDNS extends BunnyAPI
{
    public function getDNSZones(int $page = 1, int $per_page = 1000, ?string $search = null): array
    {
        return $this->APIcall('GET', 'dnszone', ['page' => $page, 'perPage' => $per_page, 'search' => $search]);
    }

    public function getDNSZone(int $zone_id): array
    {
        return $this->APIcall('GET', "dnszone/$zone_id");
    }

    public function getDNSZoneStatistics(int $zone_id, ?string $date_from = null, ?string $date_to = null): array
    {
        return $this->APIcall('GET', "dnszone/$zone_id/statistics", ['dateFrom' => $date_from, 'dateTo' => $date_to]);
    }

    public function checkDNSZoneAvailability(string $domain): array
    {
        return $this->APIcall('POST', 'dnszone/checkavailability', json: ['Name' => $domain]);
    }

    /**
     * @param array<string, mixed> $parameters Fields from https://docs.bunny.net/reference/dnszonepublic_add
     */
    public function addDNSZoneFull(array $parameters): array
    {
        return $this->APIcall('POST', 'dnszone', json: $parameters);
    }

    /**
     * Creates the zone, then applies the logging settings (the add endpoint only accepts Domain and Records).
     */
    public function addDNSZone(string $domain, bool $logging = false, bool $log_ip_anon = true): array
    {
        $zone = $this->APIcall('POST', 'dnszone', json: ['Domain' => $domain]);
        if ($logging && isset($zone['Id'])) {
            $zone = $this->updateDNSZone((int)$zone['Id'], ['LoggingEnabled' => true, 'LoggingIPAnonymizationEnabled' => $log_ip_anon]);
        }
        return $zone;
    }

    /**
     * @param array<string, mixed> $parameters Fields from https://docs.bunny.net/reference/dnszonepublic_update
     */
    public function updateDNSZone(int $zone_id, array $parameters): array
    {
        return $this->APIcall('POST', "dnszone/$zone_id", json: $parameters);
    }

    public function updateDNSZoneNameservers(int $zone_id, bool $custom_ns, string $ns_one = '', string $ns_two = ''): array
    {
        return $this->updateDNSZone($zone_id, ['CustomNameserversEnabled' => $custom_ns, 'Nameserver1' => $ns_one, 'Nameserver2' => $ns_two]);
    }

    public function updateDNSZoneLogging(int $zone_id, bool $enable_logging, int $log_anon_type, bool $use_log_anon): array
    {
        return $this->updateDNSZone($zone_id, ['LoggingEnabled' => $enable_logging, 'LogAnonymizationType' => $log_anon_type, 'LoggingIPAnonymizationEnabled' => $use_log_anon]);
    }

    public function updateDNSZoneSoaEmail(int $zone_id, string $soa_email): array
    {
        return $this->updateDNSZone($zone_id, ['SoaEmail' => $soa_email]);
    }

    public function deleteDNSZone(int $zone_id): array
    {
        return $this->APIcall('DELETE', "dnszone/$zone_id");
    }

    /** Returns the zone as a BIND zone file */
    public function exportDNSZone(int $zone_id): string
    {
        $this->assertKey($this->api_key, 'API key', 'apiKey()');
        return $this->send('GET', self::API_URL . "dnszone/$zone_id/export", ['AccessKey' => $this->api_key])->body;
    }

    public function enableDNSSEC(int $zone_id): array
    {
        return $this->APIcall('POST', "dnszone/$zone_id/dnssec");
    }

    public function disableDNSSEC(int $zone_id): array
    {
        return $this->APIcall('DELETE', "dnszone/$zone_id/dnssec");
    }

    public function listDNSRecords(int $zone_id, int $page = 1, int $per_page = 1000, DnsRecordType|int|null $type = null, ?string $search = null): array
    {
        return $this->APIcall('GET', "dnszone/$zone_id/records", [
            'page' => $page, 'perPage' => $per_page, 'type' => $type instanceof DnsRecordType ? $type->value : $type, 'search' => $search,
        ]);
    }

    /**
     * @param array<string, mixed> $parameters Fields from https://docs.bunny.net/reference/dnszonepublic_addrecord ('Type' may be a DnsRecordType)
     */
    public function addDNSRecord(int $zone_id, string $name, string $value, array $parameters = []): array
    {
        return $this->APIcall('PUT', "dnszone/$zone_id/records", json: self::record(array_merge(['Name' => $name, 'Value' => $value], $parameters)));
    }

    /**
     * @param array<string, mixed> $parameters Fields to change ('Type' may be a DnsRecordType)
     */
    public function updateDNSRecord(int $zone_id, int $dns_id, array $parameters): array
    {
        return $this->APIcall('POST', "dnszone/$zone_id/records/$dns_id", json: self::record(array_merge(['Id' => $dns_id], $parameters)));
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    protected static function record(array $record): array
    {
        if (($record['Type'] ?? null) instanceof DnsRecordType) {
            $record['Type'] = $record['Type']->value;
        }
        return $record;
    }

    protected function addTypedRecord(int $zone_id, DnsRecordType $type, string $hostname, array $fields, int $ttl, int $weight): array
    {
        return $this->APIcall('PUT', "dnszone/$zone_id/records", json: array_merge(['Type' => $type->value], $fields, ['Name' => $hostname, 'Ttl' => $ttl, 'Weight' => $weight]));
    }

    public function addDNSRecordA(int $zone_id, string $hostname, string $ipv4, int $ttl = 300, int $weight = 100): array
    {
        return $this->addTypedRecord($zone_id, DnsRecordType::A, $hostname, ['Value' => $ipv4], $ttl, $weight);
    }

    public function addDNSRecordAAAA(int $zone_id, string $hostname, string $ipv6, int $ttl = 300, int $weight = 100): array
    {
        return $this->addTypedRecord($zone_id, DnsRecordType::AAAA, $hostname, ['Value' => $ipv6], $ttl, $weight);
    }

    public function addDNSRecordCNAME(int $zone_id, string $hostname, string $target, int $ttl = 300, int $weight = 100): array
    {
        return $this->addTypedRecord($zone_id, DnsRecordType::CNAME, $hostname, ['Value' => $target], $ttl, $weight);
    }

    public function addDNSRecordFlatten(int $zone_id, string $hostname, string $target, int $ttl = 300, int $weight = 100): array
    {
        return $this->addTypedRecord($zone_id, DnsRecordType::Flatten, $hostname, ['Value' => $target], $ttl, $weight);
    }

    public function addDNSRecordMX(int $zone_id, string $hostname, string $mail_server, int $priority = 2000, int $ttl = 300, int $weight = 100): array
    {
        return $this->addTypedRecord($zone_id, DnsRecordType::MX, $hostname, ['Value' => $mail_server, 'Priority' => $priority], $ttl, $weight);
    }

    public function addDNSRecordTXT(int $zone_id, string $hostname, string $content, int $ttl = 300, int $weight = 100): array
    {
        return $this->addTypedRecord($zone_id, DnsRecordType::TXT, $hostname, ['Value' => $content], $ttl, $weight);
    }

    public function addDNSRecordNS(int $zone_id, string $hostname, string $target, int $ttl = 300, int $weight = 100): array
    {
        return $this->addTypedRecord($zone_id, DnsRecordType::NS, $hostname, ['Value' => $target], $ttl, $weight);
    }

    public function addDNSRecordSRV(int $zone_id, string $hostname, string $target, int $port, int $priority = 10, int $ttl = 300, int $weight = 100): array
    {
        return $this->addTypedRecord($zone_id, DnsRecordType::SRV, $hostname, ['Value' => $target, 'Port' => $port, 'Priority' => $priority], $ttl, $weight);
    }

    public function addDNSRecordCAA(int $zone_id, string $hostname, string $tag, string $value, int $flags = 0, int $ttl = 300, int $weight = 100): array
    {
        return $this->addTypedRecord($zone_id, DnsRecordType::CAA, $hostname, ['Value' => $value, 'Tag' => $tag, 'Flags' => $flags], $ttl, $weight);
    }

    public function addDNSRecordRedirect(int $zone_id, string $hostname, string $url, int $ttl = 300, int $weight = 100): array
    {
        return $this->addTypedRecord($zone_id, DnsRecordType::Redirect, $hostname, ['Value' => $url], $ttl, $weight);
    }

    public function addDNSRecordPullZone(int $zone_id, string $hostname, int $pullzone_id, int $ttl = 300, int $weight = 100): array
    {
        return $this->addTypedRecord($zone_id, DnsRecordType::PullZone, $hostname, ['PullZoneId' => $pullzone_id], $ttl, $weight);
    }

    public function addDNSRecordScript(int $zone_id, string $hostname, int $script_id, int $ttl = 300, int $weight = 100): array
    {
        return $this->addTypedRecord($zone_id, DnsRecordType::Script, $hostname, ['ScriptId' => $script_id], $ttl, $weight);
    }

    public function updateDNSRecordA(int $zone_id, int $dns_id, string $hostname, string $ipv4): array
    {
        return $this->updateDNSRecord($zone_id, $dns_id, ['Type' => DnsRecordType::A, 'Value' => $ipv4, 'Name' => $hostname]);
    }

    public function updateDNSRecordAAAA(int $zone_id, int $dns_id, string $hostname, string $ipv6): array
    {
        return $this->updateDNSRecord($zone_id, $dns_id, ['Type' => DnsRecordType::AAAA, 'Value' => $ipv6, 'Name' => $hostname]);
    }

    public function updateDNSRecordCNAME(int $zone_id, int $dns_id, string $hostname, string $target): array
    {
        return $this->updateDNSRecord($zone_id, $dns_id, ['Type' => DnsRecordType::CNAME, 'Value' => $target, 'Name' => $hostname]);
    }

    public function updateDNSRecordMX(int $zone_id, int $dns_id, string $hostname, string $mail_server, int $priority = 2000): array
    {
        return $this->updateDNSRecord($zone_id, $dns_id, ['Type' => DnsRecordType::MX, 'Value' => $mail_server, 'Priority' => $priority, 'Name' => $hostname]);
    }

    public function updateDNSRecordTXT(int $zone_id, int $dns_id, string $hostname, string $content): array
    {
        return $this->updateDNSRecord($zone_id, $dns_id, ['Type' => DnsRecordType::TXT, 'Value' => $content, 'Name' => $hostname]);
    }

    public function updateDNSRecordNS(int $zone_id, int $dns_id, string $hostname, string $target): array
    {
        return $this->updateDNSRecord($zone_id, $dns_id, ['Type' => DnsRecordType::NS, 'Value' => $target, 'Name' => $hostname]);
    }

    public function disableDNSRecord(int $zone_id, int $dns_id): array
    {
        return $this->updateDNSRecord($zone_id, $dns_id, ['Disabled' => true]);
    }

    public function enableDNSRecord(int $zone_id, int $dns_id): array
    {
        return $this->updateDNSRecord($zone_id, $dns_id, ['Disabled' => false]);
    }

    public function deleteDNSRecord(int $zone_id, int $dns_id): array
    {
        return $this->APIcall('DELETE', "dnszone/$zone_id/records/$dns_id");
    }

    public function recheckDNSRecord(int $zone_id): array
    {
        return $this->APIcall('POST', "dnszone/$zone_id/recheckdns");
    }

    public function dismissDNSConfigNotice(int $zone_id): array
    {
        return $this->APIcall('POST', "dnszone/$zone_id/dismissnameservercheck");
    }
}
