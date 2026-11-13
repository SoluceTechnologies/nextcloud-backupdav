<?php

declare(strict_types=1);

namespace OCA\BackupDAV;

use OCA\BackupDAV\Dav\AuthBackend;
use OCA\BackupDAV\Dav\File;
use OCA\BackupDAV\Dav\GroupFoldersCollection;
use OCA\BackupDAV\Dav\ReadOnlyPlugin;
use OCA\BackupDAV\Dav\StorageFile;
use OCA\BackupDAV\Dav\UsersCollection;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Ip\IFactory;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Exception as DavException;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;

/** Builds the app's own SabreDAV server, independent from Nextcloud's /remote.php/dav server. */
class ServerFactory
{
    public const NS_OWNCLOUD = 'http://owncloud.org/ns';

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
        $server->xml->namespaceMap[self::NS_OWNCLOUD] = 'oc';
        $server->on('propFind', $this->propFind(...));
        return $server;
    }

    /**
     * oc:checksums, as Nextcloud's own WebDAV serves it: rclone (vendor = owncloud) reads MD5 and
     * SHA1 from it. Only checksums stored at upload are served, never computed (that would read
     * every file from S3); Nextcloud clears them whenever the content changes.
     */
    public function propFind(PropFind $propFind, INode $node): void
    {
        if (!$node instanceof File && !$node instanceof StorageFile) {
            return;
        }
        $propFind->handle('{' . self::NS_OWNCLOUD . '}checksums', static function () use ($node): ?array {
            $checksum = $node->getChecksum();
            return $checksum === '' ? null : array_map(
                static fn (string $c): array => ['name' => '{' . self::NS_OWNCLOUD . '}checksum', 'value' => $c],
                explode(' ', $checksum),
            );
        });
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
