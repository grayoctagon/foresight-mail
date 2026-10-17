<?php

declare(strict_types=1);

use MailMirror\Exception\AuthorizationException;
use MailMirror\Exception\ConfigurationException;
use MailMirror\Exception\CorruptStorageException;
use MailMirror\Exception\IntegrityException;
use MailMirror\Exception\NotFoundException;
use MailMirror\Exception\BusyException;
use MailMirror\Exception\RateLimitException;

/** @var array<string,mixed> $app Loaded by public/api.php. */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$respond = static function (mixed $data, int $status = 200) use (&$segments): never {
    http_response_code($status);
    // Legacy keys remain additive for existing API/CLI clients.
    $failed = $status >= 400;
    $payload = ['ok' => !$failed, 'data' => $failed ? null : $data, 'error' => $failed ? ($data['error'] ?? ['code' => 'ERROR', 'message' => 'Anfrage fehlgeschlagen.']) : null];
    if (($segments[0] ?? '') !== 'webmail') $payload += is_array($data) ? $data : [];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit;
};

$body = static function (): array {
    $raw = file_get_contents('php://input');
    if (strlen((string) $raw) > 262144) throw new \InvalidArgumentException('Anfrage ist zu groß.');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || !str_starts_with(ltrim($raw), '{')) {
        throw new \InvalidArgumentException('JSON-Body muss ein Objekt sein.');
    }
    return $decoded;
};

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = $_SERVER['PATH_INFO'] ?? ($_GET['route'] ?? '/');
$segments = array_map('rawurldecode', array_values(array_filter(explode('/', trim((string) $path, '/')), 'strlen')));

try {
    if ($method !== 'GET') {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($origin !== null) {
            $originParts = parse_url($origin);
            $originHost = ($originParts['host'] ?? '').(isset($originParts['port']) ? ':'.$originParts['port'] : '');
            if (!hash_equals(strtolower($host), strtolower($originHost))) throw new AuthorizationException('Fremder Request-Origin ist nicht erlaubt.');
        }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 262144) throw new \InvalidArgumentException('Anfrage ist zu groß.');
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) throw new \InvalidArgumentException('Content-Type application/json erforderlich.');
    }
    if ($method === 'GET' && $segments === ['session']) {
        $user = $app['auth']->current();
        $respond([
            'authenticated' => $user !== null,
            'user' => $user ? ['user_id' => $user->userId, 'username' => $user->username] : null,
            'csrf_token' => $user ? $app['auth']->csrf() : null,
            'registration_allowed' => $app['deployment']->registrationAllowed(),
        ]);
    }

    if ($method === 'POST' && $segments === ['register']) {
        if (!$app['deployment']->registrationAllowed()) {
            throw new AuthorizationException('Registrierung ist deaktiviert.');
        }
        $input = $body();
        if (!is_string($input['username'] ?? null) || !is_string($input['password'] ?? null) || strlen($input['username']) > 256 || strlen($input['password']) > 4096) throw new \InvalidArgumentException('Ungültige Anmeldedaten.');
        $app['authLimiter']->consume('register', $_SERVER['REMOTE_ADDR'] ?? '', $input['username']);
        $user = $app['auth']->register($input['username'], $input['password']);
        $app['auth']->establish($user);
        $respond(['ok' => true, 'user' => ['user_id' => $user->userId, 'username' => $user->username], 'csrf_token' => $app['auth']->csrf()], 201);
    }

    if ($method === 'POST' && $segments === ['login']) {
        $input = $body();
        if (!is_string($input['username'] ?? null) || !is_string($input['password'] ?? null) || strlen($input['username']) > 256 || strlen($input['password']) > 4096) throw new \InvalidArgumentException('Ungültige Anmeldedaten.');
        $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $app['authLimiter']->consume('login', $remoteAddress, $input['username']);
        $user = $app['auth']->login($input['username'], $input['password']);
        $app['authLimiter']->successfulLogin($remoteAddress, $input['username']);
        $app['auth']->establish($user);
        $respond(['ok' => true, 'user' => ['user_id' => $user->userId, 'username' => $user->username], 'csrf_token' => $app['auth']->csrf()]);
    }

    $user = $app['auth']->current();
    if ($user === null) {
        throw new AuthorizationException('Anmeldung erforderlich.');
    }
    if ($method !== 'GET') {
        $app['auth']->verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    }

    if (($segments[0] ?? '') === 'webmail') {
        require $app['projectRoot'].'/src/Webmail/routes.php';
    }

    $withCredentialStatus = static function (array $account) use ($app, $user): array {
        $imapRef = (string) ($account['imap']['authentication']['credential_ref'] ?? '');
        $smtpRef = (string) ($account['smtp']['authentication']['credential_ref'] ?? '');
        $account['credential_status'] = [
            'imap_configured' => $imapRef !== '' && $app['credentials']->exists($user->userId, (string) $account['account_id'], $imapRef),
            'smtp_configured' => $account['smtp'] === null
                ? null
                : ($smtpRef !== '' && $app['credentials']->exists($user->userId, (string) $account['account_id'], $smtpRef)),
        ];
        return $account;
    };

    if ($method === 'POST' && $segments === ['logout']) {
        $app['auth']->logout();
        $respond(['ok' => true]);
    }

    if ($method === 'GET' && $segments === ['accounts']) {
        $respond(['accounts' => array_map($withCredentialStatus, $app['accounts']->list($user->userId))]);
    }

    if ($method === 'POST' && $segments === ['accounts']) {
        $input = $body();
        if (trim((string) ($input['imap_password'] ?? '')) === '') {
            throw new ConfigurationException('Das IMAP-Passwort ist für einen neuen Account erforderlich.');
        }
        $app['credentials']->assertStorageWritable();
        $account = $app['accounts']->create($user->userId, $input, static function (array $account) use ($app, $user, $input): void {
            $accountId = (string) $account['account_id'];
            $imapRef = (string) $account['imap']['authentication']['credential_ref'];
            $app['credentials']->savePassword($user->userId, $accountId, $imapRef, (string) $input['imap_password']);
            if ($account['smtp'] !== null && trim((string) ($input['smtp_password'] ?? '')) !== '') {
                $smtpRef = (string) $account['smtp']['authentication']['credential_ref'];
                $app['credentials']->savePassword($user->userId, $accountId, $smtpRef, (string) $input['smtp_password']);
            }
        });
        $respond(['account' => $withCredentialStatus($account)], 201);
    }

    if (($segments[0] ?? null) === 'accounts' && isset($segments[1])) {
        $accountId = (string) $segments[1];
        if (count($segments) === 2 && $method === 'GET') {
            $respond(['account' => $withCredentialStatus($app['accounts']->get($user->userId, $accountId))]);
        }
        if (count($segments) === 2 && $method === 'PUT') {
            $input = $body();
            $existing = $app['accounts']->get($user->userId, $accountId);
            $oldImapRef = (string) $existing['imap']['authentication']['credential_ref'];
            $newImapRef = (string) ($input['imap']['authentication']['credential_ref'] ?? $oldImapRef);
            $imapPassword = (string) ($input['imap_password'] ?? '');
            if ($newImapRef !== $oldImapRef && trim($imapPassword) === '') {
                throw new ConfigurationException('Bei Änderung der IMAP-Credential-Referenz ist das Passwort erforderlich.');
            }
            if (!$app['credentials']->exists($user->userId, $accountId, $oldImapRef) && trim($imapPassword) === '') {
                throw new ConfigurationException('Für diesen Account fehlt das IMAP-Passwort. Bitte unter „Verbindungen“ ein Passwort eingeben.');
            }
            $app['credentials']->assertStorageWritable();
            $smtpPassword = (string) ($input['smtp_password'] ?? '');
            $account = $app['accounts']->update($user->userId, $accountId, $input, static function (array $account) use ($app, $user, $accountId, $imapPassword, $smtpPassword): void {
                if (trim($imapPassword) !== '') $app['credentials']->savePassword($user->userId, $accountId, $account['imap']['authentication']['credential_ref'], $imapPassword);
                if ($account['smtp'] !== null && trim($smtpPassword) !== '') $app['credentials']->savePassword($user->userId, $accountId, $account['smtp']['authentication']['credential_ref'], $smtpPassword);
            });
            $respond(['account' => $withCredentialStatus($account)]);
        }
        if (count($segments) === 2 && $method === 'DELETE') {
            $app['accounts']->archive($user->userId, $accountId);
            $respond(['ok' => true, 'recoverable' => true]);
        }

        if (($segments[2] ?? null) === 'sync' && count($segments) === 4) {
            $operation = $segments[3];
            if ($method === 'POST' && $operation === 'check') {
                session_write_close();
                $result = $app['sync']->check($user->userId, $accountId);
                header('Retry-After: '.$result['retryAfterSeconds']);
                $respond($result);
            }
            if ($method === 'POST' && $operation === 'start') {
                session_write_close();
                $respond(['job' => $app['sync']->start($user->userId, $accountId)]);
            }
            if ($method === 'POST' && $operation === 'advance') {
                session_write_close();
                $respond(['job' => $app['sync']->advance($user->userId, $accountId)]);
            }
            if ($method === 'GET' && $operation === 'status') {
                session_write_close();
                $respond(['job' => $app['sync']->status($user->userId, $accountId)]);
            }
        }

        if (($segments[2] ?? null) === 'mirror' && $method === 'GET') {
            $app['accounts']->get($user->userId, $accountId);
            $folderId = isset($_GET['folder_id']) ? (string) $_GET['folder_id'] : null;
            if ($folderId === null || $folderId === '') {
                $result = [];
                foreach ($app['catalog']->all($user->userId, $accountId) as $mailbox) {
                    $locations = $app['folders']->locations($user->userId, $accountId, (string) $mailbox['local_folder_id']);
                    $pending = count(array_filter($locations, static fn (array $row): bool => ($row['content_state'] ?? null) === 'pending'));
                    $result[] = $mailbox + ['location_count' => count($locations), 'pending_count' => $pending];
                }
                $respond(['folders' => $result]);
            }
            $mailbox = $app['catalog']->find($user->userId, $accountId, $folderId);
            if ($mailbox === null) {
                throw new NotFoundException('Ordner nicht gefunden.');
            }
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 100)));
            $all = array_reverse($app['folders']->locations($user->userId, $accountId, $folderId));
            $page = array_slice($all, $offset, $limit);
            foreach ($page as &$location) {
                if (($location['content_state'] ?? null) !== 'materialized' || !is_string($location['hash'] ?? null)) {
                    continue;
                }
                $emlPath = $app['objects']->objectPath($user->userId, $accountId, $location['hash']);
                $metadataPath = substr($emlPath, 0, -4).'.json';
                if (is_file($metadataPath)) {
                    $metadata = $app['json']->read($metadataPath);
                    $location['metadata'] = [
                        'subject' => $metadata['subject'] ?? null,
                        'from' => $metadata['from'] ?? [],
                        'date_header' => $metadata['date_header'] ?? null,
                        'bytes' => $metadata['bytes'] ?? null,
                    ];
                }
            }
            unset($location);
            $respond(['folder' => $mailbox, 'locations' => $page, 'offset' => $offset, 'limit' => $limit, 'total' => count($all)]);
        }

        if (($segments[2] ?? null) === 'objects' && isset($segments[3]) && $method === 'GET') {
            $app['accounts']->get($user->userId, $accountId);
            $hash = (string) $segments[3];
            $path = $app['objects']->objectPath($user->userId, $accountId, $hash);
            if (!is_file($path)) {
                throw new NotFoundException('EML-Objekt nicht gefunden.');
            }
            session_write_close();
            header_remove('Content-Type');
            header('Content-Type: message/rfc822');
            header('Content-Length: '.filesize($path));
            header('Content-Disposition: attachment; filename="'.$hash.'.eml"');
            readfile($path);
            exit;
        }
    }

    throw new NotFoundException('API-Route nicht gefunden.');
} catch (RateLimitException $e) {
    header('Retry-After: '.$e->retryAfter);
    $respond(['error' => ['code'=>'AUTH_RATE_LIMITED', 'message'=>$e->getMessage(), 'retryAfterSeconds'=>$e->retryAfter]], 429);
} catch (AuthorizationException $e) {
    $respond(['error' => ['code' => 'unauthorized', 'message' => $e->getMessage()]], 401);
} catch (NotFoundException $e) {
    $respond(['error' => ['code' => 'not_found', 'message' => $e->getMessage()]], 404);
} catch (BusyException $e) {
    $respond(['error' => ['code' => 'INDEX_CHANGED', 'message' => $e->getMessage()]], 409);
} catch (ConfigurationException|\InvalidArgumentException|\JsonException $e) {
    $respond(['error' => ['code' => 'validation', 'message' => $e->getMessage()]], 422);
} catch (CorruptStorageException|IntegrityException $e) {
    $respond(['error' => ['code' => 'storage_integrity', 'message' => 'Lokale Daten sind beschädigt. Bitte den Serverstore prüfen.']], 500);
} catch (\Throwable $e) {
    error_log('foresight: '.$e::class.' (API request failed)');
    $respond(['error' => ['code' => 'internal_error', 'message' => 'Interner Fehler. Details stehen im Serverlog.']], 500);
}
