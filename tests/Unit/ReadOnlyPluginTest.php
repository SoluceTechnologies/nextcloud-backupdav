<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Tests\Unit;

use OCA\BackupDAV\Credentials;
use OCA\BackupDAV\Dav\ReadOnlyPlugin;
use OCP\IRequest;
use OCP\Security\Ip\IAddress;
use OCP\Security\Ip\IFactory;
use OCP\Security\Ip\IRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\MethodNotAllowed;
use Sabre\DAV\Server;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

class ReadOnlyPluginTest extends TestCase
{
    private function plugin(array $allowedIps = [], bool $ipMatches = true, ?IFactory $factory = null): ReadOnlyPlugin
    {
        $credentials = $this->createStub(Credentials::class);
        $credentials->method('allowedIps')->willReturn($allowedIps);
        $request = $this->createStub(IRequest::class);
        $request->method('getRemoteAddress')->willReturn('203.0.113.7');
        if ($factory === null) {
            $address = $this->createStub(IAddress::class);
            $address->method('matches')->willReturn($ipMatches);
            $factory = $this->createStub(IFactory::class);
            $factory->method('rangeFromString')->willReturn($this->createStub(IRange::class));
            $factory->method('addressFromString')->willReturn($address);
        }
        return new ReadOnlyPlugin($request, $factory, $credentials, $this->createStub(LoggerInterface::class));
    }

    #[DataProvider('refusedMethods')]
    public function testRefusesEveryNonReadMethod(string $method): void
    {
        try {
            $this->plugin()->beforeMethod(new Request($method, '/users/'), new Response());
            $this->fail("$method was accepted");
        } catch (MethodNotAllowed $e) {
            $this->assertSame(
                ['Allow' => 'OPTIONS, GET, HEAD, PROPFIND'],
                $e->getHTTPHeaders($this->createStub(Server::class)),
            );
        }
    }

    public static function refusedMethods(): array
    {
        $methods = ['PUT', 'DELETE', 'MKCOL', 'MOVE', 'COPY', 'PROPPATCH', 'LOCK', 'UNLOCK', 'POST', 'PATCH', 'REPORT', 'SEARCH', 'ACL', 'FOO', 'get'];
        return array_combine($methods, array_map(static fn (string $m): array => [$m], $methods));
    }

    #[DataProvider('readMethods')]
    public function testAllowsReadMethods(string $method): void
    {
        $this->plugin()->beforeMethod(new Request($method, '/users/'), new Response());
        $this->addToAssertionCount(1);
    }

    public static function readMethods(): array
    {
        return ['OPTIONS' => ['OPTIONS'], 'GET' => ['GET'], 'HEAD' => ['HEAD'], 'PROPFIND' => ['PROPFIND']];
    }

    public function testEmptyAllowlistSkipsTheIpCheck(): void
    {
        $factory = $this->createMock(IFactory::class);
        $factory->expects($this->never())->method('addressFromString');

        $this->plugin([], true, $factory)->beforeMethod(new Request('GET', '/'), new Response());
    }

    public function testRefusesAddressOutsideAllowlist(): void
    {
        $this->expectException(Forbidden::class);
        $this->plugin(['192.0.2.0/24'], false)->beforeMethod(new Request('GET', '/'), new Response());
    }

    public function testAcceptsAddressInsideAllowlist(): void
    {
        $this->plugin(['203.0.113.0/24'], true)->beforeMethod(new Request('GET', '/'), new Response());
        $this->addToAssertionCount(1);
    }

    public function testIpIsCheckedBeforeTheMethod(): void
    {
        $this->expectException(Forbidden::class);
        $this->plugin(['192.0.2.0/24'], false)->beforeMethod(new Request('PUT', '/'), new Response());
    }

    public function testOptionsAdvertisesReadOnlyMethods(): void
    {
        $response = new Response();

        $continue = $this->plugin()->httpOptions(new Request('OPTIONS', '/'), $response);

        $this->assertFalse($continue);
        $this->assertSame(200, $response->getStatus());
        $this->assertSame('OPTIONS, GET, HEAD, PROPFIND', $response->getHeader('Allow'));
        $this->assertSame('1', $response->getHeader('DAV'));
    }
}
