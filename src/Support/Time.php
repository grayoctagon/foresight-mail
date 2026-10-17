<?php

declare(strict_types=1);

namespace MailMirror\Support;

final class Time
{
    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public static function secondsSince(string $timestamp): int
    {
        $start = strtotime($timestamp);
        return $start === false ? 0 : max(0, time() - $start);
    }
}
