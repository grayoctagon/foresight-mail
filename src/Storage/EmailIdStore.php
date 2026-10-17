<?php

declare(strict_types=1);

namespace MailMirror\Storage;

final class EmailIdStore
{
    /** @var array<string, array<string, string>> */
    private array $lookupCache = [];

    public function __construct(private readonly PathResolver $paths, private readonly NdjsonStore $ndjson)
    {
    }

    public function lookup(string $userId, string $accountId, string $serverScope, string $emailId): ?string
    {
        $path = $this->path($userId, $accountId);
        if (!isset($this->lookupCache[$path])) {
            $this->lookupCache[$path] = [];
            foreach ($this->all($userId, $accountId) as $row) {
                if (is_string($row['hash'] ?? null)) {
                    $key = (string) ($row['server_scope'] ?? '')."\0".(string) ($row['email_id'] ?? '');
                    $this->lookupCache[$path][$key] = $row['hash'];
                }
            }
        }
        return $this->lookupCache[$path][$serverScope."\0".$emailId] ?? null;
    }

    public function put(string $userId, string $accountId, string $serverScope, string $emailId, string $hash): void
    {
        $rows = $this->all($userId, $accountId);
        foreach ($rows as &$row) {
            if (($row['server_scope'] ?? '') === $serverScope && ($row['email_id'] ?? '') === $emailId) {
                $row['hash'] = $hash;
                $path = $this->path($userId, $accountId);
                $this->ndjson->write($path, $rows);
                $this->lookupCache[$path][$serverScope."\0".$emailId] = $hash;
                return;
            }
        }
        unset($row);
        $rows[] = [
            'schema_version' => 1,
            'server_scope' => $serverScope,
            'email_id' => $emailId,
            'hash' => $hash,
        ];
        $path = $this->path($userId, $accountId);
        $this->ndjson->write($path, $rows);
        $this->lookupCache[$path][$serverScope."\0".$emailId] = $hash;
    }

    private function all(string $userId, string $accountId): array
    {
        return $this->ndjson->readAll($this->path($userId, $accountId));
    }

    private function path(string $userId, string $accountId): string
    {
        return $this->paths->account($userId, $accountId).'/identities/emailids.ndjson';
    }
}
