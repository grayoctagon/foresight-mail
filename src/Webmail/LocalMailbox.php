<?php

declare(strict_types=1);

namespace MailMirror\Webmail;

use MailMirror\Exception\NotFoundException;
use MailMirror\Exception\BusyException;
use MailMirror\Storage\AccountLock;

/** Read projection over the existing authoritative account/EML/location stores. */
final class LocalMailbox
{
    public function __construct(private readonly array $app, private readonly MimeReader $mime) {}

    public function folders(string $user, string $account): array
    {
        $this->app['accounts']->get($user, $account);
        $result = [];
        foreach ($this->app['catalog']->all($user, $account) as $folder) {
            $id = $folder['local_folder_id'];
            $generation = $this->snapshot($user, $account, $id);
            $file = $this->source($user, $account, $id);
            $counts = ['total' => 0, 'unread' => 0, 'pending' => 0];
            foreach ($this->app['ndjson']->iterate($file) as $row) {
                ++$counts['total'];
                if (!in_array('\Seen', $row['flags'] ?? [], true)) ++$counts['unread'];
                if (($row['content_state'] ?? '') !== 'materialized') ++$counts['pending'];
            }
            if ($generation !== $this->snapshot($user, $account, $id)) throw new BusyException('Ordner wurde gerade synchronisiert. Bitte erneut laden.');
            $type = 'custom';
            foreach (['inbox','sent','drafts','archive','junk','trash'] as $candidate) {
                if (strcasecmp($folder['remote_name'], $candidate) === 0 || in_array('\\'.ucfirst($candidate), $folder['attributes'], true)) $type = $candidate;
            }
            $result[] = [
                'id' => $id, 'accountId' => $account, 'name' => $folder['remote_name'], 'type' => $type,
                'delimiter' => $folder['delimiter'], 'selectable' => $folder['selectable'],
                'remotePresent' => $folder['remote_present'], 'counts' => $counts,
            ];
        }
        return $result;
    }

    public function messages(string $user, array $query): array
    {
        $scopes = $this->scopes($user, (string) ($query['folders'] ?? ''));
        $limit = $this->integer($query['limit'] ?? 100, 1, 200);
        $sort = (string) ($query['sort'] ?? 'date');
        $direction = (string) ($query['direction'] ?? 'desc');
        if (!in_array($sort, ['date','subject','from','bytes','priority','accountId','hasAttachments'], true)
            || !in_array($direction, ['asc','desc'], true)) throw new \InvalidArgumentException('Ungültige Sortierung.');
        foreach (['q','from','to','subject','dateFrom','dateTo','unread','flagged','attachment','unanswered','priority'] as $key) {
            if (isset($query[$key]) && (!is_scalar($query[$key]) || strlen((string) $query[$key]) > 300)) throw new \InvalidArgumentException('Ungültiger Suchfilter.');
        }
        foreach (['dateFrom','dateTo'] as $key) {
            if (!empty($query[$key]) && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $query[$key]) || strtotime($query[$key]) === false)) throw new \InvalidArgumentException('Ungültiges Datum.');
        }
        $indices = [];
        $indexing = [];
        $deadline = microtime(true) + 2;
        foreach ($scopes as [$account, $folder]) {
            $index = $this->index($user, $account, $folder, $deadline);
            if (!$index['ready']) $indexing[] = ['accountId' => $account, 'folderId' => $folder, 'processed' => $index['processed']];
            $indices[] = $index;
        }
        if ($indexing !== []) return ['messages' => [], 'total' => null, 'nextCursor' => null, 'indexing' => $indexing];
        $filterQuery = $query;
        unset($filterQuery['cursor'], $filterQuery['route']);
        ksort($filterQuery);
        $revision = hash('sha256', json_encode([$scopes, array_column($indices, 'signature'), $filterQuery]));
        $after = null;
        if (!empty($query['cursor'])) {
            $cursor = json_decode((string) base64_decode(strtr((string) $query['cursor'], '-_', '+/'), true), true);
            if (!is_array($cursor) || !is_array($cursor['after'] ?? null) || !is_string($cursor['revision'] ?? null)) throw new \InvalidArgumentException('Ungültiger Cursor.');
            if (!hash_equals($revision, $cursor['revision'])) throw new BusyException('Der lokale Index wurde aktualisiert. Die Liste bitte neu laden.');
            $after = $cursor['after'];
            if (count($after) !== 2 || !is_scalar($after[0]) || !is_string($after[1])) throw new \InvalidArgumentException('Ungültiger Cursor.');
        }
        $compare = static function (array $a, array $b) use ($direction): int {
            return (($a[0] <=> $b[0]) ?: strcmp($a[1], $b[1])) * ($direction === 'desc' ? -1 : 1);
        };
        $heap = new class($compare) extends \SplHeap {
            public function __construct(private $order) {}
            protected function compare(mixed $a, mixed $b): int { return ($this->order)($a['key'], $b['key']); }
        };
        $total = 0;
        $remaining = 0;
        foreach ($indices as $index) {
            foreach ($this->app['ndjson']->iterate($index['path']) as $row) {
                if (!$this->matches($row, $query)) continue;
                ++$total;
                $value = match ($sort) {
                    'from' => mb_strtolower(($row['from']['name'] ?: $row['from']['email'])),
                    'subject' => mb_strtolower($row['subject']),
                    'date' => strtotime($row['date'] ?? '') ?: 0,
                    'priority' => ['low' => 0, 'normal' => 1, 'high' => 2][$row['priority']],
                    default => $row[$sort],
                };
                $key = [$value, $row['accountId'].'~'.$row['id']];
                if ($after !== null && $compare($key, $after) <= 0) continue;
                ++$remaining;
                $heap->insert(['key' => $key, 'row' => $row]);
                if ($heap->count() > $limit) $heap->extract();
            }
        }
        $page = [];
        while (!$heap->isEmpty()) $page[] = $heap->extract();
        $page = array_reverse($page);
        foreach ($scopes as $i => [$account, $folder]) {
            if ($indices[$i]['signature'] !== $this->snapshot($user, $account, $folder)) throw new BusyException('Die synchronisierten Daten wurden aktualisiert. Bitte erneut laden.');
        }
        $next = $remaining > $limit ? rtrim(strtr(base64_encode(json_encode(['revision' => $revision, 'after' => end($page)['key']])), '+/', '-_'), '=') : null;
        return ['messages' => array_column($page, 'row'), 'total' => $total, 'nextCursor' => $next, 'indexing' => [], 'revision' => $revision];
    }

    public function message(string $user, string $account, string $id): array
    {
        [$row, $path] = $this->resolve($user, $account, $id);
        return $row + ($path ? $this->mime->details($path) : ['attachments' => [], 'headers' => []]);
    }

    public function body(string $user, string $account, string $id, bool $external): array
    {
        return $this->mime->body($this->rawPath($user, $account, $id), $external);
    }

    public function rawPath(string $user, string $account, string $id): string
    {
        [, $path] = $this->resolve($user, $account, $id);
        if (!$path) throw new NotFoundException('Nachrichteninhalt ist noch nicht lokal vorhanden. Bitte synchronisieren.');
        return $path;
    }

    public function attachment(string $user, string $account, string $id, string $attachment): array
    {
        return $this->mime->attachment($this->rawPath($user, $account, $id), $attachment);
    }

    private function resolve(string $user, string $account, string $id): array
    {
        $this->app['accounts']->get($user, $account);
        if (!preg_match('/^([A-Za-z0-9._-]{1,128})~([0-9]{1,20})~([1-9][0-9]{0,10})$/D', $id, $match)) throw new \InvalidArgumentException('Ungültige Nachrichten-ID.');
        [, $folder, $validity, $uid] = $match;
        $this->requireFolder($user, $account, $folder);
        $generation = $this->snapshot($user, $account, $folder);
        $statePath = $this->app['paths']->folder($user, $account, $folder).'/state.json';
        $state = $this->app['json']->read($statePath, []);
        if ((string) ($state['uidvalidity'] ?? 0) !== $validity) throw new NotFoundException('Nachricht gehört zu einem veralteten Ordnerindex.');
        foreach ($this->app['ndjson']->iterate($this->source($user, $account, $folder)) as $location) {
            if ((string) $location['uid'] !== $uid) continue;
            $path = $this->object($user, $account, $location);
            $summary = $this->summary($user, $account, $folder, $validity, $location);
            if ($generation !== $this->snapshot($user, $account, $folder)) throw new BusyException('Nachrichtenindex wurde gerade aktualisiert. Bitte erneut öffnen.');
            return [$summary, $path];
        }
        throw new NotFoundException('Nachricht nicht gefunden.');
    }

    private function index(string $user, string $account, string $folder, float $deadline): array
    {
        $source = $this->source($user, $account, $folder);
        $root = $this->app['paths']->folder($user, $account, $folder);
        $signature = $this->snapshot($user, $account, $folder);
        $path = $root.'/webmail.ndjson';
        $metaPath = $root.'/webmail-index.json';
        $meta = $this->app['json']->read($metaPath, []);
        if (($meta['signature'] ?? '') === $signature && is_file($path)) return ['ready' => true, 'path' => $path, 'signature' => $signature];
        if (microtime(true) >= $deadline) return ['ready' => false, 'processed' => 0];
        $this->app['writer']->ensureDirectory($root);
        $lock = new AccountLock();
        if (!$lock->acquire($root.'/webmail.lock')) return ['ready' => false, 'processed' => 0];
        try {
            $buildPath = $root.'/webmail-build.json';
            $partPath = $root.'/webmail-building.ndjson';
            $build = $this->app['json']->read($buildPath, []);
            if (($build['signature'] ?? '') !== $signature || !is_file($partPath)) {
                $build = ['signature' => $signature, 'offset' => 0, 'bytes' => 0, 'processed' => 0];
            }
            $output = fopen($partPath, 'c+b');
            if (!$output) throw new \RuntimeException('Webmail-Index kann nicht geschrieben werden.');
            try {
                ftruncate($output, $build['bytes']); // Recover an interrupted, uncommitted chunk.
                fseek($output, $build['bytes']);
                $input = is_file($source) ? fopen($source, 'rb') : null;
                $finished = !$input;
                $state = $this->app['json']->read($root.'/state.json', []);
                try {
                    if ($input) {
                        fseek($input, $build['offset']);
                        $count = 0;
                        while (microtime(true) < $deadline && $count < 500) {
                            $line = fgets($input);
                            if ($line === false) { $finished = true; break; }
                            if (trim($line) === '') continue;
                            $location = json_decode($line, true, 128, JSON_THROW_ON_ERROR);
                            $row = $this->summary($user, $account, $folder, (string) ($state['uidvalidity'] ?? 0), $location);
                            $bytes = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
                            if (fwrite($output, $bytes) !== strlen($bytes)) throw new \RuntimeException('Webmail-Index unvollständig.');
                            ++$count;
                            ++$build['processed'];
                        }
                        $build['offset'] = ftell($input);
                    }
                } finally { if ($input) fclose($input); }
                fflush($output);
                $build['bytes'] = ftell($output);
            } finally { fclose($output); }
            if ($signature !== $this->snapshot($user, $account, $folder)) return ['ready' => false, 'processed' => 0];
            if ($finished) {
                if (!rename($partPath, $path)) throw new \RuntimeException('Webmail-Index konnte nicht committed werden.');
                $this->app['json']->write($metaPath, ['signature' => $signature, 'processed' => $build['processed']]);
                return ['ready' => true, 'path' => $path, 'signature' => $signature];
            }
            $this->app['json']->write($buildPath, $build);
            return ['ready' => false, 'processed' => $build['processed']];
        } finally { $lock->release(); }
    }

    private function summary(string $user, string $account, string $folder, string $validity, array $location): array
    {
        $path = $this->object($user, $account, $location);
        $base = $path ? $this->mime->summary($path) : [
            'subject' => 'UID '.$location['uid'].' – Inhalt noch nicht geladen', 'from' => ['name' => '', 'email' => ''],
            'to' => [], 'cc' => [], 'replyTo' => [], 'date' => null, 'messageId' => null,
            'snippet' => '', 'hasAttachments' => false, 'attachmentCount' => 0, 'hasHtml' => false,
            'priority' => 'normal', 'spamScore' => null, 'bytes' => $location['rfc822_size'] ?? 0,
        ];
        unset($base['version']);
        $flags = $location['flags'] ?? [];
        $base['date'] = $location['internal_date'] ?? $base['date'];
        try { if ($base['date']) $base['date'] = (new \DateTimeImmutable($base['date']))->format(DATE_ATOM); } catch (\Throwable) { $base['date'] = null; }
        return $base + [
            'id' => $folder.'~'.$validity.'~'.$location['uid'], 'accountId' => $account, 'folderId' => $folder,
            'uid' => $location['uid'], 'contentState' => $path ? 'available' : 'pending',
            'flags' => ['seen' => in_array('\Seen', $flags, true), 'flagged' => in_array('\Flagged', $flags, true),
                'answered' => in_array('\Answered', $flags, true), 'draft' => in_array('\Draft', $flags, true),
                'deleted' => in_array('\Deleted', $flags, true), 'keywords' => array_values(array_filter($flags, static fn ($f) => !str_starts_with($f, '\\')))],
        ];
    }

    private function matches(array $row, array $q): bool
    {
        $address = static fn ($a) => ($a['name'] ?? '').' '.($a['email'] ?? '');
        $from = $address($row['from']);
        $to = implode(' ', array_map($address, array_merge($row['to'], $row['cc'])));
        foreach (['q' => $from.' '.$to.' '.$row['subject'].' '.$row['snippet'], 'from' => $from, 'to' => $to, 'subject' => $row['subject']] as $key => $text) {
            if (!empty($q[$key]) && !str_contains(mb_strtolower($text), mb_strtolower(trim($q[$key])))) return false;
        }
        $date = strtotime($row['date'] ?? '') ?: 0;
        if (!empty($q['dateFrom']) && $date < strtotime($q['dateFrom'])) return false;
        if (!empty($q['dateTo']) && $date >= strtotime($q['dateTo'].' +1 day')) return false;
        if (!empty($q['unread']) && $row['flags']['seen']) return false;
        if (!empty($q['flagged']) && !$row['flags']['flagged']) return false;
        if (!empty($q['attachment']) && !$row['hasAttachments']) return false;
        if (!empty($q['unanswered']) && $row['flags']['answered']) return false;
        if (!empty($q['priority']) && $q['priority'] !== $row['priority']) return false;
        return true;
    }

    private function scopes(string $user, string $value): array
    {
        if ($value === '') return [];
        $tokens = array_unique(explode(',', $value));
        if (count($tokens) > 128) throw new \InvalidArgumentException('Höchstens 128 Ordner pro Ansicht.');
        $scopes = [];
        foreach ($tokens as $token) {
            $parts = explode('~', $token);
            if (count($parts) !== 2) throw new \InvalidArgumentException('Ungültige Ordnerauswahl.');
            [$account, $folder] = $parts;
            $this->app['accounts']->get($user, $account);
            $this->requireFolder($user, $account, $folder);
            $scopes[] = $parts;
        }
        sort($scopes);
        return $scopes;
    }

    private function requireFolder(string $user, string $account, string $folder): void
    {
        \MailMirror\Support\Ids::assert($folder, 'Folder-ID');
        if (!$this->app['catalog']->find($user, $account, $folder)) throw new NotFoundException('Ordner nicht gefunden.');
    }

    private function object(string $user, string $account, array $location): ?string
    {
        $hash = $location['hash'] ?? '';
        return $hash && $this->app['objects']->exists($user, $account, $hash) ? $this->app['objects']->objectPath($user, $account, $hash) : null;
    }

    private function source(string $user, string $account, string $folder): string
    {
        return $this->app['paths']->folder($user, $account, $folder).'/locations.ndjson';
    }

    private function signature(string $path): string
    {
        clearstatcache(true, $path);
        $s = @stat($path);
        $statePath = dirname($path).'/state.json';
        clearstatcache(true, $statePath);
        $state = @stat($statePath);
        return hash('sha256', json_encode([2, $s ? [$s['ino'], $s['size'], $s['mtime'], $s['ctime']] : [],
            $state ? [$state['ino'], $state['size'], $state['mtime'], $state['ctime']] : []]));
    }

    private function snapshot(string $user, string $account, string $folder): string
    {
        $pending = $this->app['paths']->folder($user, $account, $folder).'/commit.pending.json';
        clearstatcache(true, $pending);
        if (is_file($pending)) {
            // Never read state.json from one side of a two-file commit and locations from the other.
            // An interrupted local commit is recovered using the existing sync lock, without IMAP.
            $lock = new AccountLock();
            if (!$lock->acquire($this->app['paths']->account($user, $account).'/locks/sync.lock')) {
                throw new BusyException('Ordner wird gerade gespeichert. Bitte kurz warten und erneut laden.');
            }
            try { $this->app['folders']->recover($user, $account, $folder); }
            finally { $lock->release(); }
        }
        return $this->signature($this->source($user, $account, $folder));
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        $n = filter_var($value, FILTER_VALIDATE_INT);
        if ($n === false || $n < $min || $n > $max) throw new \InvalidArgumentException("Limit muss zwischen {$min} und {$max} liegen.");
        return $n;
    }
}
