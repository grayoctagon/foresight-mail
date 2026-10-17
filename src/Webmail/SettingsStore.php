<?php

declare(strict_types=1);

namespace MailMirror\Webmail;

use MailMirror\Storage\JsonStore;
use MailMirror\Storage\PathResolver;

final class SettingsStore
{
    private const COLUMNS = ['message','date','bytes','from','to','cc','hasAttachments','priority','accountId','spam','urgency','answerBy','importance','report'];
    public function __construct(private readonly PathResolver $paths, private readonly JsonStore $json) {}

    public function get(string $user): array
    {
        $preferences = $this->json->read($this->paths->user($user).'/webmail-settings.json', [
            'sidebarPercent' => 20, 'listPercent' => 20, 'density' => 'comfortable', 'pageSize' => 100,
            'columns' => ['message','date','hasAttachments','priority','accountId','spam','urgency','answerBy','importance','report'],
            'columnWidths' => [], 'favorites' => [],
        ]);
        $preferences['columnOrder'] = $this->columnOrder($preferences['columnOrder'] ?? []);
        $preferences['previewMode'] = $this->previewMode($preferences['previewMode'] ?? 'text');
        $preferences['previewRules'] = $this->previewRules($preferences['previewRules'] ?? []);
        return $preferences;
    }

    public function save(string $user, array $input): array
    {
        $existing = $this->get($user);
        $out = [];
        foreach (['sidebarPercent' => [0,70], 'listPercent' => [0,80], 'pageSize' => [10,200]] as $key => [$min,$max]) {
            $value = $input[$key] ?? $existing[$key];
            if (!is_numeric($value) || $value < $min || $value > $max) throw new \InvalidArgumentException('Ungültige Anzeigeeinstellung.');
            $out[$key] = $key === 'pageSize' ? (int) $value : (float) $value;
        }
        if ($out['sidebarPercent'] + $out['listPercent'] > 95) throw new \InvalidArgumentException('Die Vorschau benötigt mindestens 5 % Breite.');
        $out['density'] = ($input['density'] ?? $existing['density']) === 'compact' ? 'compact' : 'comfortable';
        foreach (['columns','favorites','columnWidths'] as $key) {
            if (!array_key_exists($key, $input)) $input[$key] = $existing[$key];
        }
        $out['previewMode'] = $this->previewMode(array_key_exists('previewMode', $input) ? $input['previewMode'] : $existing['previewMode']);
        $out['previewRules'] = $this->previewRules(array_key_exists('previewRules', $input) ? $input['previewRules'] : $existing['previewRules']);
        $columns = self::COLUMNS;
        if (!is_array($input['columns'] ?? []) || !is_array($input['favorites'] ?? []) || !is_array($input['columnWidths'] ?? [])) throw new \InvalidArgumentException('Ungültige Einstellungen.');
        foreach ($input['columns'] ?? [] as $column) {
            if (!is_string($column)) throw new \InvalidArgumentException('Ungültige Spaltenauswahl.');
        }
        $out['columns'] = array_values(array_unique(array_merge(['message'], array_intersect($columns, $input['columns'] ?? $existing['columns']))));
        $out['columnOrder'] = $this->columnOrder(array_key_exists('columnOrder', $input) ? $input['columnOrder'] : $existing['columnOrder']);
        $out['columnWidths'] = [];
        foreach ($input['columnWidths'] ?? [] as $key => $width) {
            if (in_array($key, $columns, true) && is_numeric($width)) $out['columnWidths'][$key] = max(30, min(1200, (int) $width));
        }
        $out['favorites'] = [];
        foreach (array_slice($input['favorites'] ?? [], 0, 100) as $favorite) {
            if (!is_array($favorite) || !is_string($favorite['name'] ?? null) || !is_array($favorite['folders'] ?? null)) throw new \InvalidArgumentException('Ungültiger Favorit.');
            if (!is_array($favorite['filters'] ?? [])) throw new \InvalidArgumentException('Ungültige Favoritenfilter.');
            $folders = [];
            foreach (array_slice($favorite['folders'], 0, 128) as $folder) {
                if (!is_string($folder) || !preg_match('/^[A-Za-z0-9._-]{1,128}~[A-Za-z0-9._-]{1,128}$/D', $folder)) throw new \InvalidArgumentException('Ungültiger Favoritenordner.');
                $folders[] = $folder;
            }
            $filters = [];
            foreach (['q','from','to','subject','dateFrom','dateTo','unread','flagged','attachment','unanswered','priority'] as $key) {
                if (isset($favorite['filters'][$key]) && is_scalar($favorite['filters'][$key])) $filters[$key] = mb_substr((string) $favorite['filters'][$key], 0, 300);
            }
            $out['favorites'][] = ['name' => mb_substr($favorite['name'], 0, 100), 'folders' => array_values(array_unique($folders)), 'filters' => $filters];
        }
        $this->json->write($this->paths->user($user).'/webmail-settings.json', $out);
        return $out;
    }

    private function previewMode(mixed $mode): string
    {
        if (!in_array($mode, ['text', 'html'], true)) throw new \InvalidArgumentException('Vorschauformat muss text oder html sein.');
        return $mode;
    }

    private function previewRules(mixed $rules): array
    {
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > 200) throw new \InvalidArgumentException('Höchstens 200 Vorschau-Ausnahmen sind erlaubt.');
        $out = []; $seen = [];
        foreach ($rules as $rule) {
            if (!is_array($rule) || !in_array($rule['type'] ?? null, ['address','domain'], true) || !is_string($rule['value'] ?? null)) throw new \InvalidArgumentException('Ungültige Vorschau-Ausnahme.');
            $type = $rule['type'];
            $value = strtolower(trim($rule['value']));
            if ($type === 'domain' && str_starts_with($value, '@')) $value = substr($value, 1);
            $at = strrpos($value, '@');
            $domain = $type === 'domain' ? $value : ($at === false ? '' : substr($value, $at + 1));
            if (function_exists('idn_to_ascii') && $domain !== '') $domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
            $domain = strtolower($domain);
            if (strlen($domain) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain)) throw new \InvalidArgumentException('Bitte eine vollständige Mailadresse oder Domain wie @domain.at angeben.');
            $value = $type === 'domain' ? $domain : substr($value, 0, $at).'@'.$domain;
            if ($type === 'address' && (strlen($value) > 254 || !filter_var($value, FILTER_VALIDATE_EMAIL))) throw new \InvalidArgumentException('Ungültige Absenderadresse.');
            $key = $type.':'.$value;
            if (isset($seen[$key])) throw new \InvalidArgumentException('Für diese Adresse oder Domain besteht bereits eine Vorschau-Ausnahme.');
            $seen[$key] = true;
            $out[] = ['type' => $type, 'value' => $value, 'mode' => $this->previewMode($rule['mode'] ?? null)];
        }
        return $out;
    }

    private function columnOrder(mixed $order): array
    {
        if (!is_array($order) || !array_is_list($order) || count($order) > count(self::COLUMNS)) throw new \InvalidArgumentException('Ungültige Spaltenreihenfolge.');
        $seen = [];
        foreach ($order as $key) {
            if (!is_string($key) || !in_array($key, self::COLUMNS, true) || isset($seen[$key])) throw new \InvalidArgumentException('Ungültige Spaltenreihenfolge.');
            $seen[$key] = true;
        }
        // Older settings have no order; newly introduced columns are appended without losing existing positions.
        return array_merge($order, array_values(array_diff(self::COLUMNS, $order)));
    }
}
