<?php

declare(strict_types=1);

namespace MailMirror\Storage;

use MailMirror\Support\Ids;

final class PathResolver
{
    public function __construct(
        private readonly string $mailstoreRoot,
        private readonly string $secretStoreRoot,
    ) {
    }

    public function mailstoreRoot(): string
    {
        return $this->mailstoreRoot;
    }

    public function secretStoreRoot(): string
    {
        return $this->secretStoreRoot;
    }

    public function user(string $userId): string
    {
        return $this->mailstoreRoot.'/users/'.Ids::assert($userId, 'User-ID');
    }

    public function account(string $userId, string $accountId): string
    {
        return $this->user($userId).'/accounts/'.Ids::assert($accountId, 'Account-ID');
    }

    public function folder(string $userId, string $accountId, string $folderId): string
    {
        return $this->account($userId, $accountId).'/folders/'.Ids::assert($folderId, 'Folder-ID');
    }

    public function secret(string $userId, string $accountId, string $credentialRef): string
    {
        return $this->secretStoreRoot.'/'.Ids::assert($userId, 'User-ID').'/'.Ids::assert($accountId, 'Account-ID').'/'.Ids::assert($credentialRef, 'Credential-Ref').'.json';
    }
}
