<?php
declare(strict_types=1);

namespace MailMirror\Webmail;

/** Explicit opt-in only. No browser requests, redirects, proxy env, cookies or credentials. */
final class ExternalImageLoader
{
    public const MAX_BYTES = 524288;
    private float $deadline;
    private int $attempts = 0;
    private int $bytes = 0;
    private array $cache = [];

    public function __construct(private readonly ?\Closure $resolver = null, private readonly ?\Closure $transport = null)
    {
        $this->deadline = microtime(true) + 8;
    }

    public static function publicAddress(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) return false;
        $packed = inet_pton($ip);
        if (strlen($packed) === 16) {
            // Only native global unicast: no mapped IPv4, NAT64, Teredo or 6to4.
            return (ord($packed[0]) & 0xe0) === 0x20
                && !str_starts_with($packed, "\x20\x02")
                && substr($packed, 0, 4) !== "\x20\x01\x00\x00";
        }
        $n = unpack('N', $packed)[1];
        return $n < 0xe0000000 // No multicast or class-E/reserved destinations.
            && ($n & 0xffc00000) !== 0x64400000 // Shared address space 100.64/10.
            && ($n & 0xffffff00) !== 0xc0586300; // Deprecated 6to4 relay range.
    }

    public function load(string $url): ?string
    {
        if (array_key_exists($url, $this->cache)) return $this->cache[$url];
        $this->cache[$url] = null;
        if (++$this->attempts > 4 || microtime(true) >= $this->deadline || $this->bytes >= 2097152) return null;
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return null;
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) return null;
        $host = strtolower($parts['host'] ?? '');
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) || strlen($host) > 253) return null;
        if (preg_match('/\.(?:localhost|local|internal|test|invalid|example|onion)$/D', $host)) return null;
        $ownHost = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
        if ($host === $ownHost) return null;
        try {
            if ($this->resolver) $addresses = ($this->resolver)($host);
            else {
                $addresses = [];
                foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                    if (isset($record['ip'])) $addresses[] = $record['ip'];
                    if (isset($record['ipv6'])) $addresses[] = $record['ipv6'];
                }
            }
            if (!$addresses || count($addresses) > 64) return null;
            foreach ($addresses as $ip) if (!is_string($ip) || !self::publicAddress($ip) || $ip === ($_SERVER['SERVER_ADDR'] ?? null)) return null;
            $path = ($parts['path'] ?? '/') ?: '/';
            if (isset($parts['query'])) $path .= '?'.$parts['query'];
            $timeout = min(2.0, $this->deadline - microtime(true));
            if ($timeout <= 0) return null;
            // The exact validated address is used for the connection; DNS cannot rebind it.
            $response = $this->transport ? ($this->transport)($host, $addresses[0], $path, $timeout) : $this->download($host, $addresses[0], $path, $timeout);
            if (!is_array($response) || ($response['status'] ?? 0) !== 200 || !is_string($response['body'] ?? null)) return null;
            $body = $response['body'];
            if (strlen($body) > self::MAX_BYTES || $this->bytes + strlen($body) > 2097152) return null;
            $info = @getimagesizefromstring($body);
            if (!$info || !in_array($info['mime'] ?? '', ['image/png','image/jpeg','image/gif','image/webp'], true) || $info[0] * $info[1] > 16000000) return null;
            $this->bytes += strlen($body);
            return $this->cache[$url] = 'data:'.$info['mime'].';base64,'.base64_encode($body);
        } catch (\Throwable) { return null; } // Remote failures never disclose URLs, IPs or server paths.
    }

    private function download(string $host, string $ip, string $path, float $timeout): ?array
    {
        $context = stream_context_create(['ssl' => ['verify_peer'=>true, 'verify_peer_name'=>true,
            'peer_name'=>$host, 'SNI_enabled'=>true, 'disable_compression'=>true,
            'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]]);
        $target = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $stream = @stream_socket_client('tls://'.$target.':443', $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$stream) return null;
        try {
            stream_set_timeout($stream, 2);
            $request = "GET {$path} HTTP/1.0\r\nHost: {$host}\r\nUser-Agent: foresight-image-loader\r\nAccept: image/png,image/jpeg,image/gif,image/webp\r\nAccept-Encoding: identity\r\nConnection: close\r\n\r\n";
            if (fwrite($stream, $request) !== strlen($request)) return null;
            $status = fgets($stream, 4096);
            if (!$status || !preg_match('~^HTTP/1\.[01] 200(?: |\r)~', $status)) return null;
            $headerBytes = strlen($status);
            while (($line = fgets($stream, 4096)) !== false) {
                $headerBytes += strlen($line);
                if ($headerBytes > 16384 || microtime(true) >= $this->deadline) return null;
                if ($line === "\r\n") break;
                if (preg_match('/^(?:Transfer-Encoding|Content-Encoding):\s*(?!identity\s*$)/i', trim($line))) return null;
                if (preg_match('/^Content-Length:\s*(\d+)/i', $line, $m) && (int) $m[1] > self::MAX_BYTES) return null;
            }
            if ($line !== "\r\n") return null;
            $body = '';
            while (!feof($stream) && strlen($body) <= self::MAX_BYTES && microtime(true) < $this->deadline) {
                $part = fread($stream, min(65536, self::MAX_BYTES + 1 - strlen($body)));
                if ($part === false || ($part === '' && !feof($stream))) return null;
                $body .= $part;
            }
            if (!feof($stream) || strlen($body) > self::MAX_BYTES || stream_get_meta_data($stream)['timed_out']) return null;
            return ['status'=>200, 'body'=>$body];
        } finally { fclose($stream); }
    }
}
