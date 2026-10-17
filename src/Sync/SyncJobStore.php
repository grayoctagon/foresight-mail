<?php

declare(strict_types=1);

namespace MailMirror\Sync;

use MailMirror\Exception\NotFoundException;
use MailMirror\Storage\JsonStore;
use MailMirror\Storage\PathResolver;
use MailMirror\Support\Ids;
use MailMirror\Support\Time;

final class SyncJobStore
{
    public function __construct(private readonly PathResolver $paths, private readonly JsonStore $json)
    {
    }

    public function start(string $userId, string $accountId): array
    {
        $active = $this->active($userId, $accountId);
        if ($active !== null && in_array($active['state'] ?? null, ['queued', 'running'], true)) {
            return $this->decorate($active);
        }
        $now = Time::now();
        $job = [
            'schema_version' => 1,
            'job_id' => Ids::uuid7(),
            'account_id' => $accountId,
            'state' => 'queued',
            'phase' => null,
            'started_at' => $now,
            'updated_at' => $now,
            'completed_at' => null,
            'mailbox_index' => 0,
            'mailbox_total' => 0,
            'index_after_uid' => 0,
            'index_processed' => 0,
            'index_mailbox_totals' => [],
            'content_current' => 0,
            'content_total' => 0,
            'content_started_at' => null,
            'processed_bytes' => 0,
            'pending_content' => 0,
            'finalize_cursor' => 0,
            'last_error' => null,
            'counters' => [
                'mailboxes_processed' => 0,
                'locations_added' => 0,
                'locations_removed' => 0,
                'flags_changed' => 0,
                'eml_downloaded' => 0,
                'eml_deduplicated' => 0,
                'materializations_recovered' => 0,
                'emailid_reused' => 0,
                'uidvalidity_resets' => 0,
                'reconciliations' => 0,
            ],
        ];
        $this->save($userId, $accountId, $job);
        $this->json->write($this->activePath($userId, $accountId), [
            'schema_version' => 1,
            'job_id' => $job['job_id'],
        ]);
        return $this->decorate($job);
    }

    public function active(string $userId, string $accountId): ?array
    {
        $activePath = $this->activePath($userId, $accountId);
        if (!is_file($activePath)) {
            return null;
        }
        $active = $this->json->read($activePath);
        $jobId = (string) ($active['job_id'] ?? '');
        $path = $this->jobPath($userId, $accountId, $jobId);
        if (!is_file($path)) {
            throw new NotFoundException('Aktiver Sync-Job verweist auf eine fehlende Job-Datei.');
        }
        return $this->json->read($path);
    }

    public function status(string $userId, string $accountId): ?array
    {
        $job = $this->active($userId, $accountId);
        return $job === null ? null : $this->decorate($job);
    }

    public function save(string $userId, string $accountId, array $job): array
    {
        $job['updated_at'] = Time::now();
        $this->json->write($this->jobPath($userId, $accountId, (string) $job['job_id']), $job);
        return $job;
    }

    public function decorate(array $job): array
    {
        $job['elapsed_seconds'] = Time::secondsSince((string) $job['started_at']);
        $phase = $job['phase'] ?? null;
        $mailboxCurrent = min((int) ($job['mailbox_index'] ?? 0) + 1, max(0, (int) ($job['mailbox_total'] ?? 0)));
        $job['mailbox_current'] = $mailboxCurrent;
        $job['current'] = match ($phase) {
            'discover_mailboxes' => 0,
            'index_mailboxes' => (int) ($job['index_processed'] ?? 0),
            'fetch_content' => (int) ($job['content_current'] ?? 0),
            'finalize' => (int) ($job['finalize_cursor'] ?? 0),
            default => ($job['state'] ?? null) === 'completed' ? 1 : 0,
        };
        $job['total'] = match ($phase) {
            'discover_mailboxes' => 1,
            'index_mailboxes' => array_sum($job['index_mailbox_totals'] ?? []),
            'fetch_content' => (int) ($job['content_total'] ?? 0),
            'finalize' => (int) ($job['mailbox_total'] ?? 0),
            default => 1,
        };
        $job['phase_label'] = match ($phase) {
            'discover_mailboxes' => 'Lädt Ordnerliste',
            'index_mailboxes' => sprintf('Lädt Ordner-Nachrichtenindex %d/%d', $mailboxCurrent, (int) ($job['mailbox_total'] ?? 0)),
            'fetch_content' => 'Lädt Nachrichteninhalt',
            'finalize' => 'Finalisiert Synchronisation',
            default => match ($job['state'] ?? null) {
                'queued' => 'Wartet auf Start',
                'completed' => 'Synchronisation abgeschlossen',
                'failed' => 'Synchronisation fehlgeschlagen',
                default => 'Unbekannter Status',
            },
        };
        $job['progress_percent'] = $this->progress($job);
        $job['eta_seconds'] = $this->eta($job);
        return $job;
    }

    private function progress(array $job): float
    {
        if (($job['state'] ?? null) === 'completed') {
            return 100.0;
        }
        $ratio = static fn (int $current, int $total): float => $total > 0 ? min(1.0, $current / $total) : 0.0;
        return round(match ($job['phase'] ?? null) {
            'discover_mailboxes' => 2.0,
            'index_mailboxes' => 5.0 + 25.0 * $ratio((int) ($job['index_processed'] ?? 0), array_sum($job['index_mailbox_totals'] ?? [])),
            'fetch_content' => 30.0 + 65.0 * $ratio((int) ($job['content_current'] ?? 0), (int) ($job['content_total'] ?? 0)),
            'finalize' => 95.0 + 4.0 * $ratio((int) ($job['finalize_cursor'] ?? 0), (int) ($job['mailbox_total'] ?? 0)),
            default => 0.0,
        }, 2);
    }

    private function eta(array $job): ?int
    {
        if (($job['phase'] ?? null) !== 'fetch_content' || (int) ($job['content_current'] ?? 0) < 2 || empty($job['content_started_at'])) {
            return null;
        }
        $elapsed = Time::secondsSince((string) $job['content_started_at']);
        if ($elapsed < 2) {
            return null;
        }
        $current = (int) $job['content_current'];
        $remaining = max(0, (int) $job['content_total'] - $current);
        return (int) ceil($remaining / ($current / $elapsed));
    }

    private function activePath(string $userId, string $accountId): string
    {
        return $this->paths->account($userId, $accountId).'/sync/active.json';
    }

    private function jobPath(string $userId, string $accountId, string $jobId): string
    {
        return $this->paths->account($userId, $accountId).'/sync/jobs/'.Ids::assert($jobId, 'Job-ID').'.json';
    }
}
