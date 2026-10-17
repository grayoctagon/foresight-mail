<?php

declare(strict_types=1);

namespace MailMirror\Config;

use MailMirror\Exception\ConfigurationException;

final class AccountConfigValidator
{
    public const SYNC_INTERVALS = [0, 15, 30, 60, 120, 240, 360, 480, 720, 1440];

    public const PERFORMANCE_DEFAULTS = [
        'max_request_runtime_seconds' => 20,
        'max_index_entries_per_request' => 5000,
        'max_content_messages_per_request' => 100,
        'max_content_bytes_per_request' => 104857600,
        'imap_metadata_batch_size' => 250,
    ];

    public function normalize(array $input, ?string $immutableAccountId = null): array
    {
        foreach (['imap','smtp','identities','performance','sync'] as $key) {
            if (isset($input[$key]) && !is_array($input[$key])) throw new ConfigurationException("Ungültige {$key}-Konfiguration.");
        }
        foreach (['imap','smtp'] as $key) {
            if (isset($input[$key]['authentication']) && !is_array($input[$key]['authentication'])) throw new ConfigurationException('Ungültige Authentifizierung.');
        }
        if (isset($input['label']) && !is_string($input['label'])) throw new ConfigurationException('Ungültiges Account-Label.');
        $accountId = $immutableAccountId ?? (string) ($input['account_id'] ?? '');
        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 120) {
            throw new ConfigurationException('Das Account-Label ist erforderlich und darf höchstens 120 Zeichen lang sein.');
        }

        $imap = $this->server($input['imap'] ?? [], false);
        $smtp = isset($input['smtp']) && $input['smtp'] !== null
            ? $this->server($input['smtp'], true)
            : null;
        $identities = $this->identities($input['identities'] ?? []);
        $syncInterval = $input['sync']['interval_minutes'] ?? 0;
        if (!is_int($syncInterval) || !in_array($syncInterval, self::SYNC_INTERVALS, true)) {
            throw new ConfigurationException('Ungültiges Sync-Intervall. Erlaubt sind manuell, 15/30 Minuten oder 1/2/4/6/8/12/24 Stunden.');
        }
        $performance = self::PERFORMANCE_DEFAULTS;
        foreach ($performance as $key => $default) {
            $value = filter_var($input['performance'][$key] ?? $default, FILTER_VALIDATE_INT);
            if ($value === false || $value < 1) {
                throw new ConfigurationException("Performance-Wert {$key} muss eine positive Ganzzahl sein.");
            }
            $performance[$key] = $value;
        }

        return [
            'schema_version' => 1,
            'account_id' => $accountId,
            'label' => $label,
            'enabled' => (bool) ($input['enabled'] ?? true),
            'imap' => $imap,
            'smtp' => $smtp,
            'identities' => $identities,
            'performance' => $performance,
            'sync' => ['interval_minutes' => $syncInterval],
            'storage' => ['orphan_policy' => 'keep'],
        ];
    }

    private function server(array $value, bool $nullable): array
    {
        $hostname = trim((string) ($value['hostname'] ?? ''));
        if ($hostname === '') {
            throw new ConfigurationException(($nullable ? 'SMTP' : 'IMAP').'-Hostname fehlt.');
        }
        $port = filter_var($value['port'] ?? 0, FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) {
            throw new ConfigurationException('Der Server-Port muss zwischen 1 und 65535 liegen.');
        }
        $security = (string) ($value['connection_security'] ?? 'implicit_tls');
        if (!in_array($security, ['starttls', 'implicit_tls', 'none'], true)) {
            throw new ConfigurationException('Ungültige Verbindungssicherheit.');
        }
        $method = (string) ($value['authentication']['method'] ?? 'auto');
        if (!in_array($method, ['auto', 'login', 'plain'], true)) {
            throw new ConfigurationException('Ungültige Authentifizierungsmethode.');
        }
        $username = trim((string) ($value['authentication']['username'] ?? ''));
        $credentialRef = trim((string) ($value['authentication']['credential_ref'] ?? ($nullable ? 'smtp-main' : 'imap-main')));
        if ($username === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $credentialRef)) {
            throw new ConfigurationException('Benutzername oder Credential-Referenz ist ungültig.');
        }
        $timeout = filter_var($value['timeout_seconds'] ?? 30, FILTER_VALIDATE_INT);
        if ($timeout === false || $timeout < 1 || $timeout > 600) {
            throw new ConfigurationException('Timeout muss zwischen 1 und 600 Sekunden liegen.');
        }
        $result = [
            'hostname' => $hostname,
            'port' => $port,
            'connection_security' => $security,
            'authentication' => [
                'method' => $method,
                'username' => $username,
                'credential_ref' => $credentialRef,
            ],
            'timeout_seconds' => $timeout,
            'validate_certificate' => (bool) ($value['validate_certificate'] ?? true),
        ];
        if (!$nullable) {
            $result['use_emailid_optimization'] = (bool) ($value['use_emailid_optimization'] ?? true);
        }
        return $result;
    }

    private function identities(array $items): array
    {
        $result = [];
        $defaultCount = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $from = trim((string) ($item['from_address'] ?? ''));
            if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
                throw new ConfigurationException("Ungültige Absenderadresse: {$from}");
            }
            $placement = (string) ($item['signature']['placement'] ?? 'before_quote');
            if (!in_array($placement, ['before_quote', 'after_quote'], true)) {
                $placement = 'before_quote';
            }
            $enabled = (bool) ($item['enabled'] ?? true);
            $isDefault = $enabled && (bool) ($item['is_default'] ?? false);
            $defaultCount += $isDefault ? 1 : 0;
            $replyTo = [];
            foreach (($item['reply_to'] ?? []) as $address) {
                if (is_string($address)) {
                    $address = ['display_name' => '', 'address' => $address];
                }
                if (is_array($address) && filter_var($address['address'] ?? '', FILTER_VALIDATE_EMAIL)) {
                    $replyTo[] = [
                        'display_name' => trim((string) ($address['display_name'] ?? '')),
                        'address' => (string) $address['address'],
                    ];
                }
            }
            $result[] = [
                'identity_id' => (string) ($item['identity_id'] ?? ''),
                'label' => trim((string) ($item['label'] ?? $from)),
                'display_name' => trim((string) ($item['display_name'] ?? '')),
                'from_address' => $from,
                'envelope_from' => filter_var($item['envelope_from'] ?? '', FILTER_VALIDATE_EMAIL) ? (string) $item['envelope_from'] : $from,
                'reply_to' => $replyTo,
                'signature' => [
                    'enabled' => (bool) ($item['signature']['enabled'] ?? false),
                    'plain_text' => (string) ($item['signature']['plain_text'] ?? ''),
                    'html' => isset($item['signature']['html']) ? (string) $item['signature']['html'] : null,
                    'placement' => $placement,
                ],
                'is_default' => $isDefault,
                'enabled' => $enabled,
            ];
        }
        if ($result !== [] && $defaultCount !== 1) {
            $assigned = false;
            foreach ($result as &$identity) {
                $identity['is_default'] = !$assigned && $identity['enabled'];
                $assigned = $assigned || $identity['is_default'];
            }
            unset($identity);
        }
        return $result;
    }
}
