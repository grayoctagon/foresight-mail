<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/FakeImap.php';

use MailMirror\Auth\FileAuthProvider;
use MailMirror\Config\AccountConfigValidator;
use MailMirror\Config\AccountRepository;
use MailMirror\Config\CredentialStore;
use MailMirror\Exception\ConfigurationException;
use MailMirror\Exception\CorruptStorageException;
use MailMirror\Exception\NotFoundException;
use MailMirror\Imap\ImapAdapterFactory;
use MailMirror\Logging\SyncLogger;
use MailMirror\Storage\AccountLock;
use MailMirror\Storage\AtomicFileWriter;
use MailMirror\Storage\ContentObjectStore;
use MailMirror\Storage\EmailIdStore;
use MailMirror\Storage\FolderStore;
use MailMirror\Storage\JsonStore;
use MailMirror\Storage\MailboxCatalog;
use MailMirror\Storage\NdjsonStore;
use MailMirror\Storage\PathResolver;
use MailMirror\Sync\SyncEngine;
use MailMirror\Sync\SyncJobStore;
use MailMirror\Tests\FakeImapAdapter;
use MailMirror\Tests\FakeImapState;

ini_set('memory_limit', '768M');

$root = sys_get_temp_dir().'/mailmirror-tests-'.bin2hex(random_bytes(5));
$mailstore = $root.'/mailstore';
$secrets = $root.'/secrets';
$writer = new AtomicFileWriter();
$writer->ensureDirectory($mailstore);
$writer->ensureDirectory($secrets, 0700);
$paths = new PathResolver($mailstore, $secrets);
$json = new JsonStore($writer);
$ndjson = new NdjsonStore($writer);
$credentials = new CredentialStore($paths, $json, $writer);
$accounts = new AccountRepository($paths, $json, $writer, new AccountConfigValidator());
$auth = new FileAuthProvider($paths, $json, $writer);
$catalog = new MailboxCatalog($paths, $ndjson, $writer);
$folders = new FolderStore($paths, $json, $ndjson, $writer);
$objects = new ContentObjectStore($paths, $json, $writer);
$emailIds = new EmailIdStore($paths, $ndjson);
$jobs = new SyncJobStore($paths, $json);
$logger = new SyncLogger($paths, $writer);
$fake = new FakeImapState();
$factory = new ImapAdapterFactory($credentials, static fn () => new FakeImapAdapter($fake));
$sync = new SyncEngine($accounts, $jobs, $factory, $catalog, $folders, $objects, $emailIds, $paths, $json, $logger);

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if (!$condition) {
        ++$failed;
        fwrite(STDERR, "FAIL: {$message}\n");
        return;
    }
    ++$passed;
    echo "ok - {$message}\n";
};
$same = static function (mixed $expected, mixed $actual, string $message) use ($assert): void {
    $assert($expected === $actual, $message.($expected === $actual ? '' : ' (erwartet '.var_export($expected, true).', erhalten '.var_export($actual, true).')'));
};
$until = static function (callable $step, callable $done, int $max = 200): array {
    $last = [];
    for ($i = 0; $i < $max; $i++) {
        $last = $step();
        if ($done($last)) {
            return $last;
        }
    }
    throw new RuntimeException('Test-Job erreichte den erwarteten Zustand nicht.');
};

try {
    $user = $auth->register('alice', 'correct-horse-battery-staple');
    $user2 = $auth->register('bob', 'another-correct-password');
    $rawA = "From: A <a@example.test>\r\nTo: B <b@example.test>\r\nSubject: Alpha\r\nMessage-ID: <same@example.test>\r\nContent-Type: text/plain\r\n\r\nBody A\r\n";
    $rawB = "From: A <a@example.test>\r\nTo: B <b@example.test>\r\nSubject: Beta\r\nMessage-ID: <same@example.test>\r\nContent-Type: text/plain\r\n\r\nBody B\r\n";
    $rawNoId = "From: nobody@example.test\nSubject: LF only\n\nNo message id\n";
    $binary = "From: bin@example.test\r\nContent-Type: application/octet-stream\r\nContent-Transfer-Encoding: binary\r\n\r\n"."\0\x01\rX\nY\xff";
    $fake->mailboxes = [
        'INBOX' => [
            'mailbox_id' => 'M-INBOX', 'uidvalidity' => 10,
            'messages' => [
                1 => ['raw' => $rawA, 'email_id' => 'E-A', 'flags' => []],
                2 => ['raw' => $rawB, 'email_id' => 'E-B', 'flags' => ['\\Seen']],
                3 => ['raw' => $rawA, 'email_id' => 'E-A', 'flags' => ['custom-keyword']],
                4 => ['raw' => $rawNoId, 'email_id' => 'E-N', 'flags' => []],
                5 => ['raw' => $binary, 'email_id' => 'E-BIN', 'flags' => []],
            ],
        ],
        'Archive' => ['mailbox_id' => 'M-ARCHIVE', 'uidvalidity' => 20, 'messages' => []],
        'Container' => ['mailbox_id' => 'M-CONTAINER', 'uidvalidity' => 30, 'attributes' => ['\\Noselect'], 'messages' => []],
    ];

    $account = $accounts->create($user->userId, [
        'label' => 'Testkonto', 'enabled' => true,
        'imap' => [
            'hostname' => 'fake.test', 'port' => 993, 'connection_security' => 'implicit_tls',
            'authentication' => ['method' => 'auto', 'username' => 'alice', 'credential_ref' => 'imap-main'],
            'timeout_seconds' => 30, 'validate_certificate' => true,
        ],
        'smtp' => null,
        'identities' => [[
            'label' => 'Alice', 'display_name' => 'Alice', 'from_address' => 'alice@example.test',
            'envelope_from' => 'alice@example.test', 'reply_to' => [], 'enabled' => true, 'is_default' => true,
            'signature' => ['enabled' => true, 'plain_text' => 'Gruß', 'html' => null, 'placement' => 'before_quote'],
        ]],
        'performance' => [
            'max_request_runtime_seconds' => 20, 'max_index_entries_per_request' => 2,
            'max_content_messages_per_request' => 1, 'max_content_bytes_per_request' => 32,
            'imap_metadata_batch_size' => 2,
        ],
    ]);
    $accountId = $account['account_id'];
    try {
        $credentials->password($user->userId, $accountId, 'missing-credential');
        $assert(false, 'fehlendes Credential wird als Konfigurationsfehler erkannt');
    } catch (ConfigurationException $e) {
        $assert(str_contains($e->getMessage(), 'Passwort'), 'fehlendes Credential wird als Konfigurationsfehler erkannt');
    }
    $credentials->savePassword($user->userId, $accountId, 'imap-main', 'secret');

    $assert(!class_exists('IMAP\\Connection'), 'keine Abhängigkeit von ext-imap');
    try {
        $accounts->get($user2->userId, $accountId);
        $assert(false, 'Ownership blockiert fremden Account');
    } catch (NotFoundException) {
        $assert(true, 'Ownership blockiert fremden Account');
    }

    $job = $sync->start($user->userId, $accountId);
    $same('queued', $job['state'], 'Sync-Start bleibt billig und queued');
    $job = $until(
        static fn () => $sync->advance($user->userId, $accountId),
        static fn (array $job): bool => $job['phase'] === 'fetch_content',
    );
    $same(5, $job['pending_content'], 'Index persistiert alle unbekannten Nachrichten als pending');
    $assert($job['content_total'] === 5, 'Content-Todo stammt aus Locations');
    $job = $until(
        static fn () => $sync->advance($user->userId, $accountId),
        static fn (array $job): bool => $job['state'] === 'completed',
    );
    $same(5, $fake->rawFetches, 'Initialsync lädt jede unbekannte Remote-Location einmal');
    $same(100.0, $job['progress_percent'], 'abgeschlossener Job meldet 100 Prozent');

    $mailboxes = $catalog->all($user->userId, $accountId);
    $inbox = array_values(array_filter($mailboxes, static fn (array $m): bool => $m['remote_name'] === 'INBOX'))[0];
    $archive = array_values(array_filter($mailboxes, static fn (array $m): bool => $m['remote_name'] === 'Archive'))[0];
    $inboxLocations = $folders->locationsByUid($user->userId, $accountId, $inbox['local_folder_id']);
    $hashA = hash('sha256', $rawA);
    $hashB = hash('sha256', $rawB);
    $same($hashA, $inboxLocations[1]['hash'], 'Content-Hash basiert auf exakten EML-Bytes');
    $same($hashA, $inboxLocations[3]['hash'], 'identische EML wird contentseitig dedupliziert');
    $assert($hashA !== $hashB, 'gleiche Message-ID mit anderem Body wird nicht zusammengeführt');
    $same($rawA, file_get_contents($objects->objectPath($user->userId, $accountId, $hashA)), 'gespeicherte CRLF-EML ist bytegetreu');
    $same($rawNoId, file_get_contents($objects->objectPath($user->userId, $accountId, hash('sha256', $rawNoId))), 'EML ohne Message-ID funktioniert und LF bleibt erhalten');
    $same($binary, file_get_contents($objects->objectPath($user->userId, $accountId, hash('sha256', $binary))), 'Binary-MIME bleibt bytegetreu');
    $same(5, count($inboxLocations), 'alle Remote-Locations sind materialisiert');

    $fake->mailboxes['INBOX']['messages'][1]['flags'] = ['\\Deleted'];
    $fake->mailboxes['INBOX']['messages'][2]['flags'] = ['\\Seen', '\\Flagged'];
    $job = $sync->start($user->userId, $accountId);
    $job = $until(static fn () => $sync->advance($user->userId, $accountId), static fn (array $j): bool => $j['state'] === 'completed');
    $same(5, $fake->rawFetches, 'reiner Flag-Sync lädt keine Bodies erneut');
    $inboxLocations = $folders->locationsByUid($user->userId, $accountId, $inbox['local_folder_id']);
    $assert(in_array('\\Deleted', $inboxLocations[1]['flags'], true), '\\Deleted behält die Location bis EXPUNGE');
    $assert(in_array('\\Flagged', $inboxLocations[2]['flags'], true), 'Flag-Änderung ohne UID-Änderung wird gespiegelt');

    unset($fake->mailboxes['INBOX']['messages'][1]);
    $fake->mailboxes['Archive']['messages'][10] = ['raw' => $rawA, 'email_id' => 'E-A', 'flags' => []];
    $job = $sync->start($user->userId, $accountId);
    $job = $until(static fn () => $sync->advance($user->userId, $accountId), static fn (array $j): bool => $j['state'] === 'completed');
    $same(5, $fake->rawFetches, 'COPY/MOVE-Ziel mit bekannter EMAILID vermeidet Redownload');
    $archiveLocations = $folders->locationsByUid($user->userId, $accountId, $archive['local_folder_id']);
    $same($hashA, $archiveLocations[10]['hash'], 'COPY nutzt denselben Content-Hash in zweiter Mailbox');
    $inboxLocations = $folders->locationsByUid($user->userId, $accountId, $inbox['local_folder_id']);
    $assert(!isset($inboxLocations[1]), 'EXPUNGE entfernt nur die Location');
    $assert(is_file($objects->objectPath($user->userId, $accountId, $hashA)), 'referenzloser/historischer Content wird nicht automatisch gelöscht');

    $oldFolderId = $archive['local_folder_id'];
    $fake->mailboxes['Archiv'] = $fake->mailboxes['Archive'];
    unset($fake->mailboxes['Archive']);
    $job = $sync->start($user->userId, $accountId);
    $job = $until(static fn () => $sync->advance($user->userId, $accountId), static fn (array $j): bool => $j['state'] === 'completed');
    $renamed = array_values(array_filter($catalog->all($user->userId, $accountId), static fn (array $m): bool => $m['remote_name'] === 'Archiv'))[0];
    $same($oldFolderId, $renamed['local_folder_id'], 'Mailbox-Rename mit MAILBOXID behält lokale Folder-ID');

    $fake->capabilities = ['IMAP4REV1'];
    $fake->mailboxes['Temporary'] = ['mailbox_id' => null, 'uidvalidity' => 31, 'messages' => []];
    $job = $sync->start($user->userId, $accountId);
    $job = $until(static fn () => $sync->advance($user->userId, $accountId), static fn (array $j): bool => $j['state'] === 'completed');
    $temporaryOld = array_values(array_filter($catalog->all($user->userId, $accountId), static fn (array $m): bool => $m['remote_name'] === 'Temporary' && $m['remote_present']))[0];
    unset($fake->mailboxes['Temporary']);
    $job = $sync->start($user->userId, $accountId);
    $job = $until(static fn () => $sync->advance($user->userId, $accountId), static fn (array $j): bool => $j['state'] === 'completed');
    $fake->mailboxes['Temporary'] = ['mailbox_id' => null, 'uidvalidity' => 32, 'messages' => []];
    $job = $sync->start($user->userId, $accountId);
    $job = $until(static fn () => $sync->advance($user->userId, $accountId), static fn (array $j): bool => $j['state'] === 'completed');
    $temporaryNew = array_values(array_filter($catalog->all($user->userId, $accountId), static fn (array $m): bool => $m['remote_name'] === 'Temporary' && $m['remote_present']))[0];
    $assert($temporaryOld['local_folder_id'] !== $temporaryNew['local_folder_id'], 'neu angelegte namensgleiche Mailbox ohne MAILBOXID erhält neue lokale Identität');
    $fake->capabilities = ['IMAP4REV1', 'OBJECTID', 'CONDSTORE'];

    $accounts->update($user->userId, $accountId, ['imap' => ['use_emailid_optimization' => false]]);
    $fake->mailboxes['Archiv']['messages'][12] = ['raw' => $rawA, 'email_id' => 'E-A', 'flags' => []];
    $beforeDisabledOptimization = $fake->rawFetches;
    $job = $sync->start($user->userId, $accountId);
    $job = $until(static fn () => $sync->advance($user->userId, $accountId), static fn (array $j): bool => $j['state'] === 'completed');
    $same($beforeDisabledOptimization + 1, $fake->rawFetches, 'abschaltbare EMAILID-Optimierung erzwingt definitive Full-EML-Zuordnung');
    $accounts->update($user->userId, $accountId, ['imap' => ['use_emailid_optimization' => true]]);

    $fake->mailboxes['INBOX']['uidvalidity'] = 11;
    $fake->mailboxes['INBOX']['messages'] = [50 => ['raw' => $rawB, 'email_id' => 'E-B', 'flags' => []]];
    $beforeResetFetches = $fake->rawFetches;
    $job = $sync->start($user->userId, $accountId);
    $job = $until(static fn () => $sync->advance($user->userId, $accountId), static fn (array $j): bool => $j['state'] === 'completed');
    $same($beforeResetFetches, $fake->rawFetches, 'UIDVALIDITY-Reconciliation nutzt bekannte EMAILID');
    $inboxLocations = $folders->locationsByUid($user->userId, $accountId, $inbox['local_folder_id']);
    $same([50], array_keys($inboxLocations), 'UIDVALIDITY-Wechsel invalidiert alte UID-Zuordnungen');
    $same($hashB, $inboxLocations[50]['hash'], 'Content Store bleibt bei UIDVALIDITY-Wechsel gültig');

    $fake->mailboxes['INBOX']['uid_not_sticky'] = true;
    $beforeNotStickyFetches = $fake->rawFetches;
    $job = $sync->start($user->userId, $accountId);
    $job = $until(static fn () => $sync->advance($user->userId, $accountId), static fn (array $j): bool => $j['state'] === 'completed');
    $same($beforeNotStickyFetches, $fake->rawFetches, 'UIDNOTSTICKY wird bei jedem Job konservativ über EMAILID reconciled');
    $assert($folders->state($user->userId, $accountId, $inbox['local_folder_id'])['uid_not_sticky'] === true, 'UIDNOTSTICKY wird im Mailbox-State persistiert');
    $fake->mailboxes['INBOX']['uid_not_sticky'] = false;

    $rawRecovered = "Subject: Recovered\r\n\r\nCommitted before location";
    $fake->mailboxes['INBOX']['messages'][51] = ['raw' => $rawRecovered, 'email_id' => 'E-RECOVER', 'flags' => []];
    $job = $sync->start($user->userId, $accountId);
    $job = $until(static fn () => $sync->advance($user->userId, $accountId), static fn (array $j): bool => $j['phase'] === 'fetch_content');
    $beforeMarkerRecovery = $fake->rawFetches;
    $prepared = $objects->materialize($user->userId, $accountId, $rawRecovered);
    $markerName = hash('sha256', $inbox['local_folder_id']."\0".'51');
    $json->write($paths->account($user->userId, $accountId).'/tmp/materialized-'.$markerName.'.json', [
        'schema_version' => 1, 'folder_id' => $inbox['local_folder_id'], 'uid' => 51,
        'hash' => $prepared['hash'], 'bytes' => $prepared['bytes'], 'prepared_at' => gmdate('c'),
    ]);
    $job = $sync->advance($user->userId, $accountId);
    $same($beforeMarkerRecovery, $fake->rawFetches, 'Recovery-Marker vermeidet Redownload nach Content-Commit vor Location-Commit');
    $same(1, $job['counters']['materializations_recovered'], 'Recovery wird im Job protokolliert');
    $job = $until(static fn () => $sync->advance($user->userId, $accountId), static fn (array $j): bool => $j['state'] === 'completed');

    $rawRetry = "Subject: Retry\r\n\r\nRetry body";
    $fake->mailboxes['INBOX']['messages'][52] = ['raw' => $rawRetry, 'email_id' => 'E-RETRY', 'flags' => []];
    $job = $sync->start($user->userId, $accountId);
    $job = $until(static fn () => $sync->advance($user->userId, $accountId), static fn (array $j): bool => $j['phase'] === 'fetch_content');
    $fake->failNextRaw = true;
    $job = $sync->advance($user->userId, $accountId);
    $assert($job['state'] === 'running' && $job['last_error']['retryable'] === true, 'Netzwerkfehler bleibt retrybar und Job resumierbar');
    $parts = glob($paths->account($user->userId, $accountId).'/tmp/*.part') ?: [];
    $same([], $parts, 'abgebrochener Fetch erzeugt kein finales oder halbfertiges EML-Objekt');
    $job = $until(static fn () => $sync->advance($user->userId, $accountId), static fn (array $j): bool => $j['state'] === 'completed');
    $assert($objects->verify($user->userId, $accountId, hash('sha256', $rawRetry)), 'Retry materialisiert die offene Pending-Location');

    $sync->start($user->userId, $accountId);
    $externalLock = new AccountLock();
    $externalLock->acquire($paths->account($user->userId, $accountId).'/locks/sync.lock');
    $busy = $sync->advance($user->userId, $accountId);
    $assert(($busy['busy'] ?? false) === true, 'überlappender Worker erhält busy statt paralleler Schreibarbeit');
    $externalLock->release();

    $updated = $accounts->update($user->userId, $accountId, ['identities' => [[
        'identity_id' => $account['identities'][0]['identity_id'], 'label' => 'Alice', 'display_name' => 'Alice',
        'from_address' => 'alice@example.test', 'envelope_from' => 'bounce@example.test', 'reply_to' => [],
        'enabled' => true, 'is_default' => true,
        'signature' => ['enabled' => true, 'plain_text' => 'Gruß', 'html' => null, 'placement' => 'after_quote'],
    ]]]);
    $same('after_quote', $updated['identities'][0]['signature']['placement'], 'Signature Placement wird gespeichert und erneut geladen');
    $same($account['identities'][0]['identity_id'], $updated['identities'][0]['identity_id'], 'Identity-ID bleibt immutable');

    $corrupt = $root.'/corrupt.json';
    file_put_contents($corrupt, '{broken');
    try {
        $json->read($corrupt);
        $assert(false, 'beschädigte JSON-Datei wird erkannt');
    } catch (CorruptStorageException) {
        $assert(true, 'beschädigte JSON-Datei wird erkannt und nicht überschrieben');
    }

    $recoveryFolder = $inbox['local_folder_id'];
    $recoveryRoot = $paths->folder($user->userId, $accountId, $recoveryFolder);
    $recoveryState = $folders->state($user->userId, $accountId, $recoveryFolder);
    $recoveryState['message_count'] = 777;
    $recoveryLocations = array_values($folders->locationsByUid($user->userId, $accountId, $recoveryFolder));
    $json->write($recoveryRoot.'/commit.pending.json', ['schema_version'=>1,'state'=>$recoveryState,'locations'=>$recoveryLocations]);
    $folders->recover($user->userId, $accountId, $recoveryFolder);
    $same(777, $folders->state($user->userId, $accountId, $recoveryFolder)['message_count'], 'unvollständiger Index-Commit wird deterministisch recovered');

    $largeRowsPath = $root.'/50000.ndjson';
    $rows = (static function (): Generator { for ($i = 1; $i <= 50000; $i++) yield ['uid'=>$i,'hash'=>null,'content_state'=>'pending','flags'=>[]]; })();
    $ndjson->write($largeRowsPath, $rows);
    $same(50000, count($ndjson->readAll($largeRowsPath)), '50.000 Locations werden ohne Hardlimit verarbeitet');

    $large = "Subject: 100 MB acceptance\r\nContent-Type: application/octet-stream\r\n\r\n".str_repeat('A', 100 * 1024 * 1024);
    $largeObject = $objects->materialize($user->userId, $accountId, $large);
    $same(strlen($large), filesize($largeObject['path']), '100-MB-Nachricht wird vollständig gespeichert');
    $assert(hash_equals(hash('sha256', $large), hash_file('sha256', $largeObject['path'])), '100-MB-Nachricht erhält korrekten SHA-256');

    echo "\n{$passed} Prüfungen bestanden, {$failed} fehlgeschlagen.\n";
} finally {
    $delete = static function (string $path) use (&$delete): void {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.','..']) as $name) $delete($path.'/'.$name);
            rmdir($path);
        } else {
            unlink($path);
        }
    };
    $delete($root);
}

exit($failed === 0 ? 0 : 1);
