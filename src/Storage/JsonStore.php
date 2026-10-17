<?php

declare(strict_types=1);

namespace MailMirror\Storage;

use MailMirror\Exception\CorruptStorageException;

final class JsonStore
{
    public function __construct(private readonly AtomicFileWriter $writer)
    {
    }

    public function read(string $path, ?array $default = null): array
    {
        if (!is_file($path)) {
            if ($default !== null) {
                return $default;
            }
            throw new \RuntimeException("JSON-Datei fehlt: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("JSON-Datei kann nicht gelesen werden: {$path}");
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CorruptStorageException("Beschädigte JSON-Datei {$path}: {$e->getMessage()}", 0, $e);
        }

        if (!is_array($data)) {
            throw new CorruptStorageException("JSON-Wurzel ist kein Objekt/Array: {$path}");
        }

        return $data;
    }

    public function write(string $path, array $data, int $mode = 0660): void
    {
        $json = json_encode(
            $this->sortRecursive($data),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
        $this->writer->write($path, $json, $mode);
    }

    private function sortRecursive(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }
        return $value;
    }
}
