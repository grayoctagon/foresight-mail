<?php

declare(strict_types=1);

namespace MailMirror\Support;

final class Ids
{
    public static function uuid7(): string
    {
        $milliseconds = (int) floor(microtime(true) * 1000);
        $timeHex = str_pad(dechex($milliseconds), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(10));

        return sprintf(
            '%s-%s-7%s-%s%s-%s',
            substr($timeHex, 0, 8),
            substr($timeHex, 8, 4),
            substr($random, 0, 3),
            dechex((hexdec($random[3]) & 0x3) | 0x8),
            substr($random, 4, 3),
            substr($random, 7, 12),
        );
    }

    public static function folderId(): string
    {
        return 'f_'.str_replace('-', '', self::uuid7());
    }

    public static function assert(string $value, string $name = 'ID'): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $value)) {
            throw new \InvalidArgumentException("Ungültige {$name}.");
        }

        return $value;
    }
}
