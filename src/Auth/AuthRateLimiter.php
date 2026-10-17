<?php
declare(strict_types=1);
namespace MailMirror\Auth;

use MailMirror\Exception\RateLimitException;
use MailMirror\Storage\AtomicFileWriter;
use MailMirror\Storage\JsonStore;
use MailMirror\Storage\PathResolver;

/** Shared, bounded and process-safe; independent of sessions/cookies and forwarded headers. */
final class AuthRateLimiter
{
    public const WINDOW = 900;
    private const MAX_BUCKETS = 4096;
    public function __construct(private readonly PathResolver $paths, private readonly JsonStore $json,
        private readonly AtomicFileWriter $writer, private readonly ?\Closure $clock = null) {}

    public function consume(string $action, string $ip, string $username): void
    {
        if (!in_array($action, ['login','register'], true)) throw new \InvalidArgumentException('Ungültige Auth-Aktion.');
        $now = $this->now();
        $keys = [$this->key($action, $ip) => $action === 'register' ? 5 : 40];
        if ($action === 'login') $keys[$this->key($action, $ip, $username)] = 5;
        $this->change(static function(array &$buckets) use ($keys, $now): void {
            foreach ($keys as $key => $limit) {
                if (($buckets[$key]['count'] ?? 0) >= $limit) throw new RateLimitException(max(1, $buckets[$key]['expires'] - $now));
            }
            $new = count(array_diff_key($keys, $buckets));
            if (count($buckets) + $new > self::MAX_BUCKETS) throw new RateLimitException(max(1, min(array_column($buckets, 'expires')) - $now));
            foreach ($keys as $key => $limit) {
                $buckets[$key] ??= ['count'=>0, 'expires'=>$now + self::WINDOW];
                ++$buckets[$key]['count']; // Reserve before password verification, including parallel requests.
            }
        });
    }

    public function successfulLogin(string $ip, string $username): void
    {
        $key = $this->key('login', $ip, $username);
        $this->change(static function(array &$buckets) use ($key): void { unset($buckets[$key]); });
    }

    private function key(string $action, string $ip, ?string $username = null): string
    {
        $ip = filter_var($ip, FILTER_VALIDATE_IP) ? inet_ntop(inet_pton($ip)) : 'unknown';
        return hash('sha256', $action."\0".$ip.($username === null ? '' : "\0".mb_strtolower(trim($username))));
    }

    private function now(): int { return $this->clock ? (int) ($this->clock)() : time(); }

    private function change(\Closure $update): void
    {
        $root = $this->paths->mailstoreRoot().'/auth-limits';
        $this->writer->ensureDirectory($root);
        $handle = fopen($root.'/attempts.lock', 'c+');
        if (!$handle) throw new \RuntimeException('Anmeldeschutz ist nicht verfügbar.');
        try {
            if (!flock($handle, LOCK_EX)) throw new \RuntimeException('Anmeldeschutz kann nicht gesperrt werden.');
            $buckets = $this->json->read($root.'/attempts.json', []);
            $now = $this->now();
            foreach ($buckets as $key => $bucket) {
                if (!is_array($bucket) || !is_int($bucket['expires'] ?? null) || !is_int($bucket['count'] ?? null)) throw new \RuntimeException('Anmeldeschutz ist beschädigt.');
                if ($bucket['expires'] <= $now) unset($buckets[$key]);
            }
            $update($buckets);
            $this->json->write($root.'/attempts.json', $buckets);
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
