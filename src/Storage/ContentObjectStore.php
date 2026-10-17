<?php

declare(strict_types=1);

namespace MailMirror\Storage;

use MailMirror\Exception\IntegrityException;

final class ContentObjectStore
{
    public function __construct(
        private readonly PathResolver $paths,
        private readonly JsonStore $json,
        private readonly AtomicFileWriter $writer,
    ) {
    }

    /** @return array{hash:string,bytes:int,deduplicated:bool,path:string} */
    public function materialize(string $userId, string $accountId, string $raw, ?callable $prepared = null): array
    {
        $accountRoot = $this->paths->account($userId, $accountId);
        $tmpRoot = $accountRoot.'/tmp';
        $this->writer->ensureDirectory($tmpRoot);
        $tmp = $tmpRoot.'/eml-'.bin2hex(random_bytes(12)).'.part';
        $handle = fopen($tmp, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('Temporäre EML kann nicht erstellt werden.');
        }
        $hashContext = hash_init('sha256');
        $length = strlen($raw);
        $offset = 0;
        try {
            while ($offset < $length) {
                $chunk = substr($raw, $offset, 1048576);
                $written = fwrite($handle, $chunk);
                if ($written === false || $written !== strlen($chunk)) {
                    throw new \RuntimeException('EML konnte nicht vollständig geschrieben werden.');
                }
                hash_update($hashContext, $chunk);
                $offset += $written;
            }
            fflush($handle);
            if (function_exists('fsync')) {
                fsync($handle);
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($tmp);
            throw $e;
        }
        fclose($handle);
        $hash = hash_final($hashContext);
        if ($prepared !== null) {
            $prepared($hash, $length);
        }
        $final = $this->objectPath($userId, $accountId, $hash);
        $this->writer->ensureDirectory(dirname($final));
        $deduplicated = is_file($final);
        if ($deduplicated) {
            if (!hash_equals($hash, hash_file('sha256', $final))) {
                @unlink($tmp);
                throw new IntegrityException("Content-Objekt unter {$hash} verletzt die Hash-Integrität.");
            }
            @unlink($tmp);
        } else {
            chmod($tmp, 0440);
            if (!rename($tmp, $final)) {
                @unlink($tmp);
                throw new \RuntimeException('EML konnte nicht atomar committed werden.');
            }
        }

        $metadataPath = substr($final, 0, -4).'.json';
        if (!is_file($metadataPath)) {
            $this->json->write($metadataPath, $this->metadata($hash, $raw));
        }
        return ['hash' => $hash, 'bytes' => $length, 'deduplicated' => $deduplicated, 'path' => $final];
    }

    public function discardStalePartials(string $userId, string $accountId): int
    {
        $count = 0;
        $tmpRoot = $this->paths->account($userId, $accountId).'/tmp';
        foreach (glob($tmpRoot.'/*.part') ?: [] as $path) {
            if (is_file($path) && unlink($path)) {
                ++$count;
            }
        }
        foreach (glob($tmpRoot.'/materialized-*.json') ?: [] as $path) {
            if (is_file($path) && filemtime($path) !== false && filemtime($path) < time() - 86400 && unlink($path)) {
                ++$count;
            }
        }
        return $count;
    }

    public function exists(string $userId, string $accountId, string $hash): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $hash) === 1 && is_file($this->objectPath($userId, $accountId, $hash));
    }

    public function verify(string $userId, string $accountId, string $hash): bool
    {
        $path = $this->objectPath($userId, $accountId, $hash);
        return is_file($path) && hash_equals($hash, hash_file('sha256', $path));
    }

    public function objectPath(string $userId, string $accountId, string $hash): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new \InvalidArgumentException('Ungültiger Content-Hash.');
        }
        return $this->paths->account($userId, $accountId).'/objects/'.substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash.'.eml';
    }

    public function rebuildMetadata(string $userId, string $accountId, string $hash): array
    {
        $path = $this->objectPath($userId, $accountId, $hash);
        if (!$this->verify($userId, $accountId, $hash)) {
            throw new IntegrityException("EML {$hash} fehlt oder ist beschädigt.");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('EML kann nicht gelesen werden.');
        }
        $metadata = $this->metadata($hash, $raw);
        $this->json->write(substr($path, 0, -4).'.json', $metadata);
        return $metadata;
    }

    private function metadata(string $hash, string $raw): array
    {
        $separator = strpos($raw, "\r\n\r\n");
        $separatorLength = 4;
        if ($separator === false) {
            $separator = strpos($raw, "\n\n");
            $separatorLength = 2;
        }
        $header = $separator === false ? $raw : substr($raw, 0, $separator + $separatorLength);
        $unfolded = preg_replace("/\r?\n[ \t]+/", ' ', $header) ?? $header;
        $values = [];
        foreach (preg_split('/\r?\n/', $unfolded) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $values[strtolower(trim($name))][] = trim($value);
        }
        $addresses = static function (array $headers, string $name): array {
            $result = [];
            foreach ($headers[$name] ?? [] as $value) {
                foreach (preg_split('/\s*,\s*/', $value) ?: [] as $part) {
                    if (preg_match('/<([^>]+)>/', $part, $match)) {
                        $part = $match[1];
                    }
                    $part = trim($part, " \t\"'");
                    if (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                        $result[] = $part;
                    }
                }
            }
            return array_values(array_unique($result));
        };
        $date = null;
        if (isset($values['date'][0])) {
            try {
                $date = (new \DateTimeImmutable($values['date'][0]))->format(DATE_ATOM);
            } catch (\Throwable) {
                $date = null;
            }
        }
        $contentType = strtolower(trim(explode(';', $values['content-type'][0] ?? 'text/plain')[0]));
        return [
            'schema_version' => 1,
            'content_hash' => $hash,
            'bytes' => strlen($raw),
            'header_sha256' => hash('sha256', $header),
            'message_id' => $values['message-id'][0] ?? null,
            'subject' => isset($values['subject'][0]) ? $this->decodeHeader($values['subject'][0]) : null,
            'from' => $addresses($values, 'from'),
            'to' => $addresses($values, 'to'),
            'cc' => $addresses($values, 'cc'),
            'date_header' => $date,
            'content_type' => $contentType,
        ];
    }

    private function decodeHeader(string $value): string
    {
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return $decoded === false ? $value : $decoded;
    }
}
