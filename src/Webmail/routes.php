<?php

declare(strict_types=1);

// Executed inside the existing authenticated, CSRF-checked API dispatcher.
use MailMirror\Exception\NotFoundException;
use MailMirror\Webmail\HtmlSanitizer;
use MailMirror\Webmail\MimeReader;
use MailMirror\Webmail\LocalMailbox;
use MailMirror\Webmail\SettingsStore;

$mail = new LocalMailbox($app, new MimeReader($app['json'], new HtmlSanitizer()));
$settings = new SettingsStore($app['paths'], $app['json']);
$parts = array_slice($segments, 1);
foreach ($_GET as $key => $value) {
    if (!is_string($value) || strlen($value) > 40000) throw new InvalidArgumentException('Ungültige Anfrageparameter.');
}
if ($parts === ['settings']) {
    if ($method === 'GET') $respond($settings->get($user->userId));
    if ($method === 'PUT') $respond($settings->save($user->userId, $body()));
}
if ($parts === ['capabilities'] && $method === 'GET') {
    $respond(['localRead' => true, 'sync' => true, 'send' => false, 'remoteWrite' => false, 'ai' => false,
        'search' => 'headers_and_snippet', 'bodyPreviewLimitBytes' => MimeReader::BODY_LIMIT]);
}
if ($method !== 'GET') {
    $respond(['error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Diese Ressource ist nur lesbar.']], 405);
}
session_write_close();
if ($parts === ['messages']) $respond($mail->messages($user->userId, $_GET));
if (count($parts) >= 3 && $parts[0] === 'accounts') {
    $account = $parts[1];
    if (count($parts) === 3 && $parts[2] === 'folders') $respond(['folders' => $mail->folders($user->userId, $account)]);
    if (count($parts) >= 4 && $parts[2] === 'messages') {
        $id = $parts[3];
        if (count($parts) === 4) $respond(['message' => $mail->message($user->userId, $account, $id)]);
        if (count($parts) === 5 && $parts[4] === 'body') $respond($mail->body($user->userId, $account, $id, ($_GET['external'] ?? '0') === '1'));
        if (count($parts) === 5 && $parts[4] === 'raw') {
            $path = $mail->rawPath($user->userId, $account, $id);
            header('Content-Type: message/rfc822');
            header('Content-Disposition: attachment; filename="message.eml"');
            header('Content-Length: '.filesize($path));
            header("Content-Security-Policy: sandbox; default-src 'none'");
            readfile($path);
            exit;
        }
        if (count($parts) === 5 && $parts[4] === 'attachments') {
            $respond(['attachments' => $mail->message($user->userId, $account, $id)['attachments']]);
        }
        if (count($parts) === 6 && $parts[4] === 'attachments') {
            $attachment = $mail->attachment($user->userId, $account, $id, $parts[5]);
            header('Content-Type: application/octet-stream');
            header("Content-Disposition: attachment; filename=\"attachment\"; filename*=UTF-8''".rawurlencode($attachment['name']));
            header("Content-Security-Policy: sandbox; default-src 'none'");
            if ($attachment['stream']) {
                while (!$attachment['stream']->eof()) echo $attachment['stream']->read(65536);
            }
            exit;
        }
    }
}
throw new NotFoundException('Webmail-Ressource nicht gefunden.');
