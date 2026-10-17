<?php
declare(strict_types=1);

namespace MailMirror\Http;

/** URL paths come from the public PHP entry point, never its resolved filesystem path. */
final class PublicPath
{
    public static function detect(array $server): string
    {
        $script = (string) ($server['SCRIPT_NAME'] ?? '');
        if ($script === '' || $script[0] !== '/' || preg_match('/[\x00-\x20\x7f\\\\?#]/', $script)) {
            throw new \InvalidArgumentException('Öffentlicher Einstiegspfad ist ungültig.');
        }
        // Some PHP handlers include PATH_INFO in SCRIPT_NAME, others do not.
        $pathInfo = (string) ($server['PATH_INFO'] ?? '');
        if ($pathInfo !== '' && str_ends_with($script, $pathInfo)) $script = substr($script, 0, -strlen($pathInfo));
        if (preg_match('~^(.*)/(?:index|api)\.php(?:/.*)?$~D', $script, $match)) {
            $base = $match[1];
        } else {
            $base = dirname($script);
        }
        return $base === '/' || $base === '.' ? '' : rtrim($base, '/');
    }

    public static function resolve(string $base, string $relative): string
    {
        if ($relative === '' || preg_match('/[\x00-\x20\x7f\\\\?#:]/', $relative)) {
            throw new \InvalidArgumentException('Weiterleitungsziel ist ungültig.');
        }
        $parts = [];
        foreach (explode('/', ($relative[0] === '/' ? '' : rtrim($base, '/').'/').$relative) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') array_pop($parts); else $parts[] = $part;
        }
        return '/'.implode('/', $parts);
    }
}
