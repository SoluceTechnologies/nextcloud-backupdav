<?php

declare(strict_types=1);

namespace OCA\BackupDAV;

use OCA\BackupDAV\Dav\AuthBackend;
use OCA\BackupDAV\Dav\GroupFoldersCollection;
use OCA\BackupDAV\Dav\ReadOnlyPlugin;
use OCA\BackupDAV\Dav\UsersCollection;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Ip\IFactory;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Exception as DavException;
use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;

/** Builds the app's own SabreDAV server, independent from Nextcloud's /remote.php/dav server. */
class ServerFactory
{
    public function __construct(
        private IRootFolder $rootFolder,
        private IUserManager $userManager,
        private Credentials $credentials,
        private GroupFolderIndex $groupFolders,
        private IRequest $request,
        private IThrottler $throttler,
        private IFactory $ipFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function create(string $baseUri): Server
    {
        Server::$exposeVersion = false;
        $server = new Server(new SimpleCollection('root', [
            new UsersCollection($this->userManager, $this->rootFolder),
            new GroupFoldersCollection($this->groupFolders),
        ]));
        $server->httpRequest->setUrl($this->request->getRequestUri());
        $server->setBaseUri($baseUri);
        $server->addPlugin(new ReadOnlyPlugin($this->request, $this->ipFactory, $this->credentials, $this->logger));
        $server->addPlugin(new AuthPlugin(new AuthBackend($this->credentials, $this->throttler, $this->request, $this->logger)));
        $server->on('exception', $this->logException(...));
        return $server;
    }

    /** 4xx are expected client errors (auth/IP failures are logged where they happen). */
    public function logException(\Throwable $e): void
    {
        $status = $e instanceof DavException ? $e->getHTTPCode() : 500;
        if ($status < 500) {
            return;
        }
        $context = ['app' => Credentials::APP_ID, 'exception' => $e->getPrevious() ?? $e];
        if ($status === 503) {
            $this->logger->warning('BackupDAV storage error: ' . $e->getMessage(), $context);
        } else {
            $this->logger->error('BackupDAV request failed: ' . $e->getMessage(), $context);
        }
    }
}
