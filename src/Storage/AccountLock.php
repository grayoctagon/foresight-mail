<?php

declare(strict_types=1);

namespace MailMirror\Storage;

final class AccountLock
{
    /** @var resource|null */
    private mixed $handle = null;

    public function acquire(string $lockPath, bool $blocking = false): bool
    {
        (new AtomicFileWriter())->ensureDirectory(dirname($lockPath));
        $this->handle = fopen($lockPath, 'c+');
        if ($this->handle === false) {
            throw new \RuntimeException("Lock-Datei kann nicht geöffnet werden: {$lockPath}");
        }
        $operation = LOCK_EX | ($blocking ? 0 : LOCK_NB);
        if (!flock($this->handle, $operation)) {
            fclose($this->handle);
            $this->handle = null;
            return false;
        }
        ftruncate($this->handle, 0);
        fwrite($this->handle, json_encode(['pid' => getmypid(), 'acquired_at' => gmdate('c')])."\n");
        fflush($this->handle);
        return true;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
