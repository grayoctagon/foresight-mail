<?php

declare(strict_types=1);

namespace MailMirror\Sync;

use DirectoryTree\ImapEngine\Exceptions\Exception as ImapException;
use MailMirror\Config\AccountRepository;
use MailMirror\Exception\ConfigurationException;
use MailMirror\Exception\BusyException;
use MailMirror\Exception\CorruptStorageException;
use MailMirror\Exception\IntegrityException;
use MailMirror\Exception\RemoteMessageGoneException;
use MailMirror\Imap\ImapAdapterFactory;
use MailMirror\Imap\ImapAdapterInterface;
use MailMirror\Logging\SyncLogger;
use MailMirror\Storage\AccountLock;
use MailMirror\Storage\ContentObjectStore;
use MailMirror\Storage\EmailIdStore;
use MailMirror\Storage\FolderStore;
use MailMirror\Storage\JsonStore;
use MailMirror\Storage\MailboxCatalog;
use MailMirror\Storage\PathResolver;
use MailMirror\Support\Time;

final class SyncEngine
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly SyncJobStore $jobs,
        private readonly ImapAdapterFactory $imapFactory,
        private readonly MailboxCatalog $catalog,
        private readonly FolderStore $folders,
        private readonly ContentObjectStore $objects,
        private readonly EmailIdStore $emailIds,
        private readonly PathResolver $paths,
        private readonly JsonStore $json,
        private readonly SyncLogger $logger,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function start(string $userId, string $accountId): array
    {
        $this->accounts->get($userId, $accountId);
        $lock = $this->lock($userId, $accountId);
        if ($lock === null) {
            $job = $this->jobs->status($userId, $accountId);
            if ($job === null) throw new BusyException('Dieses Postfach wird gerade synchronisiert. Bitte erneut prüfen.');
            return $job + ['busy' => true];
        }
        try {
            $config = $this->accounts->get($userId, $accountId);
            if (!($config['enabled'] ?? false)) throw new ConfigurationException('Der Account ist deaktiviert.');
            $job = $this->jobs->start($userId, $accountId);
            // An explicit start takes over a scheduled job, including a paused one.
            $job['trigger'] = 'manual';
            $job = $this->jobs->save($userId, $accountId, $job);
            $this->logger->log($userId, $accountId, 'info', 'sync_started', ['job_id' => $job['job_id']]);
            return $this->jobs->decorate($job);
        } finally {
            $lock->release();
        }
    }

    public function status(string $userId, string $accountId): ?array
    {
        $this->accounts->get($userId, $accountId);
        return $this->jobs->status($userId, $accountId);
    }

    public function advance(string $userId, string $accountId): array
    {
        $this->accounts->get($userId, $accountId);
        $lock = $this->lock($userId, $accountId);
        if ($lock === null) {
            $job = $this->jobs->status($userId, $accountId);
            if ($job === null) throw new BusyException('Dieses Postfach wird gerade synchronisiert. Bitte erneut prüfen.');
            return $job + ['busy' => true];
        }
        try {
            $config = $this->accounts->get($userId, $accountId);
            if (!($config['enabled'] ?? false)) throw new ConfigurationException('Der Account ist deaktiviert.');
            return $this->advanceLocked($userId, $accountId, $config);
        } finally {
            $lock->release();
        }
    }

    /** Browser-driven scheduler: local decision and at most one bounded work unit. */
    public function check(string $userId, string $accountId): array
    {
        $this->accounts->get($userId, $accountId);
        $lock = $this->lock($userId, $accountId);
        if ($lock === null) {
            return $this->checkResult($this->jobs->status($userId, $accountId), 'busy', 60);
        }
        try {
            $config = $this->accounts->get($userId, $accountId);
            $job = $this->jobs->status($userId, $accountId);
            $interval = (int) ($config['sync']['interval_minutes'] ?? 0);
            if (!($config['enabled'] ?? false) || $interval === 0) {
                return $this->checkResult($job, $config['enabled'] ? 'manual' : 'disabled', 300);
            }
            $active = $job !== null && in_array($job['state'], ['queued', 'running'], true);
            if ($active && ($job['trigger'] ?? 'manual') !== 'automatic') {
                // Do not resume explicitly paused/manual work behind the user's back.
                return $this->checkResult($job, 'manual_job', 60);
            }
            $now = $this->clock === null ? time() : ($this->clock)();
            $runtime = $this->paths->account($userId, $accountId).'/sync/automatic.json';
            $schedule = is_file($runtime) ? $this->json->read($runtime) : [];
            if ($active) {
                if (($schedule['job_id'] ?? null) === $job['job_id'] && (int) ($schedule['next_step_at'] ?? 0) > $now) {
                    return $this->checkResult($job, 'running', 60);
                }
            } else {
                // Completed and failed attempts both wait an interval; no tight failure loop.
                $reference = $job === null ? 0 : (strtotime($job['completed_at'] ?? $job['updated_at'] ?? $job['started_at']) ?: 0);
                $dueAt = $reference > 0 ? $reference + $interval * 60 : $now;
                if ($dueAt > $now) return $this->checkResult($job, 'not_due', 300, $dueAt);
                $job = $this->jobs->start($userId, $accountId);
                $job['trigger'] = 'automatic';
                $job = $this->jobs->save($userId, $accountId, $job);
                $this->logger->log($userId, $accountId, 'info', 'automatic_sync_started', ['job_id' => $job['job_id']]);
            }
            // Reserve the next slot before doing I/O; multiple tabs cannot double-step.
            $this->json->write($runtime, ['job_id' => $job['job_id'], 'next_step_at' => $now + 60]);
            $config['performance']['max_request_runtime_seconds'] = min(15, (int) $config['performance']['max_request_runtime_seconds']);
            $config['performance']['max_index_entries_per_request'] = min(250, (int) $config['performance']['max_index_entries_per_request']);
            $config['performance']['imap_metadata_batch_size'] = min(100, (int) $config['performance']['imap_metadata_batch_size']);
            $config['performance']['max_content_messages_per_request'] = min(10, (int) $config['performance']['max_content_messages_per_request']);
            $config['imap']['timeout_seconds'] = min(5, (int) $config['imap']['timeout_seconds']);
            $config['imap']['_request_deadline'] = microtime(true) + 20;
            $job = $this->advanceLocked($userId, $accountId, $config);
            $active = in_array($job['state'], ['queued', 'running'], true);
            $finishedAt = $this->clock === null ? time() : ($this->clock)();
            $this->json->write($runtime, ['job_id' => $job['job_id'], 'next_step_at' => $finishedAt + 60]);
            return $this->checkResult($job, $active ? 'running' : $job['state'], $active ? 60 : 300);
        } finally {
            $lock->release();
        }
    }

    private function checkResult(?array $job, string $reason, int $retry, ?int $dueAt = null): array
    {
        return ['job' => $job, 'reason' => $reason, 'syncing' => in_array($reason, ['running', 'busy', 'manual_job'], true),
            'retryAfterSeconds' => $retry, 'nextDueAt' => $dueAt === null ? null : gmdate('c', $dueAt)];
    }

    private function lock(string $userId, string $accountId): ?AccountLock
    {
        $lock = new AccountLock();
        return $lock->acquire($this->paths->account($userId, $accountId).'/locks/sync.lock') ? $lock : null;
    }

    /** Must only be called while holding the account sync lock. Re-read after locking. */
    private function advanceLocked(string $userId, string $accountId, array $config): array
    {
        $job = $this->jobs->active($userId, $accountId);
        if ($job === null) {
            $job = $this->jobs->start($userId, $accountId);
            $job = $this->jobs->active($userId, $accountId);
        }
        if (in_array($job['state'] ?? null, ['completed', 'failed'], true)) {
            return $this->jobs->decorate($job);
        }

        $adapter = null;
        $requestStarted = microtime(true);
        try {
            $discarded = $this->objects->discardStalePartials($userId, $accountId);
            if ($discarded > 0) {
                $this->logger->log($userId, $accountId, 'info', 'stale_temp_recovered', ['files_removed' => $discarded]);
            }
            $job['state'] = 'running';
            $job['last_error'] = null;
            if (($job['phase'] ?? null) === null) {
                $job['phase'] = 'discover_mailboxes';
            }
            $adapter = $this->imapFactory->make($config, $userId, $accountId);
            $job = match ($job['phase']) {
                'discover_mailboxes' => $this->discover($userId, $accountId, $config, $job, $adapter),
                'index_mailboxes' => $this->index($userId, $accountId, $config, $job, $adapter, $requestStarted),
                'fetch_content' => $this->fetchContent($userId, $accountId, $config, $job, $adapter, $requestStarted),
                'finalize' => $this->finalize($userId, $accountId, $config, $job, $adapter),
                default => throw new \LogicException('Unbekannte Sync-Phase.'),
            };
            $job = $this->jobs->save($userId, $accountId, $job);
            return $this->jobs->decorate($job);
        } catch (\Throwable $e) {
            $retryable = !$e instanceof IntegrityException
                && !$e instanceof CorruptStorageException
                && !$e instanceof ConfigurationException
                && !$e instanceof \InvalidArgumentException;
            $job['last_error'] = [
                'code' => $this->errorCode($e),
                'message' => $this->safeErrorMessage($e),
                'at' => Time::now(),
                'retryable' => $retryable,
            ];
            if (!$retryable) {
                $job['state'] = 'failed';
            }
            $job = $this->jobs->save($userId, $accountId, $job);
            $this->logger->log($userId, $accountId, 'error', 'sync_error', [
                'job_id' => $job['job_id'],
                'phase' => $job['phase'] ?? null,
                'code' => $job['last_error']['code'],
                'message' => $job['last_error']['message'],
                'retryable' => $retryable,
            ]);
            return $this->jobs->decorate($job);
        } finally {
            $adapter?->disconnect();
        }
    }

    private function discover(string $userId, string $accountId, array $config, array $job, ImapAdapterInterface $imap): array
    {
        $imap->connect();
        $capabilities = $imap->capabilities();
        $this->json->write($this->paths->account($userId, $accountId).'/runtime/capabilities.json', [
            'schema_version' => 1,
            'detected_at' => Time::now(),
            'capabilities' => $capabilities,
        ]);
        $mailboxes = $this->catalog->reconcile($userId, $accountId, $imap->discoverMailboxes());
        $selectable = array_values(array_filter($mailboxes, static fn (array $row): bool => ($row['remote_present'] ?? false) && ($row['selectable'] ?? false)));
        $job['mailbox_total'] = count($selectable);
        $job['mailbox_index'] = 0;
        $job['index_after_uid'] = 0;
        $job['index_processed'] = 0;
        $job['index_mailbox_totals'] = [];
        $job['phase'] = 'index_mailboxes';
        $this->logger->log($userId, $accountId, 'info', 'mailboxes_discovered', [
            'job_id' => $job['job_id'],
            'mailbox_count' => count($mailboxes),
            'selectable_count' => count($selectable),
            'capabilities' => $capabilities,
        ]);
        return $job;
    }

    private function index(string $userId, string $accountId, array $config, array $job, ImapAdapterInterface $imap, float $requestStarted): array
    {
        $mailboxes = $this->selectableMailboxes($userId, $accountId);
        $mailboxIndex = (int) ($job['mailbox_index'] ?? 0);
        if (!isset($mailboxes[$mailboxIndex])) {
            return $this->enterContentPhase($userId, $accountId, $job);
        }
        $mailbox = $mailboxes[$mailboxIndex];
        $folderId = (string) $mailbox['local_folder_id'];
        $imap->connect();
        $status = $imap->examine((string) $mailbox['remote_name']);
        $remoteUids = $imap->allUids();
        $lastUid = (int) ($job['index_after_uid'] ?? 0);
        $oldState = $this->folders->state($userId, $accountId, $folderId);
        $reset = $lastUid === 0 && ($oldState !== null) && (
            (int) ($oldState['uidvalidity'] ?? 0) !== (int) $status['uidvalidity']
            || (bool) $status['uid_not_sticky']
        );
        $locations = ($lastUid === 0 && ($oldState === null || $reset))
            ? []
            : $this->folders->locationsByUid($userId, $accountId, $folderId);
        if ($reset) {
            ++$job['counters']['uidvalidity_resets'];
            $this->logger->log($userId, $accountId, 'warning', 'uidvalidity_reset', [
                'job_id' => $job['job_id'],
                'folder_id' => $folderId,
                'old_uidvalidity' => $oldState['uidvalidity'] ?? null,
                'new_uidvalidity' => $status['uidvalidity'],
                'uid_not_sticky' => $status['uid_not_sticky'],
            ]);
        }

        if ($lastUid === 0) {
            $job['index_mailbox_totals'][$folderId] = count($remoteUids);
        }
        $limit = (int) $config['performance']['max_index_entries_per_request'];
        $remaining = array_values(array_filter($remoteUids, static fn (int $uid): bool => $uid > $lastUid));
        $batchUids = array_slice($remaining, 0, $limit);
        $metadata = [];
        $metadataBatchSize = (int) $config['performance']['imap_metadata_batch_size'];
        $useEmailId = (bool) ($config['imap']['use_emailid_optimization'] ?? true);
        $processedUids = [];
        foreach (array_chunk($batchUids, $metadataBatchSize) as $chunk) {
            if ($processedUids !== [] && microtime(true) - $requestStarted >= (int) $config['performance']['max_request_runtime_seconds']) break;
            $metadata += $imap->fetchMetadata($chunk, $useEmailId);
            array_push($processedUids, ...$chunk);
        }
        $batchUids = $processedUids;
        $serverScope = $this->serverScope($config);
        foreach ($batchUids as $uid) {
            if (!isset($metadata[$uid])) {
                continue;
            }
            $remote = $metadata[$uid];
            $existing = $locations[$uid] ?? null;
            $hash = null;
            $contentState = 'pending';
            if ($existing !== null && ($existing['content_state'] ?? null) === 'materialized' && is_string($existing['hash'] ?? null)
                && $this->objects->exists($userId, $accountId, $existing['hash'])) {
                $hash = $existing['hash'];
                $contentState = 'materialized';
            } elseif ($useEmailId && !empty($remote['email_id'])) {
                $knownHash = $this->emailIds->lookup($userId, $accountId, $serverScope, (string) $remote['email_id']);
                if ($knownHash !== null && $this->objects->exists($userId, $accountId, $knownHash)) {
                    $hash = $knownHash;
                    $contentState = 'materialized';
                    ++$job['counters']['emailid_reused'];
                }
            }
            $newLocation = [
                'uid' => $uid,
                'hash' => $hash,
                'content_state' => $contentState,
                'flags' => array_values($remote['flags'] ?? []),
                'internal_date' => $remote['internal_date'] ?? null,
                'size' => (int) ($remote['size'] ?? 0),
            ];
            if ($contentState === 'pending' && !empty($remote['email_id'])) {
                $newLocation['email_id'] = (string) $remote['email_id'];
            }
            if (($remote['modseq'] ?? null) !== null) {
                $newLocation['modseq'] = (int) $remote['modseq'];
            }
            if ($existing === null) {
                ++$job['counters']['locations_added'];
            } elseif (($existing['flags'] ?? []) !== $newLocation['flags']) {
                ++$job['counters']['flags_changed'];
            }
            $locations[$uid] = $newLocation;
        }

        $hasMore = count($remaining) > count($batchUids);
        if (!$hasMore) {
            $remoteSet = array_fill_keys($remoteUids, true);
            foreach (array_keys($locations) as $uid) {
                if (!isset($remoteSet[$uid])) {
                    unset($locations[$uid]);
                    ++$job['counters']['locations_removed'];
                }
            }
        }
        $state = [
            'schema_version' => 1,
            'local_folder_id' => $folderId,
            'remote_name' => $mailbox['remote_name'],
            'mailbox_id' => $mailbox['mailbox_id'] ?? null,
            'uidvalidity' => (int) $status['uidvalidity'],
            'uidnext' => $status['uidnext'],
            'highestmodseq' => $status['highestmodseq'],
            'message_count' => (int) $status['message_count'],
            'uid_not_sticky' => (bool) $status['uid_not_sticky'],
            'last_indexed_at' => Time::now(),
            'last_successful_sync' => $oldState['last_successful_sync'] ?? null,
        ];
        $this->folders->commit($userId, $accountId, $folderId, $state, $locations, 'index_batch');
        $job['index_processed'] += count($batchUids);
        if ($hasMore) {
            $job['index_after_uid'] = (int) end($batchUids);
        } else {
            ++$job['mailbox_index'];
            ++$job['counters']['mailboxes_processed'];
            $job['index_after_uid'] = 0;
        }
        return $job;
    }

    private function fetchContent(string $userId, string $accountId, array $config, array $job, ImapAdapterInterface $imap, float $requestStarted): array
    {
        $pending = $this->pendingLocations($userId, $accountId);
        $job['pending_content'] = count($pending);
        if ($pending === []) {
            $job['phase'] = 'finalize';
            $job['finalize_cursor'] = 0;
            return $job;
        }
        $messageLimit = (int) $config['performance']['max_content_messages_per_request'];
        $byteLimit = (int) $config['performance']['max_content_bytes_per_request'];
        $runtimeLimit = (int) $config['performance']['max_request_runtime_seconds'];
        $selected = [];
        $plannedBytes = 0;
        foreach ($pending as $item) {
            $size = max(0, (int) ($item['location']['size'] ?? 0));
            if ($selected !== [] && (count($selected) >= $messageLimit || $plannedBytes + $size > $byteLimit)) {
                break;
            }
            $selected[] = $item;
            $plannedBytes += $size;
            if (count($selected) >= $messageLimit) {
                break;
            }
        }

        $imap->connect();
        $currentFolderId = null;
        $mailboxes = $this->selectableMailboxes($userId, $accountId);
        $mailboxIndexes = [];
        foreach ($mailboxes as $index => $mailbox) {
            $mailboxIndexes[$mailbox['local_folder_id']] = $index;
        }
        foreach ($selected as $processedInUnit => $item) {
            if ($processedInUnit > 0 && microtime(true) - $requestStarted >= $runtimeLimit) {
                break;
            }
            $folderId = (string) $item['mailbox']['local_folder_id'];
            $uid = (int) $item['location']['uid'];
            if ($currentFolderId !== $folderId) {
                $remoteStatus = $imap->examine((string) $item['mailbox']['remote_name']);
                $localState = $this->folders->state($userId, $accountId, $folderId);
                if ($localState === null || (int) ($localState['uidvalidity'] ?? 0) !== (int) $remoteStatus['uidvalidity']) {
                    $job['phase'] = 'index_mailboxes';
                    $job['mailbox_index'] = $mailboxIndexes[$folderId] ?? 0;
                    $job['index_after_uid'] = 0;
                    ++$job['counters']['reconciliations'];
                    return $job;
                }
                if ($remoteStatus['uid_not_sticky']) {
                    $currentUids = $imap->allUids();
                    if (!in_array($uid, $currentUids, true)) {
                        $job['phase'] = 'index_mailboxes';
                        $job['mailbox_index'] = $mailboxIndexes[$folderId] ?? 0;
                        $job['index_after_uid'] = 0;
                        ++$job['counters']['reconciliations'];
                        return $job;
                    }
                    $currentMetadata = $imap->fetchMetadata([$uid], true)[$uid] ?? null;
                    $expectedEmailId = $item['location']['email_id'] ?? null;
                    if ($expectedEmailId !== null && $currentMetadata !== null && ($currentMetadata['email_id'] ?? null) !== $expectedEmailId) {
                        $job['phase'] = 'index_mailboxes';
                        $job['mailbox_index'] = $mailboxIndexes[$folderId] ?? 0;
                        $job['index_after_uid'] = 0;
                        ++$job['counters']['reconciliations'];
                        return $job;
                    }
                }
                $currentFolderId = $folderId;
            }
            try {
                $markerPath = $this->materializationMarkerPath($userId, $accountId, $folderId, $uid);
                if (is_file($markerPath)) {
                    $marker = $this->json->read($markerPath);
                    $markerHash = $marker['hash'] ?? null;
                    $locations = $this->folders->locationsByUid($userId, $accountId, $folderId);
                    if (($marker['folder_id'] ?? null) === $folderId
                        && (int) ($marker['uid'] ?? 0) === $uid
                        && is_string($markerHash)
                        && $this->objects->verify($userId, $accountId, $markerHash)
                        && isset($locations[$uid])
                        && ($locations[$uid]['content_state'] ?? null) === 'pending') {
                        $emailId = $locations[$uid]['email_id'] ?? null;
                        $locations[$uid]['hash'] = $markerHash;
                        $locations[$uid]['content_state'] = 'materialized';
                        $state = $this->folders->state($userId, $accountId, $folderId) ?? [];
                        if (!($state['uid_not_sticky'] ?? false)) {
                            unset($locations[$uid]['email_id']);
                        }
                        $this->folders->commit($userId, $accountId, $folderId, $state, $locations, 'content_recovered');
                        if (is_string($emailId) && $emailId !== '') {
                            $this->emailIds->put($userId, $accountId, $this->serverScope($config), $emailId, $markerHash);
                        }
                        unlink($markerPath);
                        ++$job['content_current'];
                        $job['processed_bytes'] += (int) ($marker['bytes'] ?? 0);
                        ++$job['counters']['materializations_recovered'];
                        continue;
                    }
                    unlink($markerPath);
                }
                $raw = $imap->fetchRawMessage($uid);
            } catch (RemoteMessageGoneException) {
                $locations = $this->folders->locationsByUid($userId, $accountId, $folderId);
                unset($locations[$uid]);
                $state = $this->folders->state($userId, $accountId, $folderId) ?? [];
                $this->folders->commit($userId, $accountId, $folderId, $state, $locations, 'remote_uid_gone');
                ++$job['counters']['locations_removed'];
                $job['phase'] = 'index_mailboxes';
                $job['mailbox_index'] = $mailboxIndexes[$folderId] ?? 0;
                $job['index_after_uid'] = 0;
                ++$job['counters']['reconciliations'];
                return $job;
            }
            $markerPath = $this->materializationMarkerPath($userId, $accountId, $folderId, $uid);
            $object = $this->objects->materialize(
                $userId,
                $accountId,
                $raw,
                function (string $hash, int $bytes) use ($markerPath, $folderId, $uid): void {
                    $this->json->write($markerPath, [
                        'schema_version' => 1,
                        'folder_id' => $folderId,
                        'uid' => $uid,
                        'hash' => $hash,
                        'bytes' => $bytes,
                        'prepared_at' => Time::now(),
                    ]);
                },
            );
            $locations = $this->folders->locationsByUid($userId, $accountId, $folderId);
            if (!isset($locations[$uid]) || ($locations[$uid]['content_state'] ?? null) !== 'pending') {
                continue;
            }
            $emailId = $locations[$uid]['email_id'] ?? null;
            $locations[$uid]['hash'] = $object['hash'];
            $locations[$uid]['content_state'] = 'materialized';
            $state = $this->folders->state($userId, $accountId, $folderId) ?? [];
            if (!($state['uid_not_sticky'] ?? false)) {
                unset($locations[$uid]['email_id']);
            }
            $this->folders->commit($userId, $accountId, $folderId, $state, $locations, 'content_materialized');
            if (is_string($emailId) && $emailId !== '') {
                $this->emailIds->put($userId, $accountId, $this->serverScope($config), $emailId, $object['hash']);
            }
            if (is_file($markerPath)) {
                unlink($markerPath);
            }
            ++$job['content_current'];
            $job['processed_bytes'] += $object['bytes'];
            ++$job['counters']['eml_downloaded'];
            if ($object['deduplicated']) {
                ++$job['counters']['eml_deduplicated'];
            }
        }
        $job['pending_content'] = count($this->pendingLocations($userId, $accountId));
        if ($job['pending_content'] === 0) {
            $job['phase'] = 'finalize';
            $job['finalize_cursor'] = 0;
        }
        return $job;
    }

    private function finalize(string $userId, string $accountId, array $config, array $job, ImapAdapterInterface $imap): array
    {
        $pending = $this->pendingLocations($userId, $accountId);
        if ($pending !== []) {
            $job['phase'] = 'fetch_content';
            $job['pending_content'] = count($pending);
            return $job;
        }
        $mailboxes = $this->selectableMailboxes($userId, $accountId);
        $cursor = (int) ($job['finalize_cursor'] ?? 0);
        if (isset($mailboxes[$cursor])) {
            $mailbox = $mailboxes[$cursor];
            $folderId = (string) $mailbox['local_folder_id'];
            $imap->connect();
            $remoteState = $imap->examine((string) $mailbox['remote_name']);
            $remoteUids = $imap->allUids();
            $localState = $this->folders->state($userId, $accountId, $folderId);
            $localLocations = $this->folders->locationsByUid($userId, $accountId, $folderId);
            $localUids = array_keys($localLocations);
            sort($localUids, SORT_NUMERIC);
            if ($localState === null
                || (int) ($localState['uidvalidity'] ?? 0) !== (int) $remoteState['uidvalidity']
                || $localUids !== $remoteUids
                || $this->hasBrokenReferences($userId, $accountId, $localLocations)) {
                $job['phase'] = 'index_mailboxes';
                $job['mailbox_index'] = $cursor;
                $job['index_after_uid'] = 0;
                $job['finalize_cursor'] = 0;
                ++$job['counters']['reconciliations'];
                return $job;
            }
            ++$job['finalize_cursor'];
            if ($job['finalize_cursor'] < count($mailboxes)) {
                return $job;
            }
        }

        $completedAt = Time::now();
        foreach ($mailboxes as $mailbox) {
            $folderId = (string) $mailbox['local_folder_id'];
            $state = $this->folders->state($userId, $accountId, $folderId);
            if ($state === null) {
                continue;
            }
            $state['last_successful_sync'] = $completedAt;
            $locations = $this->folders->locationsByUid($userId, $accountId, $folderId);
            $this->folders->commit($userId, $accountId, $folderId, $state, $locations, 'sync_completed');
        }
        $runtimePath = $this->paths->account($userId, $accountId).'/runtime/account.json';
        $runtime = $this->json->read($runtimePath);
        $runtime['last_completed_sync'] = $completedAt;
        $this->json->write($runtimePath, $runtime);
        $job['state'] = 'completed';
        $job['phase'] = null;
        $job['completed_at'] = $completedAt;
        $job['pending_content'] = 0;
        $this->logger->log($userId, $accountId, 'info', 'sync_completed', [
            'job_id' => $job['job_id'],
            'started_at' => $job['started_at'],
            'completed_at' => $completedAt,
            'counters' => $job['counters'],
            'processed_bytes' => $job['processed_bytes'],
        ]);
        return $job;
    }

    private function enterContentPhase(string $userId, string $accountId, array $job): array
    {
        $pending = count($this->pendingLocations($userId, $accountId));
        $job['phase'] = $pending > 0 ? 'fetch_content' : 'finalize';
        $job['pending_content'] = $pending;
        $job['content_total'] = (int) ($job['content_current'] ?? 0) + $pending;
        $job['content_started_at'] ??= Time::now();
        $job['finalize_cursor'] = 0;
        return $job;
    }

    /** @return array<int, array<string, mixed>> */
    private function selectableMailboxes(string $userId, string $accountId): array
    {
        return array_values(array_filter(
            $this->catalog->all($userId, $accountId),
            static fn (array $row): bool => ($row['remote_present'] ?? false) && ($row['selectable'] ?? false),
        ));
    }

    /** @return array<int, array{mailbox:array,location:array}> */
    private function pendingLocations(string $userId, string $accountId): array
    {
        $pending = [];
        foreach ($this->selectableMailboxes($userId, $accountId) as $mailbox) {
            $folderId = (string) $mailbox['local_folder_id'];
            foreach ($this->folders->locations($userId, $accountId, $folderId) as $location) {
                if (($location['content_state'] ?? null) === 'pending' && ($location['hash'] ?? null) === null) {
                    $pending[] = ['mailbox' => $mailbox, 'location' => $location];
                }
            }
        }
        return $pending;
    }

    private function hasBrokenReferences(string $userId, string $accountId, array $locations): bool
    {
        foreach ($locations as $location) {
            if (($location['content_state'] ?? null) !== 'materialized'
                || !is_string($location['hash'] ?? null)
                || !$this->objects->exists($userId, $accountId, $location['hash'])) {
                return true;
            }
        }
        return false;
    }

    private function serverScope(array $config): string
    {
        return hash('sha256', strtolower((string) $config['imap']['hostname']).':'.(int) $config['imap']['port']);
    }

    private function materializationMarkerPath(string $userId, string $accountId, string $folderId, int $uid): string
    {
        $name = hash('sha256', $folderId."\0".$uid);
        return $this->paths->account($userId, $accountId).'/tmp/materialized-'.$name.'.json';
    }

    private function errorCode(\Throwable $e): string
    {
        return match (true) {
            $e instanceof IntegrityException => 'storage_integrity',
            $e instanceof CorruptStorageException => 'corrupt_storage',
            $e instanceof ConfigurationException => 'configuration',
            $e instanceof ImapException => 'imap_error',
            default => 'runtime_error',
        };
    }

    private function safeErrorMessage(\Throwable $e): string
    {
        // Library exceptions can include IMAP commands, credentials or local paths.
        return match (true) {
            $e instanceof ConfigurationException => mb_substr($e->getMessage(), 0, 800),
            $e instanceof IntegrityException, $e instanceof CorruptStorageException => 'Die lokalen Maildaten sind inkonsistent. Bitte den Serverstore prüfen.',
            $e instanceof ImapException => 'IMAP-Verbindung oder Abruf fehlgeschlagen. Bitte Zugangsdaten, Servereinstellungen und Erreichbarkeit prüfen.',
            default => 'Der Synchronisationsschritt konnte nicht abgeschlossen werden. Bitte Speicherrechte, freien Speicher und Serververbindung prüfen.',
        };
    }
}
