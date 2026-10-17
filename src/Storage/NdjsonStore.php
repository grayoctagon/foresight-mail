<?php

declare(strict_types=1);

namespace MailMirror\Storage;

use MailMirror\Exception\CorruptStorageException;

final class NdjsonStore
{
    public function __construct(private readonly AtomicFileWriter $writer)
    {
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function iterate(string $path): \Generator
    {
        if (!is_file($path)) {
            return;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("NDJSON-Datei kann nicht gelesen werden: {$path}");
        }
        $lineNumber = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                ++$lineNumber;
                if (trim($line) === '') {
                    continue;
                }
                try {
                    $row = json_decode($line, true, 128, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new CorruptStorageException("Beschädigtes NDJSON {$path}, Zeile {$lineNumber}: {$e->getMessage()}", 0, $e);
                }
                if (!is_array($row)) {
                    throw new CorruptStorageException("Ungültige NDJSON-Zeile {$lineNumber} in {$path}");
                }
                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function readAll(string $path): array
    {
        return iterator_to_array($this->iterate($path), false);
    }

    /** @param iterable<array<string, mixed>> $rows */
    public function write(string $path, iterable $rows): void
    {
        $this->writer->ensureDirectory(dirname($path));
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(6));
        $handle = fopen($tmp, 'xb');
        if ($handle === false) {
            throw new \RuntimeException("Temporäre NDJSON-Datei kann nicht erstellt werden: {$tmp}");
        }
        try {
            foreach ($rows as $row) {
                $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
                $offset = 0;
                while ($offset < strlen($line)) {
                    $written = fwrite($handle, substr($line, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException("NDJSON-Datei kann nicht vollständig geschrieben werden: {$tmp}");
                    }
                    $offset += $written;
                }
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
        chmod($tmp, 0660);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Atomarer NDJSON-Dateitausch fehlgeschlagen: {$path}");
        }
    }
}
