<?php

declare(strict_types=1);

namespace MailMirror\Tests;

use MailMirror\Exception\RemoteMessageGoneException;
use MailMirror\Imap\ImapAdapterInterface;

final class FakeImapState
{
    /** @var array<string, array<string, mixed>> */
    public array $mailboxes = [];
    public int $rawFetches = 0;
    public int $metadataFetches = 0;
    public bool $failNextRaw = false;
    public array $capabilities = ['IMAP4REV1', 'OBJECTID', 'CONDSTORE'];
}

final class FakeImapAdapter implements ImapAdapterInterface
{
    private bool $connected = false;
    private ?string $selected = null;

    public function __construct(private readonly FakeImapState $state)
    {
    }

    public function connect(): void
    {
        $this->connected = true;
    }

    public function disconnect(): void
    {
        $this->connected = false;
        $this->selected = null;
    }

    public function capabilities(): array
    {
        return $this->state->capabilities;
    }

    public function discoverMailboxes(): array
    {
        $result = [];
        foreach ($this->state->mailboxes as $name => $mailbox) {
            $attributes = $mailbox['attributes'] ?? [];
            $result[] = [
                'remote_name' => $name,
                'mailbox_id' => $mailbox['mailbox_id'] ?? null,
                'delimiter' => $mailbox['delimiter'] ?? '/',
                'selectable' => !in_array('\\Noselect', $attributes, true),
                'attributes' => $attributes,
            ];
        }
        return $result;
    }

    public function examine(string $remoteName): array
    {
        if (!isset($this->state->mailboxes[$remoteName])) {
            throw new \RuntimeException("Mailbox {$remoteName} fehlt.");
        }
        $this->selected = $remoteName;
        $mailbox = $this->state->mailboxes[$remoteName];
        $uids = array_keys($mailbox['messages'] ?? []);
        return [
            'uidvalidity' => (int) ($mailbox['uidvalidity'] ?? 1),
            'uidnext' => $uids === [] ? 1 : max($uids) + 1,
            'highestmodseq' => (int) ($mailbox['highestmodseq'] ?? 1),
            'message_count' => count($uids),
            'uid_not_sticky' => (bool) ($mailbox['uid_not_sticky'] ?? false),
        ];
    }

    public function allUids(): array
    {
        $messages = $this->selectedMailbox()['messages'] ?? [];
        $uids = array_map('intval', array_keys($messages));
        sort($uids, SORT_NUMERIC);
        return $uids;
    }

    public function fetchMetadata(array $uids, bool $withEmailId): array
    {
        ++$this->state->metadataFetches;
        $result = [];
        $messages = $this->selectedMailbox()['messages'] ?? [];
        foreach ($uids as $uid) {
            if (!isset($messages[$uid])) {
                continue;
            }
            $message = $messages[$uid];
            $result[$uid] = [
                'uid' => $uid,
                'flags' => $message['flags'] ?? [],
                'internal_date' => $message['internal_date'] ?? '2026-09-08T00:00:00Z',
                'size' => strlen($message['raw']),
                'email_id' => $withEmailId && in_array('OBJECTID', $this->state->capabilities, true) ? ($message['email_id'] ?? null) : null,
                'modseq' => $message['modseq'] ?? 1,
            ];
        }
        return $result;
    }

    public function fetchRawMessage(int $uid): string
    {
        if ($this->state->failNextRaw) {
            $this->state->failNextRaw = false;
            throw new \RuntimeException('Simulierter temporärer Netzwerkabbruch');
        }
        $messages = $this->selectedMailbox()['messages'] ?? [];
        if (!isset($messages[$uid])) {
            throw new RemoteMessageGoneException("UID {$uid} verschwunden");
        }
        ++$this->state->rawFetches;
        return $messages[$uid]['raw'];
    }

    private function selectedMailbox(): array
    {
        if (!$this->connected || $this->selected === null) {
            throw new \LogicException('Keine Mailbox ausgewählt.');
        }
        return $this->state->mailboxes[$this->selected];
    }
}
