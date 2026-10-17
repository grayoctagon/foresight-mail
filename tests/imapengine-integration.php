<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__).'/vendor/autoload.php';

use MailMirror\Imap\ImapEngineAdapter;

if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "pcntl fehlt; Integrationstest übersprungen.\n");
    exit(0);
}

$raw = "From: Mock <mock@example.test>\r\nTo: User <user@example.test>\r\nSubject: Literal test\r\nMessage-ID: <mock-7@example.test>\r\n\r\nExact bytes\r\n";
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    throw new RuntimeException("Mock-Server konnte nicht starten: {$error}");
}
$name = stream_socket_get_name($server, false);
$port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
$pid = pcntl_fork();
if ($pid === -1) {
    throw new RuntimeException('Fork fehlgeschlagen.');
}
if ($pid === 0) {
    $client = @stream_socket_accept($server, 10);
    if ($client === false) exit(3);
    fwrite($client, "* OK Mock IMAP ready\r\n");
    while (($line = fgets($client)) !== false) {
        $line = rtrim($line, "\r\n");
        if (!preg_match('/^(TAG\d+)\s+(.+)$/', $line, $match)) continue;
        $tag = $match[1];
        $command = $match[2];
        if (str_starts_with($command, 'CAPABILITY')) {
            fwrite($client, "* CAPABILITY IMAP4rev1 OBJECTID CONDSTORE AUTH=PLAIN\r\n{$tag} OK CAPABILITY done\r\n");
        } elseif (str_starts_with($command, 'AUTHENTICATE PLAIN')) {
            fwrite($client, "+ continue\r\n");
            $credentials = trim((string) fgets($client));
            $expected = base64_encode("\0user\0password");
            fwrite($client, $credentials === $expected ? "{$tag} OK AUTHENTICATE done\r\n" : "{$tag} NO bad credentials\r\n");
        } elseif (str_starts_with($command, 'LIST ')) {
            fwrite($client, "* LIST () \"/\" \"INBOX\" (MAILBOXID (M-INBOX))\r\n");
            fwrite($client, "* LIST (\\Noselect) \"/\" \"Container\" (MAILBOXID (M-CONTAINER))\r\n{$tag} OK LIST done\r\n");
        } elseif (str_starts_with($command, 'EXAMINE ')) {
            fwrite($client, "* FLAGS (\\Seen \\Answered \\Flagged \\Deleted \\Draft)\r\n");
            fwrite($client, "* 1 EXISTS\r\n* OK [UIDVALIDITY 42] valid\r\n* OK [UIDNEXT 8] next\r\n* OK [HIGHESTMODSEQ 9] modseq\r\n{$tag} OK [READ-ONLY] EXAMINE done\r\n");
        } elseif (str_starts_with($command, 'UID SEARCH')) {
            fwrite($client, "* SEARCH 7\r\n{$tag} OK SEARCH done\r\n");
        } elseif (str_starts_with($command, 'UID FETCH') && str_contains($command, 'BODY.PEEK[]')) {
            fwrite($client, "* 1 FETCH (UID 7 BODY[] {".strlen($raw)."}\r\n");
            fwrite($client, $raw.")\r\n{$tag} OK FETCH done\r\n");
        } elseif (str_starts_with($command, 'UID FETCH')) {
            fwrite($client, "* 1 FETCH (UID 7 FLAGS (\\Seen custom) INTERNALDATE \"08-Sep-2026 01:00:00 +0000\" RFC822.SIZE ".strlen($raw)." EMAILID (E-7) MODSEQ (9))\r\n{$tag} OK FETCH done\r\n");
        } elseif (str_starts_with($command, 'LOGOUT')) {
            fwrite($client, "* BYE closing\r\n{$tag} OK LOGOUT done\r\n");
            break;
        } else {
            fwrite($client, "{$tag} BAD unsupported\r\n");
        }
    }
    fclose($client);
    fclose($server);
    exit(0);
}

fclose($server);
$adapter = new ImapEngineAdapter([
    'hostname' => '127.0.0.1',
    'port' => $port,
    'connection_security' => 'none',
    'authentication' => ['method' => 'plain', 'username' => 'user', 'credential_ref' => 'imap-main'],
    'timeout_seconds' => 5,
    'validate_certificate' => false,
], 'password');

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException("Integrationstest fehlgeschlagen: {$label}");
    ++$checks;
    echo "ok - {$label}\n";
};

try {
    $adapter->connect();
    $check(in_array('OBJECTID', $adapter->capabilities(), true), 'Capability Detection über ImapEngine');
    $mailboxes = $adapter->discoverMailboxes();
    $check(count($mailboxes) === 2 && $mailboxes[0]['mailbox_id'] === 'M-INBOX', 'LIST und MAILBOXID werden geparst');
    $check($mailboxes[1]['selectable'] === false, '\\Noselect wird erkannt');
    $status = $adapter->examine('INBOX');
    $check($status['uidvalidity'] === 42 && $status['highestmodseq'] === 9 && $status['message_count'] === 1, 'EXAMINE liefert UIDVALIDITY, MODSEQ und Count');
    $check($adapter->allUids() === [7], 'UID SEARCH liefert persistierbare UIDs');
    $metadata = $adapter->fetchMetadata([7], true);
    $check($metadata[7]['flags'] === ['\\Seen', 'custom'] && $metadata[7]['email_id'] === 'E-7', 'FLAGS, EMAILID und Metadaten werden gebatcht geparst');
    $check($adapter->fetchRawMessage(7) === $raw, 'BODY.PEEK[] liefert den Raw-Literal bytegetreu');
} finally {
    $adapter->disconnect();
    pcntl_waitpid($pid, $status);
}

echo "{$checks} ImapEngine-Integrationsprüfungen bestanden.\n";
