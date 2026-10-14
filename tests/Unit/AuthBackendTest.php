<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Tests\Unit;

use OCA\BackupDAV\Credentials;
use OCA\BackupDAV\Dav\AuthBackend;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Bruteforce\MaxDelayReached;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception as DavException;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

class AuthBackendTest extends TestCase
{
    private const IP = '203.0.113.7';

    private function backend(IThrottler $throttler, ?LoggerInterface $logger = null): AuthBackend
    {
        $credentials = $this->createStub(Credentials::class);
        $credentials->method('verify')->willReturnCallback(
            static fn (string $user, string $password): bool => $user === 'backup-service' && $password === 'good-secret',
        );
        $request = $this->createStub(IRequest::class);
        $request->method('getRemoteAddress')->willReturn(self::IP);
        return new AuthBackend($credentials, $throttler, $request, $logger ?? $this->createStub(LoggerInterface::class));
    }

    private function request(?string $user = null, ?string $password = null): Request
    {
        $headers = $user === null ? [] : ['Authorization' => 'Basic ' . base64_encode("$user:$password")];
        return new Request('PROPFIND', '/', $headers);
    }

    public function testAcceptsServiceCredentials(): void
    {
        $throttler = $this->createMock(IThrottler::class);
        $throttler->expects($this->never())->method('registerAttempt');

        $result = $this->backend($throttler)->check($this->request('backup-service', 'good-secret'), new Response());

        $this->assertSame([true, 'principals/backup-service'], $result);
    }

    public function testWrongSecretRegistersAttemptAndLogsOnlyTheIp(): void
    {
        $throttler = $this->createMock(IThrottler::class);
        $throttler->expects($this->once())->method('registerAttempt')->with('backupdav', self::IP);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('BackupDAV authentication failed', ['app' => 'backupdav', 'ip' => self::IP]);

        [$ok] = $this->backend($throttler, $logger)->check($this->request('backup-service', 'wrong'), new Response());

        $this->assertFalse($ok);
    }

    public function testMissingCredentialsAreNotCountedAsAnAttempt(): void
    {
        $throttler = $this->createMock(IThrottler::class);
        $throttler->expects($this->never())->method('registerAttempt');

        [$ok] = $this->backend($throttler)->check($this->request(), new Response());

        $this->assertFalse($ok);
    }

    public function testBlockedClientWithWrongSecretGets429(): void
    {
        $throttler = $this->createStub(IThrottler::class);
        $throttler->method('sleepDelayOrThrowOnMax')->willThrowException(new MaxDelayReached());

        try {
            $this->backend($throttler)->check($this->request('backup-service', 'wrong'), new Response());
            $this->fail('blocked request was not refused with 429');
        } catch (DavException $e) {
            $this->assertSame(429, $e->getHTTPCode());
        }
    }

    public function testCorrectSecretIsAcceptedEvenWhenTheIpIsBlocked(): void
    {
        // Rotating while an old rclone job still sends the previous secret must not lock out the new one.
        $throttler = $this->createStub(IThrottler::class);
        $throttler->method('sleepDelayOrThrowOnMax')->willThrowException(new MaxDelayReached());

        $result = $this->backend($throttler)->check($this->request('backup-service', 'good-secret'), new Response());

        $this->assertSame([true, 'principals/backup-service'], $result);
    }

    public function testChallengeUsesTheBackupDavRealm(): void
    {
        $response = new Response();

        $this->backend($this->createStub(IThrottler::class))->challenge($this->request(), $response);

        $this->assertStringContainsString('Basic realm="BackupDAV"', $response->getHeader('WWW-Authenticate'));
    }
}
