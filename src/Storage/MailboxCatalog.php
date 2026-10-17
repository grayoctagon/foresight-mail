<?php

declare(strict_types=1);

namespace MailMirror\Storage;

use MailMirror\Support\Ids;

final class MailboxCatalog
{
    public function __construct(
        private readonly PathResolver $paths,
        private readonly NdjsonStore $ndjson,
        private readonly AtomicFileWriter $writer,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(string $userId, string $accountId): array
    {
        return $this->ndjson->readAll($this->path($userId, $accountId));
    }

    /** @param array<int, array<string, mixed>> $remote */
    public function reconcile(string $userId, string $accountId, array $remote): array
    {
        $existing = $this->all($userId, $accountId);
        foreach ($existing as &$row) {
            $row['_was_remote_present'] = (bool) ($row['remote_present'] ?? false);
            $row['remote_present'] = false;
        }
        unset($row);

        foreach ($remote as $mailbox) {
            $match = null;
            if (!empty($mailbox['mailbox_id'])) {
                foreach ($existing as $index => $row) {
                    if (($row['mailbox_id'] ?? null) === $mailbox['mailbox_id']) {
                        $match = $index;
                        break;
                    }
                }
            }
            if ($match === null) {
                foreach ($existing as $index => $row) {
                    if (($row['remote_name'] ?? null) === $mailbox['remote_name']
                        && ($row['remote_present'] ?? false) === false
                        && ($row['_was_remote_present'] ?? false) === true) {
                        $match = $index;
                        break;
                    }
                }
            }
            $record = [
                'schema_version' => 1,
                'local_folder_id' => $match === null ? Ids::folderId() : $existing[$match]['local_folder_id'],
                'remote_name' => (string) $mailbox['remote_name'],
                'mailbox_id' => $mailbox['mailbox_id'] ?? null,
                'delimiter' => (string) ($mailbox['delimiter'] ?? '/'),
                'selectable' => (bool) ($mailbox['selectable'] ?? true),
                'attributes' => array_values($mailbox['attributes'] ?? []),
                'remote_present' => true,
            ];
            if ($match === null) {
                $existing[] = $record;
            } else {
                $existing[$match] = $record;
            }
        }

        foreach ($existing as &$row) {
            unset($row['_was_remote_present']);
        }
        unset($row);

        usort($existing, static fn (array $a, array $b): int => strcasecmp((string) $a['remote_name'], (string) $b['remote_name']));
        $this->ndjson->write($this->path($userId, $accountId), $existing);
        foreach ($existing as $row) {
            $this->writer->ensureDirectory($this->paths->folder($userId, $accountId, (string) $row['local_folder_id']));
        }
        return $existing;
    }

    public function find(string $userId, string $accountId, string $folderId): ?array
    {
        foreach ($this->all($userId, $accountId) as $row) {
            if (($row['local_folder_id'] ?? null) === $folderId) {
                return $row;
            }
        }
        return null;
    }

    private function path(string $userId, string $accountId): string
    {
        return $this->paths->account($userId, $accountId).'/runtime/mailboxes.ndjson';
    }
}
