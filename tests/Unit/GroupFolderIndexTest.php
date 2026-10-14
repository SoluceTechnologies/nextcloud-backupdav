<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Tests\Unit;

use OCA\BackupDAV\GroupFolderIndex;
use OCP\App\IAppManager;
use OCP\Encryption\IManager;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class GroupFolderIndexTest extends TestCase
{
    /** Stand-in for groupfolders' FolderStorageManager; records how storages are opened. */
    private object $storageManager;
    private IStorage $storage;

    protected function setUp(): void
    {
        $this->storage = $this->createStub(IStorage::class);
        $this->storageManager = new class($this->storage) {
            public array $calls = [];

            public function __construct(private IStorage $storage)
            {
            }

            public function getBaseStorageForFolder(int $folderId, bool $separateStorage, ?object $folder = null, mixed $user = null): IStorage
            {
                $this->calls[] = [$folderId, $separateStorage, $user];
                return $this->storage;
            }
        };
    }

    /** Stand-in for groupfolders' folder definitions. */
    private static function folder(int $id): object
    {
        return new class($id) {
            public function __construct(public readonly int $id)
            {
            }

            public function useSeparateStorage(): bool
            {
                return true;
            }
        };
    }

    private function index(array $folders, bool $appEnabled = true, bool $encryption = false): GroupFolderIndex
    {
        $apps = $this->createStub(IAppManager::class);
        $apps->method('isEnabledForAnyone')->willReturn($appEnabled);
        $crypto = $this->createStub(IManager::class);
        $crypto->method('isEnabled')->willReturn($encryption);
        $folderManager = new class($folders) {
            public function __construct(private array $folders)
            {
            }

            public function getAllFolders(): array
            {
                return $this->folders;
            }
        };
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn (string $id): object => match ($id) {
            'OCA\GroupFolders\Folder\FolderManager' => $folderManager,
            'OCA\GroupFolders\Mount\FolderStorageManager' => $this->storageManager,
        });
        return new GroupFolderIndex($apps, $crypto, $container);
    }

    public function testListsGroupFolderIds(): void
    {
        $this->assertSame([1, 12], $this->index([1 => self::folder(1), 12 => self::folder(12)])->ids());
    }

    public function testStorageIsOpenedWithoutAUserSoAdvancedPermissionsDoNotFilterIt(): void
    {
        $storage = $this->index([12 => self::folder(12)])->storage(12);

        $this->assertSame($this->storage, $storage);
        $this->assertSame([[12, true, null]], $this->storageManager->calls);
    }

    public function testUnknownIdHasNoStorage(): void
    {
        $this->assertNull($this->index([12 => self::folder(12)])->storage(99));
    }

    public function testEmptyWhenGroupfoldersIsNotEnabled(): void
    {
        $index = $this->index([12 => self::folder(12)], appEnabled: false);

        $this->assertSame([], $index->ids());
        $this->assertNull($index->storage(12));
    }

    public function testServerSideEncryptionIsAnErrorNotAnExportOfEncryptedData(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->index([12 => self::folder(12)], encryption: true)->ids();
    }

    public function testNoGroupFoldersIsFineWithServerSideEncryption(): void
    {
        $this->assertSame([], $this->index([], encryption: true)->ids());
    }

    public function testUnusableGroupfoldersApiIsAnErrorNotAnEmptyList(): void
    {
        $apps = $this->createStub(IAppManager::class);
        $apps->method('isEnabledForAnyone')->willReturn(true);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('class not found'));

        $this->expectException(\RuntimeException::class);
        (new GroupFolderIndex($apps, $this->createStub(IManager::class), $container))->ids();
    }
}
