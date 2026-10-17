<?php

declare(strict_types=1);

namespace MailMirror\Config;

use MailMirror\Exception\ConfigurationException;
use MailMirror\Exception\CorruptStorageException;
use MailMirror\Storage\AtomicFileWriter;
use MailMirror\Storage\JsonStore;
use MailMirror\Storage\PathResolver;

final class CredentialStore
{
    public function __construct(
        private readonly PathResolver $paths,
        private readonly JsonStore $json,
        private readonly AtomicFileWriter $writer,
    ) {
    }

    public function assertStorageWritable(): void
    {
        $root = $this->paths->secretStoreRoot();
        $this->writer->ensureDirectory($root, 0770);
        if (!is_writable($root)) {
            throw new ConfigurationException('Der geschützte Credential-Speicher ist für PHP nicht beschreibbar. Bitte die Serverrechte prüfen.');
        }
    }

    public function savePassword(string $userId, string $accountId, string $reference, string $password): void
    {
        if ($password === '') {
            throw new \InvalidArgumentException('Ein leeres Passwort kann nicht gespeichert werden.');
        }
        $path = $this->paths->secret($userId, $accountId, $reference);
        // The two explicitly ACL-authorized runtime users (Apache and CLI owner)
        // need traversal. Group/other still receive no access through the ACL.
        $this->writer->ensureDirectory(dirname($path), 0770);
        $this->json->write($path, [
            'schema_version' => 1,
            'type' => 'password',
            'password' => $password,
        ], 0640);
    }

    public function password(string $userId, string $accountId, string $reference): string
    {
        $path = $this->paths->secret($userId, $accountId, $reference);
        if (!is_file($path)) {
            throw new ConfigurationException('Für diesen Account ist kein IMAP-Passwort gespeichert. Bitte unter „Verbindungen“ ein Passwort eingeben und die Einstellungen speichern.');
        }
        try {
            $secret = $this->json->read($path);
        } catch (CorruptStorageException $e) {
            throw new ConfigurationException('Das gespeicherte Credential ist beschädigt. Bitte das Passwort unter „Verbindungen“ erneut speichern.', 0, $e);
        }
        if (($secret['schema_version'] ?? null) !== 1 || ($secret['type'] ?? null) !== 'password' || !is_string($secret['password'] ?? null)) {
            throw new ConfigurationException('Das gespeicherte Credential hat ein ungültiges Format. Bitte das Passwort unter „Verbindungen“ erneut speichern.');
        }
        return $secret['password'];
    }

    public function exists(string $userId, string $accountId, string $reference): bool
    {
        return is_file($this->paths->secret($userId, $accountId, $reference));
    }
}
