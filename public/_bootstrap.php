<?php
declare(strict_types=1);

// This is the only line to change if public/ and the private project are placed separately.
$projectRoot = __DIR__ . '/..';

$projectRoot = realpath($projectRoot);
if ($projectRoot === false || !is_file($projectRoot.'/bootstrap.php')) {
    http_response_code(503);
    exit('foresight: Anwendungspfad ist nicht konfiguriert.');
}
$app = require $projectRoot.'/bootstrap.php';
$app['publicRoot'] = __DIR__;
return $app;
