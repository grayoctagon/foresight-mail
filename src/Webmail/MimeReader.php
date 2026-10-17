<?php

declare(strict_types=1);

namespace MailMirror\Webmail;

use MailMirror\Storage\JsonStore;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Header\AddressHeader;

final class MimeReader
{
    public const BODY_LIMIT = 2097152;
    public function __construct(private readonly JsonStore $json, private readonly HtmlSanitizer $sanitizer) {}

    public function parse(string $path): IMessage
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw new \RuntimeException('Lokale Nachricht kann nicht geöffnet werden.');
        return (new MailMimeParser())->parse($handle, true);
    }

    public function summary(string $path): array
    {
        $cache = substr($path, 0, -4).'.webmail.json';
        if (is_file($cache)) {
            $summary = $this->json->read($cache);
            if (($summary['version'] ?? null) === 1) return $summary;
        }
        $message = $this->parse($path);
        [$text] = $this->content($message->getTextPart());
        if ($text === '') {
            [$html] = $this->content($message->getHtmlPart());
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $attachments = $this->attachments($message);
        $date = null;
        try { $rawDate = $message->getHeaderValue('date'); if ($rawDate) $date = (new \DateTimeImmutable($rawDate))->format(DATE_ATOM); } catch (\Throwable) {}
        $priority = $message->getHeaderValue('x-priority') ?? $message->getHeaderValue('importance') ?? '';
        $summary = [
            'version' => 1,
            'subject' => $message->getHeaderValue('subject') ?: '(Kein Betreff)',
            'from' => $this->addresses($message, 'from')[0] ?? ['name' => '', 'email' => ''],
            'to' => $this->addresses($message, 'to'),
            'cc' => $this->addresses($message, 'cc'),
            'replyTo' => $this->addresses($message, 'reply-to'),
            'date' => $date,
            'messageId' => $message->getHeaderValue('message-id'),
            'snippet' => mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, 180),
            'hasAttachments' => count($attachments) > 0,
            'attachmentCount' => count($attachments),
            'hasHtml' => $message->getHtmlPartCount() > 0,
            'priority' => preg_match('/^[12]|high/i', $priority) ? 'high' : (preg_match('/^[45]|low/i', $priority) ? 'low' : 'normal'),
            'spamScore' => $this->spamScore($message),
            'bytes' => filesize($path),
        ];
        $this->json->write($cache, $summary);
        return $summary;
    }

    private function spamScore(IMessage $message): ?float
    {
        $score = $message->getHeaderValue('x-spam-score');
        if (is_numeric($score)) return (float) $score;
        // Accept provider-specific X-...-Spam-Score headers without coupling the client to a provider.
        foreach ($message->getAllHeaders() as $header) {
            if (preg_match('/^x-(?:[a-z0-9]+-)+spam-score$/iD', $header->getName())) {
                $score = trim($header->getRawValue());
                if (is_numeric($score)) return (float) $score;
            }
        }
        return null;
    }

    public function details(string $path): array
    {
        $message = $this->parse($path);
        $headers = [];
        foreach ($message->getAllHeaders() as $header) {
            $headers[] = ['name' => $header->getName(), 'value' => mb_substr($header->getRawValue(), 0, 16000)];
            if (count($headers) >= 200) break;
        }
        return ['attachments' => $this->attachments($message), 'headers' => $headers];
    }

    public function body(string $path, bool $external = false): array
    {
        $message = $this->parse($path);
        [$text, $textTruncated] = $this->content($message->getTextPart());
        [$html, $htmlTruncated] = $this->content($message->getHtmlPart());
        $inline = [];
        $budget = self::BODY_LIMIT;
        foreach ($message->getAllAttachmentParts() as $part) {
            $cid = $part->getContentId();
            if (!$cid || !in_array(strtolower($part->getContentType()), ['image/png','image/jpeg','image/gif','image/webp'], true)) continue;
            $stream = $part->getBinaryContentStream();
            if (!$stream) continue;
            $bytes = $stream->read(min($budget, 524288) + 1);
            if (strlen($bytes) > min($budget, 524288)) continue;
            $info = @getimagesizefromstring($bytes);
            if (!$info || !in_array($info['mime'] ?? '', ['image/png','image/jpeg','image/gif','image/webp'], true)) continue;
            $budget -= strlen($bytes);
            $inline[trim($cid, '<>')] = 'data:'.$info['mime'].';base64,'.base64_encode($bytes);
            if ($budget <= 0) break;
        }
        return [
            'text' => $text,
            'html' => $html === '' ? null : $this->sanitizer->sanitize($html, $inline, $external),
            'externalAllowed' => $external,
            'truncated' => $textTruncated || $htmlTruncated,
            'limitBytes' => self::BODY_LIMIT,
        ];
    }

    public function attachment(string $path, string $id): array
    {
        if (!preg_match('/^a(0|[1-9][0-9]{0,5})$/D', $id, $match)) throw new \InvalidArgumentException('Ungültige Anhang-ID.');
        $message = $this->parse($path);
        $part = $message->getAllAttachmentParts()[(int) $match[1]] ?? null;
        if (!$part) throw new \MailMirror\Exception\NotFoundException('Anhang nicht gefunden.');
        return ['name' => $this->filename($part->getFilename() ?: 'Anhang'), 'stream' => $part->getBinaryContentStream(), 'message' => $message];
    }

    private function attachments(IMessage $message): array
    {
        $result = [];
        foreach ($message->getAllAttachmentParts() as $i => $part) {
            $result[] = ['id' => 'a'.$i, 'name' => $this->filename($part->getFilename() ?: ($part->getContentId() ?: 'Anhang')),
                'mime' => $part->getContentType(), 'inline' => $part->getContentDisposition() === 'inline', 'contentId' => $part->getContentId()];
        }
        return $result;
    }

    private function addresses(IMessage $message, string $name): array
    {
        $header = $message->getHeader($name);
        if (!$header instanceof AddressHeader) return [];
        return array_map(static fn ($a) => ['name' => $a->getName() ?? '', 'email' => $a->getEmail()], $header->getAddresses());
    }

    private function content(?\ZBateson\MailMimeParser\Message\IMessagePart $part): array
    {
        if (!$part) return ['', false];
        $stream = $part->getContentStream();
        if (!$stream) return ['', false];
        $text = $stream->read(self::BODY_LIMIT + 1);
        $truncated = strlen($text) > self::BODY_LIMIT;
        return [mb_convert_encoding(substr($text, 0, self::BODY_LIMIT), 'UTF-8', 'UTF-8'), $truncated];
    }

    private function filename(string $name): string
    {
        $name = preg_replace('/[\\\\\/\x00-\x1f\x7f]/u', '_', $name) ?? 'Anhang';
        return mb_substr(trim($name, " .\t"), 0, 180) ?: 'Anhang';
    }
}
