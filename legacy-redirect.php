<?php
declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$base = \MailMirror\Http\PublicPath::resolve(\MailMirror\Http\PublicPath::detect($_SERVER), '../foresight');
$entry = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
$destination = $base.($entry === 'api.php' ? '/api.php'.($_SERVER['PATH_INFO'] ?? '') : '/');
$query = $_SERVER['QUERY_STRING'] ?? '';
if ($query !== '') $destination .= '?'.$query;
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Location: '.$destination, true, 308);
exit;
