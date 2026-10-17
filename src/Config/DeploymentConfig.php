<?php

declare(strict_types=1);

namespace MailMirror\Config;

use MailMirror\Exception\ConfigurationException;

final class DeploymentConfig
{
    private readonly array $data;

    public function __construct(array $data, private readonly string $projectRoot)
    {
        if (($data['schema_version'] ?? null) !== 1) {
            throw new ConfigurationException('deployment.json benötigt schema_version 1.');
        }
        foreach (['mailstore_root', 'secret_store_root'] as $key) {
            $path = $data['paths'][$key] ?? null;
            if (!is_string($path) || $path === '') {
                throw new ConfigurationException("paths.{$key} muss ein Dateisystempfad sein.");
            }
            $path = self::resolvePath($path, $projectRoot);
            if ($this->insideWebRoot($path)) {
                throw new ConfigurationException("paths.{$key} darf nicht innerhalb des Projekt-Webroots liegen.");
            }
            $data['paths'][$key] = $path;
        }
        $this->data = $data;
    }

    public static function load(string $projectRoot): self
    {
        $override = getenv('FORESIGHT_DEPLOYMENT_FILE') ?: getenv('MAILMIRROR_DEPLOYMENT_FILE');
        if (is_string($override) && $override !== '') {
            $override = self::resolvePath($override, $projectRoot);
            $data = json_decode((string) file_get_contents($override), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new ConfigurationException('Ungültige Deployment-Konfiguration.');
            return new self($data, $projectRoot);
        }
        $phpPath = $projectRoot.'/config/deployment.php';
        if (is_file($phpPath)) {
            $data = require $phpPath;
            if (!is_array($data)) {
                throw new ConfigurationException('deployment.php muss ein Konfigurationsarray zurückgeben.');
            }
            return new self($data, $projectRoot);
        }
        $path = $projectRoot.'/config/deployment.json';
        if (!is_file($path)) {
            throw new ConfigurationException("Deployment-Konfiguration fehlt: {$phpPath} oder {$path}");
        }
        $raw = file_get_contents($path);
        try {
            $data = json_decode((string) $raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigurationException('deployment.json ist ungültig: '.$e->getMessage(), 0, $e);
        }
        if (!is_array($data)) {
            throw new ConfigurationException('deployment.json enthält kein JSON-Objekt.');
        }
        return new self($data, $projectRoot);
    }

    public function mailstoreRoot(): string
    {
        return rtrim($this->data['paths']['mailstore_root'], '/');
    }

    public function secretStoreRoot(): string
    {
        return rtrim($this->data['paths']['secret_store_root'], '/');
    }

    public function basePath(): string
    {
        return PHP_SAPI === 'cli' ? '' : \MailMirror\Http\PublicPath::detect($_SERVER);
    }

    public function sessionName(): string
    {
        return (string) ($this->data['app']['session_name'] ?? 'foresight_session');
    }

    public function registrationAllowed(): bool
    {
        return (bool) ($this->data['app']['allow_registration'] ?? true);
    }

    private function insideWebRoot(string $path): bool
    {
        $project = realpath($this->projectRoot) ?: $this->projectRoot;
        $parent = realpath(dirname($path));
        $candidate = ($parent ?: dirname($path)).'/'.basename($path);
        $candidate = realpath($path) ?: $candidate;
        if ($candidate === $project || str_starts_with($candidate, $project.'/')) return true;
        if (PHP_SAPI !== 'cli' && !empty($_SERVER['DOCUMENT_ROOT'])) {
            $documentRoot = realpath($_SERVER['DOCUMENT_ROOT']) ?: rtrim($_SERVER['DOCUMENT_ROOT'], '/');
            if ($candidate === $documentRoot || str_starts_with($candidate, $documentRoot.'/')) return true;
        }
        return false;
    }

    private static function resolvePath(string $path, string $projectRoot): string
    {
        if (str_contains($path, '://') || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new ConfigurationException('Ungültiger Dateisystempfad.');
        }
        if ($path[0] !== '/') $path = $projectRoot.'/'.$path;
        $resolved = '/';
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') { $resolved = dirname($resolved); continue; }
            $candidate = rtrim($resolved, '/').'/'.$part;
            if (is_link($candidate) && realpath($candidate) === false) throw new ConfigurationException('Dateisystempfad enthält einen ungültigen Symlink.');
            $resolved = realpath($candidate) ?: $candidate;
        }
        return $resolved;
    }
}
