<?php

declare(strict_types=1);

namespace MailMirror\Imap;

use DirectoryTree\ImapEngine\Connection\Responses\Data\Data;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Streams\ImapStream;
use DirectoryTree\ImapEngine\Connection\Tokens\Literal;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
use DirectoryTree\ImapEngine\Support\Str;
use MailMirror\Exception\RemoteMessageGoneException;

final class ImapEngineAdapter implements ImapAdapterInterface
{
    private ?ExtendedImapConnection $connection = null;
    /** @var string[] */
    private array $capabilities = [];
    private ?string $selectedMailbox = null;

    public function __construct(private readonly array $config, private readonly string $password)
    {
    }

    public function connect(): void
    {
        if ($this->connection?->connected()) {
            return;
        }
        $stream = isset($this->config['_request_deadline'])
            ? new DeadlineImapStream((float) $this->config['_request_deadline']) : new ImapStream();
        $this->connection = new ExtendedImapConnection($stream);
        $encryption = match ($this->config['connection_security']) {
            'implicit_tls' => 'ssl',
            'starttls' => 'starttls',
            'none' => 'tcp',
            default => throw new \InvalidArgumentException('Unbekannte IMAP-Verbindungssicherheit.'),
        };
        $this->connection->connect(
            (string) $this->config['hostname'],
            (int) $this->config['port'],
            [
                'timeout' => (int) $this->config['timeout_seconds'],
                'encryption' => $encryption,
                'validate_cert' => (bool) $this->config['validate_certificate'],
            ],
        );
        $preAuthCapabilities = $this->readCapabilities();
        $method = (string) $this->config['authentication']['method'];
        if ($method === 'auto') {
            $method = in_array('LOGINDISABLED', $preAuthCapabilities, true) && in_array('AUTH=PLAIN', $preAuthCapabilities, true)
                ? 'plain'
                : 'login';
        }
        $username = (string) $this->config['authentication']['username'];
        if ($method === 'plain') {
            if (!in_array('AUTH=PLAIN', $preAuthCapabilities, true)) {
                throw new \RuntimeException('Der Server bietet AUTH=PLAIN nicht an.');
            }
            $this->connection->plainLogin($username, $this->password);
        } else {
            $this->connection->login($username, $this->password);
        }
        $this->capabilities = $this->readCapabilities();
    }

    public function disconnect(): void
    {
        if ($this->connection !== null) {
            try {
                $this->connection->logout();
            } catch (\Throwable) {
            }
            $this->connection->disconnect();
        }
        $this->connection = null;
        $this->selectedMailbox = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    public function capabilities(): array
    {
        $this->assertConnected();
        return $this->capabilities;
    }

    public function discoverMailboxes(): array
    {
        $this->assertConnected();
        $mailboxes = [];
        foreach ($this->connection->list('', '*') as $response) {
            $attributes = $this->dataValues($response->tokenAt(2));
            $delimiter = $response->tokenAt(3)?->value ?? '/';
            $encodedName = $response->tokenAt(4)?->value;
            if (!is_string($encodedName) || $encodedName === '') {
                continue;
            }
            $mailboxIdValue = $this->findField($response, 'MAILBOXID');
            $mailboxes[] = [
                'remote_name' => Str::fromImapUtf7($encodedName),
                'mailbox_id' => $this->scalarValue($mailboxIdValue),
                'delimiter' => $delimiter,
                'selectable' => !$this->containsCaseInsensitive($attributes, '\\Noselect'),
                'attributes' => $attributes,
            ];
        }
        return $mailboxes;
    }

    public function examine(string $remoteName): array
    {
        $this->assertConnected();
        $responses = $this->connection->examine(Str::toImapUtf7($remoteName));
        $this->selectedMailbox = $remoteName;
        $result = [
            'uidvalidity' => 0,
            'uidnext' => null,
            'highestmodseq' => null,
            'message_count' => 0,
            'uid_not_sticky' => false,
        ];
        foreach ($responses as $response) {
            if (!$response instanceof UntaggedResponse) {
                continue;
            }
            $countToken = $response->tokenAt(1);
            $typeToken = $response->tokenAt(2);
            if ($countToken instanceof Token && $typeToken instanceof Token && strcasecmp($typeToken->value, 'EXISTS') === 0) {
                $result['message_count'] = (int) $countToken->value;
            }
            foreach ($this->responseCodes($response) as $code) {
                $name = strtoupper((string) ($code->tokenAt(0)?->value ?? ''));
                $value = $code->tokenAt(1)?->value ?? null;
                if ($name === 'UIDVALIDITY') {
                    $result['uidvalidity'] = (int) $value;
                } elseif ($name === 'UIDNEXT') {
                    $result['uidnext'] = (int) $value;
                } elseif ($name === 'HIGHESTMODSEQ') {
                    $result['highestmodseq'] = (int) $value;
                } elseif ($name === 'UIDNOTSTICKY') {
                    $result['uid_not_sticky'] = true;
                }
            }
        }
        if ($result['uidvalidity'] < 1 && !$result['uid_not_sticky']) {
            throw new \RuntimeException("Mailbox {$remoteName} lieferte keine gültige UIDVALIDITY.");
        }
        return $result;
    }

    public function allUids(): array
    {
        $this->assertSelected();
        $response = $this->connection->search(['ALL']);
        $uids = [];
        foreach ($response->tokensAfter(2) as $token) {
            if ($token instanceof Token && ctype_digit($token->value)) {
                $uids[] = (int) $token->value;
            }
        }
        sort($uids, SORT_NUMERIC);
        return array_values(array_unique($uids));
    }

    public function fetchMetadata(array $uids, bool $withEmailId): array
    {
        $this->assertSelected();
        if ($uids === []) {
            return [];
        }
        $items = ['UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE'];
        if ($withEmailId && in_array('OBJECTID', $this->capabilities, true)) {
            $items[] = 'EMAILID';
        }
        if (in_array('CONDSTORE', $this->capabilities, true) || in_array('QRESYNC', $this->capabilities, true)) {
            $items[] = 'MODSEQ';
        }
        $result = [];
        foreach ($this->connection->fetch($items, $uids) as $response) {
            $data = $response->tokenAt(3);
            if (!$data instanceof ListData) {
                continue;
            }
            $uid = (int) ($data->lookup('UID')?->value ?? 0);
            if ($uid < 1) {
                continue;
            }
            $flags = $this->dataValues($data->lookup('FLAGS'));
            $internalDate = $data->lookup('INTERNALDATE')?->value;
            $result[$uid] = [
                'uid' => $uid,
                'flags' => $flags,
                'internal_date' => $this->normalizeDate($internalDate),
                'size' => (int) ($data->lookup('RFC822.SIZE')?->value ?? 0),
                'email_id' => $this->scalarValue($data->lookup('EMAILID')),
                'modseq' => $this->integerValue($data->lookup('MODSEQ')),
            ];
        }
        return $result;
    }

    public function fetchRawMessage(int $uid): string
    {
        $this->assertSelected();
        $responses = $this->connection->fetch(['UID', 'BODY.PEEK[]'], $uid);
        if ($responses->isEmpty()) {
            throw new RemoteMessageGoneException("UID {$uid} ist nicht mehr vorhanden.");
        }
        foreach ($responses as $response) {
            $literal = $this->findLiteral($response);
            if ($literal !== null) {
                return $literal;
            }
        }
        throw new RemoteMessageGoneException("UID {$uid} lieferte keinen vollständigen Message-Literal.");
    }

    private function readCapabilities(): array
    {
        $response = $this->connection->capability();
        $values = [];
        foreach ($response->tokensAfter(2) as $token) {
            if ($token instanceof Token) {
                $values[] = strtoupper($token->value);
            }
        }
        return array_values(array_unique($values));
    }

    /** @return ResponseCodeData[] */
    private function responseCodes(Data|UntaggedResponse $data): array
    {
        $codes = [];
        foreach ($data->tokens() as $token) {
            if ($token instanceof ResponseCodeData) {
                $codes[] = $token;
            } elseif ($token instanceof Data) {
                array_push($codes, ...$this->responseCodes($token));
            }
        }
        return $codes;
    }

    private function findField(Data|UntaggedResponse $data, string $field): Data|Token|null
    {
        $tokens = $data->tokens();
        foreach ($tokens as $index => $token) {
            if ($token instanceof Token && strcasecmp($token->value, $field) === 0) {
                return $tokens[$index + 1] ?? null;
            }
            if ($token instanceof Data) {
                $found = $this->findField($token, $field);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function findLiteral(Data|UntaggedResponse $data): ?string
    {
        foreach ($data->tokens() as $token) {
            if ($token instanceof Literal) {
                return $token->value;
            }
            if ($token instanceof Data) {
                $found = $this->findLiteral($token);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function scalarValue(Data|Token|null $value): ?string
    {
        if ($value instanceof Token) {
            return strcasecmp($value->value, 'NIL') === 0 ? null : trim($value->value, '()');
        }
        if ($value instanceof Data) {
            $first = $value->first();
            return $first instanceof Token ? trim($first->value, '()') : null;
        }
        return null;
    }

    private function integerValue(Data|Token|null $value): ?int
    {
        $scalar = $this->scalarValue($value);
        return $scalar !== null && ctype_digit($scalar) ? (int) $scalar : null;
    }

    /** @return string[] */
    private function dataValues(Data|Token|null $value): array
    {
        if ($value instanceof Data) {
            return array_values(array_filter(array_map(static function (mixed $item): ?string {
                if (is_string($item) || is_numeric($item)) {
                    return (string) $item;
                }
                return null;
            }, $value->values())));
        }
        return $value instanceof Token ? [$value->value] : [];
    }

    private function normalizeDate(?string $date): ?string
    {
        if ($date === null || strcasecmp($date, 'NIL') === 0) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($date))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return $date;
        }
    }

    private function containsCaseInsensitive(array $values, string $needle): bool
    {
        foreach ($values as $value) {
            if (strcasecmp((string) $value, $needle) === 0) {
                return true;
            }
        }
        return false;
    }

    private function assertConnected(): void
    {
        if (!$this->connection?->connected()) {
            throw new \LogicException('IMAP-Adapter ist nicht verbunden.');
        }
    }

    private function assertSelected(): void
    {
        $this->assertConnected();
        if ($this->selectedMailbox === null) {
            throw new \LogicException('Keine Mailbox per EXAMINE ausgewählt.');
        }
    }
}
