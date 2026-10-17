<?php

declare(strict_types=1);

namespace MailMirror\Imap;

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;

final class ExtendedImapConnection extends ImapConnection
{
    public function connect(string $host, ?int $port = null, array $options = []): void
    {
        parent::connect($host, $port, $options);
        $this->stream->setTimeout((int) ($options['timeout'] ?? 30));
    }

    public function plainLogin(string $username, string $password): TaggedResponse
    {
        $this->send('AUTHENTICATE', ['PLAIN'], $tag);
        $this->assertContinuationResponse($this->result->command());
        $this->write(base64_encode("\0{$username}\0{$password}"));
        return $this->assertTaggedResponse($tag);
    }

    protected function getDefaultSocketOptions(string $transport, array $proxy = [], bool $validateCert = true): array
    {
        $options = parent::getDefaultSocketOptions($transport, $proxy, $validateCert);
        if ($transport === 'starttls') {
            $options['ssl'] = array_replace($options['ssl'] ?? [], [
                'verify_peer' => $validateCert,
                'verify_peer_name' => $validateCert,
                'allow_self_signed' => !$validateCert,
            ]);
        }
        return $options;
    }
}
