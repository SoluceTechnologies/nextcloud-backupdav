<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Tests\Unit;

use OCA\BackupDAV\Credentials;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CredentialsTest extends TestCase
{
    /** @var array<string, mixed> values of the backupdav app config */
    private array $store = [];
    private Credentials $credentials;

    protected function setUp(): void
    {
        $config = $this->createStub(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(fn (string $app, string $key): string => $this->store[$key] ?? '');
        $config->method('getValueBool')->willReturnCallback(fn (string $app, string $key): bool => $this->store[$key] ?? false);
        $config->method('getValueInt')->willReturnCallback(fn (string $app, string $key): int => $this->store[$key] ?? 0);
        $set = function (string $app, string $key, mixed $value): bool {
            $this->store[$key] = $value;
            return true;
        };
        $config->method('setValueString')->willReturnCallback($set);
        $config->method('setValueBool')->willReturnCallback($set);
        $config->method('setValueInt')->willReturnCallback($set);
        $config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
            unset($this->store[$key]);
        });
        $this->credentials = new Credentials($config, $this->createStub(LoggerInterface::class));
    }

    public function testGenerateReturnsSecretAndStoresOnlyItsHash(): void
    {
        $secret = $this->credentials->generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        $this->assertSame(hash('sha256', $secret), $this->store['secret_hash']);
        $this->assertNotContains($secret, $this->store);
        $this->assertGreaterThan(0, $this->credentials->createdAt());
        $this->assertTrue($this->credentials->verify('backup-service', $secret));
    }

    public function testGenerateRefusesWhenSecretExists(): void
    {
        $this->credentials->generate();
        $this->expectException(\RuntimeException::class);
        $this->credentials->generate();
    }

    public function testVerifyRejectsWrongUserWrongSecretAndMissingSecret(): void
    {
        $this->assertFalse($this->credentials->verify('backup-service', ''));
        $secret = $this->credentials->generate();
        $this->assertFalse($this->credentials->verify('admin', $secret));
        $this->assertFalse($this->credentials->verify('Backup-Service', $secret));
        $this->assertFalse($this->credentials->verify('backup-service', 'wrong'));
    }

    public function testRotateInvalidatesPreviousSecret(): void
    {
        $first = $this->credentials->generate();
        $second = $this->credentials->rotate();

        $this->assertNotSame($first, $second);
        $this->assertFalse($this->credentials->verify('backup-service', $first));
        $this->assertTrue($this->credentials->verify('backup-service', $second));
    }

    public function testRotateRefusesWithoutSecret(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->credentials->rotate();
    }

    public function testRevokeBlocksAccessAndTakesEndpointOffline(): void
    {
        $secret = $this->credentials->generate();
        $this->credentials->enable();

        $this->credentials->revoke();

        $this->assertFalse($this->credentials->verify('backup-service', $secret));
        $this->assertFalse($this->credentials->isLive());
        $this->assertSame(0, $this->credentials->createdAt());
    }

    public function testEndpointIsLiveOnlyWhenEnabledWithSecret(): void
    {
        $this->assertFalse($this->credentials->isLive());
        $this->credentials->generate();
        $this->assertFalse($this->credentials->isLive());
        $this->credentials->enable();
        $this->assertTrue($this->credentials->isLive());
        $this->credentials->disable();
        $this->assertFalse($this->credentials->isLive());
    }

    public function testEndpointSwitchIsIndependentFromNextcloudsAppEnabledFlag(): void
    {
        // Nextcloud keeps its own "app is enabled" flag in the app's config under the key "enabled".
        $this->store['enabled'] = true;
        $this->credentials->generate();

        $this->assertFalse($this->credentials->isLive());
        $this->credentials->enable();
        $this->credentials->disable();
        $this->assertTrue($this->store['enabled'], 'disabling the endpoint must not disable the app');
    }

    public function testEnableRequiresSecret(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->credentials->enable();
    }

    public function testAllowedIpsParsing(): void
    {
        $this->assertSame([], $this->credentials->allowedIps());
        $this->store['allowed_ips'] = ' 10.0.0.0/8, ,192.0.2.1 ';
        $this->assertSame(['10.0.0.0/8', '192.0.2.1'], $this->credentials->allowedIps());
    }
}
