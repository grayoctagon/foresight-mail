<?php

declare(strict_types=1);

namespace MailMirror\Imap;

interface ImapAdapterInterface
{
    public function connect(): void;

    public function disconnect(): void;

    /** @return string[] */
    public function capabilities(): array;

    /** @return array<int, array{remote_name:string,mailbox_id:?string,delimiter:string,selectable:bool,attributes:array}> */
    public function discoverMailboxes(): array;

    /** @return array{uidvalidity:int,uidnext:?int,highestmodseq:?int,message_count:int,uid_not_sticky:bool} */
    public function examine(string $remoteName): array;

    /** @return int[] */
    public function allUids(): array;

    /** @param int[] $uids @return array<int, array<string, mixed>> keyed by UID */
    public function fetchMetadata(array $uids, bool $withEmailId): array;

    public function fetchRawMessage(int $uid): string;
}
