<?php

declare(strict_types=1);

namespace MailMirror\Imap;

use DirectoryTree\ImapEngine\Connection\Streams\ImapStream;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionTimedOutException;

/** Wall-clock I/O budget, including slow/trickling IMAP responses. */
final class DeadlineImapStream extends ImapStream
{
    private int $timeout = 5;

    public function __construct(private readonly float $deadline) {}

    public function open(string $transport, string $host, int $port, int $timeout, array $options = []): bool
    {
        $this->remaining();
        $this->timeout = min(5, $timeout);
        $result = parent::open($transport, $host, $port, min($this->timeout, max(1, (int) ceil($this->remaining()))), $options);
        $this->prepare();
        return $result;
    }

    public function read(int $length): string|false
    {
        if (!$this->opened()) return false;
        $data = '';
        while (strlen($data) < $length && !feof($this->stream)) {
            $this->prepare();
            $chunk = fread($this->stream, min(65536, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                if ($this->meta()['timed_out'] ?? false) throw new ImapConnectionTimedOutException('Sync-Zeitbudget erreicht.');
                return $chunk === false ? false : $data;
            }
            $data .= $chunk;
        }
        return $data;
    }

    public function fgets(): string|false
    {
        if (!$this->opened()) return false;
        $line = '';
        // Native fgets can wait indefinitely when a peer trickles an unterminated line.
        while (!feof($this->stream)) {
            $this->prepare();
            $byte = fread($this->stream, 1);
            if ($byte === false || $byte === '') {
                if ($this->meta()['timed_out'] ?? false) throw new ImapConnectionTimedOutException('Sync-Zeitbudget erreicht.');
                return $line === '' ? false : $line;
            }
            $line .= $byte;
            if ($byte === "\n") return $line;
        }
        return $line === '' ? false : $line;
    }

    public function fwrite(string $data): int|false
    {
        if ($this->opened()) $this->prepare();
        return parent::fwrite($data);
    }

    public function setTimeout(int $seconds): bool
    {
        $this->timeout = min(5, max(1, $seconds));
        return $this->prepare();
    }

    public function setSocketSetCrypto(bool $enabled, ?int $method): bool|int
    {
        $this->prepare();
        return parent::setSocketSetCrypto($enabled, $method);
    }

    private function remaining(): float
    {
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) throw new ImapConnectionTimedOutException('Sync-Zeitbudget erreicht.');
        return $remaining;
    }

    private function prepare(): bool
    {
        $seconds = min($this->timeout, $this->remaining());
        return stream_set_timeout($this->stream, (int) $seconds, (int) (($seconds - (int) $seconds) * 1000000));
    }
}
