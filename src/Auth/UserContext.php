<?php

declare(strict_types=1);

namespace MailMirror\Auth;

final class UserContext
{
    public function __construct(
        public readonly string $userId,
        public readonly string $username,
    ) {
    }
}
