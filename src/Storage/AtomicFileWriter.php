<?php

declare(strict_types=1);

namespace MailMirror\Storage;

final class AtomicFileWriter
{
    public function write(string $path, string $bytes, int $mode = 0660): void
    {
        $this->ensureDirectory(dirname($path));
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(6));
        $handle = @fopen($tmp, 'xb');
        if ($handle === false) {
            throw new \RuntimeException("Temporäre Datei kann nicht erstellt werden: {$tmp}");
        }

        try {
            $length = strlen($bytes);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($handle, substr($bytes, $offset, 1048576));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException("Datei kann nicht vollständig geschrieben werden: {$tmp}");
                }
                $offset += $written;
            }
            fflush($handle);
            if (function_exists('fsync')) {
                fsync($handle);
            }
        } finally {
            fclose($handle);
        }

        chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Atomarer Dateitausch fehlgeschlagen: {$path}");
        }
    }

    public function ensureDirectory(string $path, int $mode = 0770): void
    {
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException("Verzeichnis kann nicht erstellt werden: {$path}");
        }
        // Existing deployment ACLs must not be reset by every request.
    }
}
