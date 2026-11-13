<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Tests\Unit;

use OCA\BackupDAV\Credentials;
use OCA\BackupDAV\Dav\File;
use OCA\BackupDAV\Dav\StorageFile;
use OCA\BackupDAV\GroupFolderIndex;
use OCA\BackupDAV\ServerFactory;
use OCP\Files\File as NcFile;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorage;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Ip\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\NotAuthenticated;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\ServiceUnavailable;
use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

class ServerFactoryTest extends TestCase
{
    use MocksNodes;

    private function factory(LoggerInterface $logger): ServerFactory
    {
        return new ServerFactory(
            $this->createStub(IRootFolder::class),
            $this->createStub(IUserManager::class),
            $this->createStub(Credentials::class),
            $this->createStub(GroupFolderIndex::class),
            $this->createStub(IRequest::class),
            $this->createStub(IThrottler::class),
            $this->createStub(IFactory::class),
            $logger,
        );
    }

    public function testClientErrorsAreNotLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $logger->expects($this->never())->method('error');

        $factory = $this->factory($logger);
        $factory->logException(new NotFound('Not found'));
        $factory->logException(new NotAuthenticated('No credentials'));
    }

    public function testStorageErrorsAreWarningsWithTheUnderlyingException(): void
    {
        $cause = new \RuntimeException('S3 read timeout');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('BackupDAV storage error: Storage unavailable', ['app' => 'backupdav', 'exception' => $cause]);

        $this->factory($logger)->logException(new ServiceUnavailable('Storage unavailable', 0, $cause));
    }

    public function testUnexpectedErrorsAreErrors(): void
    {
        $bug = new \TypeError('boom');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('BackupDAV request failed: boom', ['app' => 'backupdav', 'exception' => $bug]);

        $this->factory($logger)->logException($bug);
    }

    /** The PROPFIND rclone sends with vendor = owncloud, answered by a real Sabre server. */
    public function testStoredChecksumsAreServedAsOcChecksums(): void
    {
        $uploaded = $this->createStub(NcFile::class);
        $uploaded->method('getName')->willReturn('client-upload.pdf');
        $uploaded->method('getChecksum')->willReturn('SHA1:aa MD5:bb');
        $browser = $this->createStub(NcFile::class);
        $browser->method('getName')->willReturn('browser-upload.pdf');
        $browser->method('getChecksum')->willReturn('');
        $entry = $this->cacheEntry('notes.txt');
        $entry->method('offsetGet')->willReturnMap([['checksum', 'MD5:cc']]);

        $server = new Server(new SimpleCollection('root', [
            new File($uploaded),
            new File($browser),
            new StorageFile($this->createStub(IStorage::class), $entry),
        ]));
        $server->setBaseUri('/');
        $server->on('propFind', $this->factory($this->createStub(LoggerInterface::class))->propFind(...));
        $response = new Response();
        $server->invokeMethod(new Request('PROPFIND', '/', ['Depth' => '1'], '<?xml version="1.0"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:checksums/></d:prop></d:propfind>'), $response, false);

        $this->assertSame(207, $response->getStatus());
        $dom = new \DOMDocument();
        $dom->loadXML($response->getBodyAsString());
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('oc', ServerFactory::NS_OWNCLOUD);
        $served = [];
        foreach ($xpath->query('//d:response') as $r) {
            $sums = $xpath->query('d:propstat[contains(d:status, "200")]/d:prop/oc:checksums/oc:checksum', $r);
            $served[$xpath->evaluate('string(d:href)', $r)] = array_map(fn ($n) => $n->textContent, iterator_to_array($sums));
        }
        $this->assertSame([
            '/' => [],
            '/client-upload.pdf' => ['SHA1:aa', 'MD5:bb'],
            '/browser-upload.pdf' => [],
            '/notes.txt' => ['MD5:cc'],
        ], $served);
    }
}
