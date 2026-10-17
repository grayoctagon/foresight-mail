<?php

declare(strict_types=1);

namespace MailMirror\Auth;

use MailMirror\Exception\AuthorizationException;
use MailMirror\Storage\AtomicFileWriter;
use MailMirror\Storage\JsonStore;
use MailMirror\Storage\PathResolver;
use MailMirror\Support\Ids;
use MailMirror\Support\Time;

final class FileAuthProvider
{
    public function __construct(
        private readonly PathResolver $paths,
        private readonly JsonStore $json,
        private readonly AtomicFileWriter $writer,
    ) {
    }

    public function register(string $username, string $password): UserContext
    {
        $username = trim($username);
        if (!preg_match('/^[\pL\pN][\pL\pN._-]{2,63}$/u', $username)) {
            throw new AuthorizationException('Benutzername: 3–64 Zeichen, Buchstaben/Ziffern sowie . _ -');
        }
        if (strlen($password) < 10) {
            throw new AuthorizationException('Das Passwort muss mindestens 10 Zeichen lang sein.');
        }

        $root = $this->paths->mailstoreRoot();
        $this->writer->ensureDirectory($root.'/users');
        $lock = fopen($root.'/users.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Benutzerstore kann nicht gesperrt werden.');
        }
        try {
            foreach (glob($root.'/users/*/user.json') ?: [] as $file) {
                $existing = $this->json->read($file);
                if (hash_equals(mb_strtolower((string) $existing['username']), mb_strtolower($username))) {
                    throw new AuthorizationException('Dieser Benutzername existiert bereits.');
                }
            }
            $id = Ids::uuid7();
            $dir = $this->paths->user($id);
            $this->writer->ensureDirectory($dir.'/accounts');
            $this->json->write($dir.'/user.json', [
                'schema_version' => 1,
                'user_id' => $id,
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'created_at' => Time::now(),
            ]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return new UserContext($id, $username);
    }

    public function login(string $username, string $password): UserContext
    {
        foreach (glob($this->paths->mailstoreRoot().'/users/*/user.json') ?: [] as $file) {
            $user = $this->json->read($file);
            if (hash_equals(mb_strtolower((string) $user['username']), mb_strtolower(trim($username)))
                && password_verify($password, (string) $user['password_hash'])) {
                return new UserContext((string) $user['user_id'], (string) $user['username']);
            }
        }
        throw new AuthorizationException('Benutzername oder Passwort ist falsch.');
    }

    public function current(): ?UserContext
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!is_string($id)) {
            return null;
        }
        $file = $this->paths->user($id).'/user.json';
        if (!is_file($file)) {
            return null;
        }
        $user = $this->json->read($file);
        return new UserContext((string) $user['user_id'], (string) $user['username']);
    }

    public function establish(UserContext $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user->userId;
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public function csrf(): string
    {
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
        }
        return (string) $_SESSION['csrf'];
    }

    public function verifyCsrf(?string $token): void
    {
        if (!is_string($token) || !hash_equals($this->csrf(), $token)) {
            throw new AuthorizationException('Ungültiges CSRF-Token.');
        }
    }
}
