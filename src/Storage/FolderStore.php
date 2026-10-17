<?php

declare(strict_types=1);

namespace MailMirror\Storage;

use MailMirror\Exception\IntegrityException;
use MailMirror\Support\Time;

final class FolderStore
{
    public function __construct(
        private readonly PathResolver $paths,
        private readonly JsonStore $json,
        private readonly NdjsonStore $ndjson,
        private readonly AtomicFileWriter $writer,
    ) {
    }

    public function recover(string $userId, string $accountId, string $folderId): void
    {
        $root = $this->root($userId, $accountId, $folderId);
        $txnPath = $root.'/commit.pending.json';
        if (!is_file($txnPath)) {
            return;
        }
        $txn = $this->json->read($txnPath);
        if (($txn['schema_version'] ?? null) !== 1 || !isset($txn['state'], $txn['locations'])) {
            throw new IntegrityException("Ungültige Recovery-Transaktion für {$folderId}");
        }
        $this->ndjson->write($root.'/locations.ndjson', $txn['locations']);
        $this->json->write($root.'/state.json', $txn['state']);
        unlink($txnPath);
    }

    public function state(string $userId, string $accountId, string $folderId): ?array
    {
        $this->recover($userId, $accountId, $folderId);
        $path = $this->root($userId, $accountId, $folderId).'/state.json';
        return is_file($path) ? $this->json->read($path) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function locations(string $userId, string $accountId, string $folderId): array
    {
        $this->recover($userId, $accountId, $folderId);
        return $this->ndjson->readAll($this->root($userId, $accountId, $folderId).'/locations.ndjson');
    }

    /** @return array<int, array<string, mixed>> keyed by UID */
    public function locationsByUid(string $userId, string $accountId, string $folderId): array
    {
        $indexed = [];
        foreach ($this->locations($userId, $accountId, $folderId) as $row) {
            $uid = (int) ($row['uid'] ?? 0);
            if ($uid < 1 || isset($indexed[$uid])) {
                throw new IntegrityException("Ungültige oder doppelte UID im Location-Index {$folderId}");
            }
            $indexed[$uid] = $row;
        }
        ksort($indexed, SORT_NUMERIC);
        return $indexed;
    }

    /** @param array<int, array<string, mixed>> $locations */
    public function commit(string $userId, string $accountId, string $folderId, array $state, array $locations, string $operation): void
    {
        $root = $this->root($userId, $accountId, $folderId);
        $this->writer->ensureDirectory($root);
        ksort($locations, SORT_NUMERIC);
        $rows = array_values($locations);
        $state['schema_version'] = 1;
        $state['local_folder_id'] = $folderId;
        $txn = [
            'schema_version' => 1,
            'created_at' => Time::now(),
            'operation' => $operation,
            'state' => $state,
            'locations' => $rows,
        ];
        $this->json->write($root.'/commit.pending.json', $txn);

        foreach (['locations.ndjson', 'state.json'] as $name) {
            if (is_file($root.'/'.$name)) {
                @copy($root.'/'.$name, $root.'/'.$name.'.bak');
            }
        }
        $this->ndjson->write($root.'/locations.ndjson', $rows);
        $this->json->write($root.'/state.json', $state);
        $journal = $this->ndjson->readAll($root.'/journal.ndjson');
        $journal[] = [
            'schema_version' => 1,
            'committed_at' => Time::now(),
            'operation' => $operation,
            'location_count' => count($rows),
            'uidvalidity' => $state['uidvalidity'] ?? null,
        ];
        if (count($journal) > 200) {
            $journal = array_slice($journal, -200);
        }
        $this->ndjson->write($root.'/journal.ndjson', $journal);
        unlink($root.'/commit.pending.json');
    }

    private function root(string $userId, string $accountId, string $folderId): string
    {
        return $this->paths->folder($userId, $accountId, $folderId);
    }
}
