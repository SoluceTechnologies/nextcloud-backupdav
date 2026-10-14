<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Tests\Unit;

use OCA\BackupDAV\Credentials;
use OCA\BackupDAV\GroupFolderIndex;
use OCA\BackupDAV\ServerFactory;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Ip\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\NotAuthenticated;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\ServiceUnavailable;

class ServerFactoryTest extends TestCase
{
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
}
