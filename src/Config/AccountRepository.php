<?php

declare(strict_types=1);

namespace MailMirror\Config;

use MailMirror\Exception\NotFoundException;
use MailMirror\Storage\AtomicFileWriter;
use MailMirror\Storage\JsonStore;
use MailMirror\Storage\PathResolver;
use MailMirror\Support\Ids;
use MailMirror\Support\Time;

final class AccountRepository
{
    public function __construct(
        private readonly PathResolver $paths,
        private readonly JsonStore $json,
        private readonly AtomicFileWriter $writer,
        private readonly AccountConfigValidator $validator,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(string $userId): array
    {
        $accounts = [];
        foreach (glob($this->paths->user($userId).'/accounts/*/config.json') ?: [] as $file) {
            $config = $this->json->read($file);
            $accounts[] = $this->withoutSecrets($config);
        }
        usort($accounts, static fn (array $a, array $b): int => strcasecmp((string) $a['label'], (string) $b['label']));
        return $accounts;
    }

    public function create(string $userId, array $input, ?callable $beforePublish = null): array
    {
        $accountId = Ids::uuid7();
        $input['account_id'] = $accountId;
        foreach (($input['identities'] ?? []) as $index => $identity) {
            if (is_array($identity)) {
                $input['identities'][$index]['identity_id'] = Ids::uuid7();
            }
        }
        $config = $this->validator->normalize($input);
        $root = $this->paths->account($userId, $accountId);
        foreach (['runtime', 'identities', 'sync/jobs', 'folders', 'objects', 'tmp', 'locks', 'logs'] as $folder) {
            $this->writer->ensureDirectory($root.'/'.$folder);
        }
        $this->json->write($root.'/runtime/account.json', [
            'schema_version' => 1,
            'account_id' => $accountId,
            'owner_user_id' => $userId,
            'created_at' => Time::now(),
            'last_completed_sync' => null,
        ]);
        if ($beforePublish !== null) $beforePublish($config);
        // Publishing config.json makes the account visible to readers.
        $this->json->write($root.'/config.json', $config);
        return $config;
    }

    public function get(string $userId, string $accountId): array
    {
        $path = $this->paths->account($userId, $accountId).'/config.json';
        if (!is_file($path)) {
            throw new NotFoundException('Account wurde für den angemeldeten Benutzer nicht gefunden.');
        }
        $runtime = $this->json->read($this->paths->account($userId, $accountId).'/runtime/account.json');
        if (!hash_equals($userId, (string) ($runtime['owner_user_id'] ?? ''))) {
            throw new NotFoundException('Account wurde für den angemeldeten Benutzer nicht gefunden.');
        }
        return $this->json->read($path);
    }

    public function update(string $userId, string $accountId, array $input, ?callable $beforePublish = null): array
    {
        $existing = $this->get($userId, $accountId);
        $existingIdentityIds = array_column($existing['identities'] ?? [], 'identity_id');
        foreach (($input['identities'] ?? []) as $index => $identity) {
            if (is_array($identity) && (empty($identity['identity_id']) || !in_array($identity['identity_id'], $existingIdentityIds, true))) {
                $input['identities'][$index]['identity_id'] = Ids::uuid7();
            }
        }
        $merged = array_replace_recursive($existing, $input);
        if (array_key_exists('identities', $input)) {
            $merged['identities'] = $input['identities'];
        }
        $config = $this->validator->normalize($merged, $accountId);
        if ($beforePublish !== null) $beforePublish($config);
        $this->json->write($this->paths->account($userId, $accountId).'/config.json', $config);
        return $config;
    }

    public function archive(string $userId, string $accountId): void
    {
        $this->get($userId, $accountId);
        $source = $this->paths->account($userId, $accountId);
        $archiveRoot = $this->paths->user($userId).'/archive/accounts';
        $this->writer->ensureDirectory($archiveRoot);
        $target = $archiveRoot.'/'.$accountId.'-'.gmdate('YmdHis');
        if (!rename($source, $target)) {
            throw new \RuntimeException('Account konnte nicht ins wiederherstellbare Archiv verschoben werden.');
        }
    }

    private function withoutSecrets(array $config): array
    {
        return $config;
    }
}
