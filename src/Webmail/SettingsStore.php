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
        $out['density'] = ($input['density'] ?? '') === 'compact' ? 'compact' : 'comfortable';
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
