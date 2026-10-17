<?php

declare(strict_types=1);

$projectRoot = __DIR__;
require $projectRoot.'/vendor/autoload.php';

use MailMirror\Auth\FileAuthProvider;
use MailMirror\Auth\AuthRateLimiter;
use MailMirror\Config\AccountConfigValidator;
use MailMirror\Config\AccountRepository;
use MailMirror\Config\CredentialStore;
use MailMirror\Config\DeploymentConfig;
use MailMirror\Imap\ImapAdapterFactory;
use MailMirror\Logging\SyncLogger;
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

ini_set('memory_limit', getenv('FORESIGHT_MEMORY_LIMIT') ?: getenv('MAILMIRROR_MEMORY_LIMIT') ?: '512M');
ini_set('display_errors', '0');

$deployment = DeploymentConfig::load($projectRoot);
$writer = new AtomicFileWriter();
$writer->ensureDirectory($deployment->mailstoreRoot());
$writer->ensureDirectory($deployment->secretStoreRoot(), 0770);
$paths = new PathResolver($deployment->mailstoreRoot(), $deployment->secretStoreRoot());
$json = new JsonStore($writer);
$ndjson = new NdjsonStore($writer);
$validator = new AccountConfigValidator();
$credentials = new CredentialStore($paths, $json, $writer);
$accounts = new AccountRepository($paths, $json, $writer, $validator);
$auth = new FileAuthProvider($paths, $json, $writer);
$authLimiter = new AuthRateLimiter($paths, $json, $writer);
$catalog = new MailboxCatalog($paths, $ndjson, $writer);
$folders = new FolderStore($paths, $json, $ndjson, $writer);
$objects = new ContentObjectStore($paths, $json, $writer);
$emailIds = new EmailIdStore($paths, $ndjson);
$jobs = new SyncJobStore($paths, $json);
$imapFactory = new ImapAdapterFactory($credentials);
$logger = new SyncLogger($paths, $writer);
$sync = new SyncEngine($accounts, $jobs, $imapFactory, $catalog, $folders, $objects, $emailIds, $paths, $json, $logger);

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name($deployment->sessionName());
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $deployment->basePath().'/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

return compact(
    'projectRoot', 'deployment', 'writer', 'paths', 'json', 'ndjson', 'validator',
    'credentials', 'accounts', 'auth', 'authLimiter', 'catalog', 'folders', 'objects', 'emailIds',
    'jobs', 'imapFactory', 'logger', 'sync',
);
