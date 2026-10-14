<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Dav;

use OCA\BackupDAV\Credentials;
use OCP\IRequest;
use OCP\Security\Ip\IFactory;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\MethodNotAllowed;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Runs before authentication (priority 5, the auth plugin uses 10): IP allowlist first, then the
 * read-only method allowlist. Also answers OPTIONS with the real method set and logs slow PROPFINDs.
 */
class ReadOnlyPlugin extends ServerPlugin
{
    public const ALLOWED_METHODS = ['OPTIONS', 'GET', 'HEAD', 'PROPFIND'];
    private const SLOW_SECONDS = 10;

    private float $start = 0.0;

    public function __construct(
        private IRequest $request,
        private IFactory $ipFactory,
        private Credentials $credentials,
        private LoggerInterface $logger,
    ) {
    }

    public function initialize(Server $server)
    {
        $server->on('beforeMethod:*', [$this, 'beforeMethod'], 5);
        // Before CorePlugin (100), which would advertise PUT, DELETE, MOVE...
        $server->on('method:OPTIONS', [$this, 'httpOptions'], 90);
        $server->on('afterMethod:PROPFIND', [$this, 'afterPropFind']);
    }

    public function beforeMethod(RequestInterface $request, ResponseInterface $response): void
    {
        $this->start = microtime(true);
        $this->checkIp();
        if (!in_array($request->getMethod(), self::ALLOWED_METHODS, true)) {
            throw new class('Read-only endpoint') extends MethodNotAllowed {
                public function getHTTPHeaders(Server $server)
                {
                    return ['Allow' => implode(', ', ReadOnlyPlugin::ALLOWED_METHODS)];
                }
            };
        }
    }

    public function httpOptions(RequestInterface $request, ResponseInterface $response): bool
    {
        $response->setHeader('Allow', implode(', ', self::ALLOWED_METHODS));
        $response->setHeader('DAV', '1');
        $response->setHeader('MS-Author-Via', 'DAV');
        $response->setHeader('Accept-Ranges', 'bytes');
        $response->setHeader('Content-Length', '0');
        $response->setStatus(200);
        return false;
    }

    public function afterPropFind(RequestInterface $request): void
    {
        $seconds = microtime(true) - $this->start;
        if ($seconds > self::SLOW_SECONDS) {
            $this->logger->warning('BackupDAV slow PROPFIND', [
                'app' => Credentials::APP_ID,
                'path' => $request->getPath(),
                'seconds' => round($seconds, 1),
            ]);
        }
    }

    private function checkIp(): void
    {
        $allowed = $this->credentials->allowedIps();
        if ($allowed === []) {
            return;
        }
        $ip = $this->request->getRemoteAddress();
        $ranges = array_map($this->ipFactory->rangeFromString(...), $allowed);
        if (!$this->ipFactory->addressFromString($ip)->matches(...$ranges)) {
            $this->logger->warning('BackupDAV request from a non-allowed IP', ['app' => Credentials::APP_ID, 'ip' => $ip]);
            throw new Forbidden('Forbidden');
        }
    }
}
