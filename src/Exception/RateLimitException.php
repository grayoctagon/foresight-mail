<?php
declare(strict_types=1);
namespace MailMirror\Exception;

final class RateLimitException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Zu viele Anmelde- oder Registrierungsversuche. Bitte später erneut versuchen.');
    }
}
