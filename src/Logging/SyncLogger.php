<?php

declare(strict_types=1);

namespace MailMirror\Logging;

use MailMirror\Storage\AtomicFileWriter;
use MailMirror\Storage\PathResolver;
use MailMirror\Support\Time;

final class SyncLogger
{
    public function __construct(private readonly PathResolver $paths, private readonly AtomicFileWriter $writer)
    {
    }

    public function log(string $userId, string $accountId, string $level, string $event, array $context = []): void
    {
        unset($context['password'], $context['raw'], $context['body'], $context['subject'], $context['headers']);
        $dir = $this->paths->account($userId, $accountId).'/logs';
        $this->writer->ensureDirectory($dir);
        $path = $dir.'/sync-'.gmdate('Y-m').'.ndjson';
        $handle = fopen($path, 'ab');
        if ($handle === false) {
            return;
        }
        if (flock($handle, LOCK_EX)) {
            fwrite($handle, json_encode([
                'schema_version' => 1,
                'timestamp' => Time::now(),
                'level' => $level,
                'event' => $event,
                'account_id' => $accountId,
                'context' => $context,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}
