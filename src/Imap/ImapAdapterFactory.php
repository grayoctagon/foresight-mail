<?php

declare(strict_types=1);

namespace MailMirror\Imap;

use MailMirror\Config\CredentialStore;

final class ImapAdapterFactory
{
    /** @var null|callable(array,string,string):ImapAdapterInterface */
    private $override;

    public function __construct(private readonly CredentialStore $credentials, ?callable $override = null)
    {
        $this->override = $override;
    }

    public function make(array $accountConfig, string $userId, string $accountId): ImapAdapterInterface
    {
        if ($this->override !== null) {
            return ($this->override)($accountConfig, $userId, $accountId);
        }
        $reference = (string) $accountConfig['imap']['authentication']['credential_ref'];
        $password = $this->credentials->password($userId, $accountId, $reference);
        return new ImapEngineAdapter($accountConfig['imap'], $password);
    }
}
