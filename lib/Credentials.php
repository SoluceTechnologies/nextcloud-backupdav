<?php

declare(strict_types=1);

namespace OCA\BackupDAV;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Service identity and endpoint state, kept in app config and read on every request:
 * disable, rotate and revoke apply from the next request on.
 */
class Credentials
{
    public const APP_ID = 'backupdav';
    public const USERNAME = 'backup-service';

    private const KEY_ENABLED = 'enabled';
    private const KEY_HASH = 'secret_hash';
    private const KEY_CREATED_AT = 'secret_created_at';
    private const KEY_ALLOWED_IPS = 'allowed_ips';

    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->appConfig->getValueBool(self::APP_ID, self::KEY_ENABLED);
    }

    public function hasSecret(): bool
    {
        return $this->hash() !== '';
    }

    /** The endpoint answers only when it is enabled and a secret exists. */
    public function isLive(): bool
    {
        return $this->isEnabled() && $this->hasSecret();
    }

    public function createdAt(): int
    {
        return $this->appConfig->getValueInt(self::APP_ID, self::KEY_CREATED_AT);
    }

    /** @return list<string> IPs or CIDR ranges; empty means any address */
    public function allowedIps(): array
    {
        $raw = $this->appConfig->getValueString(self::APP_ID, self::KEY_ALLOWED_IPS);
        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $ip): bool => $ip !== '',
        ));
    }

    /** @throws \RuntimeException when a secret already exists */
    public function generate(): string
    {
        if ($this->hasSecret()) {
            throw new \RuntimeException('A secret already exists. Use backupdav:credentials:rotate to replace it.');
        }
        $secret = $this->store();
        $this->logger->info('BackupDAV service secret generated', ['app' => self::APP_ID]);
        return $secret;
    }

    /** @throws \RuntimeException when there is no secret to replace */
    public function rotate(): string
    {
        if (!$this->hasSecret()) {
            throw new \RuntimeException('No secret to rotate. Run backupdav:credentials:generate first.');
        }
        $secret = $this->store();
        $this->logger->info('BackupDAV service secret rotated', ['app' => self::APP_ID]);
        return $secret;
    }

    public function revoke(): void
    {
        $this->appConfig->deleteKey(self::APP_ID, self::KEY_HASH);
        $this->appConfig->deleteKey(self::APP_ID, self::KEY_CREATED_AT);
        $this->logger->info('BackupDAV service secret revoked', ['app' => self::APP_ID]);
    }

    /** @throws \RuntimeException when there is no secret */
    public function enable(): void
    {
        if (!$this->hasSecret()) {
            throw new \RuntimeException('No secret. Run backupdav:credentials:generate first.');
        }
        $this->appConfig->setValueBool(self::APP_ID, self::KEY_ENABLED, true);
        $this->logger->info('BackupDAV endpoint enabled', ['app' => self::APP_ID]);
    }

    public function disable(): void
    {
        $this->appConfig->setValueBool(self::APP_ID, self::KEY_ENABLED, false);
        $this->logger->info('BackupDAV endpoint disabled', ['app' => self::APP_ID]);
    }

    public function verify(string $username, string $password): bool
    {
        $hash = $this->hash();
        // Both comparisons always run, each in constant time.
        $userOk = hash_equals(self::USERNAME, $username);
        $secretOk = hash_equals($hash, hash('sha256', $password));
        return $userOk && $secretOk && $hash !== '';
    }

    private function hash(): string
    {
        return $this->appConfig->getValueString(self::APP_ID, self::KEY_HASH);
    }

    private function store(): string
    {
        // 256 random bits leave nothing to brute-force, so a fast SHA-256 keeps per-request auth cheap.
        $secret = bin2hex(random_bytes(32));
        $this->appConfig->setValueString(self::APP_ID, self::KEY_HASH, hash('sha256', $secret), sensitive: true);
        $this->appConfig->setValueInt(self::APP_ID, self::KEY_CREATED_AT, time());
        return $secret;
    }
}
