<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Tests\Unit;

use OCA\BackupDAV\Dav\StorageDirectory;
use OCA\BackupDAV\Dav\StorageFile;
use OCP\Files\Cache\ICache;
use OCP\Files\Cache\ICacheEntry;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\ServiceUnavailable;

class StorageDirectoryTest extends TestCase
{
    use MocksNodes;

    private function storage(ICache $cache): IStorage
    {
        $storage = $this->createStub(IStorage::class);
        $storage->method('getCache')->willReturn($cache);
        return $storage;
    }

    private static function names(array $nodes): array
    {
        return array_map(static fn ($node) => $node->getName(), $nodes);
    }

    public function testListsFilesAndSubfoldersFromTheFileCache(): void
    {
        $cache = $this->createStub(ICache::class);
        $cache->method('getFolderContents')->willReturnMap([
            ['', null, [$this->cacheEntry('open.txt'), $this->cacheEntry('sub', ICacheEntry::DIRECTORY_MIMETYPE, 13)]],
            ['sub', null, [$this->cacheEntry('sub/secret.txt')]],
        ]);
        $root = new StorageDirectory($this->storage($cache), '', '12');

        $children = $root->getChildren();

        $this->assertSame('12', $root->getName());
        $this->assertSame(['open.txt', 'sub'], self::names($children));
        $this->assertInstanceOf(StorageFile::class, $children[0]);
        $this->assertInstanceOf(StorageDirectory::class, $children[1]);
        $this->assertSame(['secret.txt'], self::names($children[1]->getChildren()));
        $this->assertSame(1760000000, $children[1]->getLastModified());
    }

    public function testGetChildLooksUpChildPathsInTheFileCache(): void
    {
        $cache = $this->createStub(ICache::class);
        $cache->method('get')->willReturnMap([
            ['sub', $this->cacheEntry('sub', ICacheEntry::DIRECTORY_MIMETYPE)],
            ['sub/secret.txt', $this->cacheEntry('sub/secret.txt')],
            ['missing', false],
        ]);
        $root = new StorageDirectory($this->storage($cache), '', '12');

        $file = $root->getChild('sub')->getChild('secret.txt');

        $this->assertInstanceOf(StorageFile::class, $file);
        $this->assertSame('secret.txt', $file->getName());
        $this->expectException(NotFound::class);
        $root->getChild('missing');
    }

    #[DataProvider('invalidNames')]
    public function testGetChildRejectsInvalidNamesWithoutTouchingStorage(string $name): void
    {
        $cache = $this->createMock(ICache::class);
        $cache->expects($this->never())->method('get');

        $this->expectException(NotFound::class);
        (new StorageDirectory($this->storage($cache), 'sub'))->getChild($name);
    }

    public static function invalidNames(): array
    {
        return ['empty' => [''], 'dot' => ['.'], 'dot dot' => ['..'], 'slash' => ['a/b'], 'nul byte' => ["a\0b"]];
    }

    public function testStorageErrorsAre503(): void
    {
        $cache = $this->createStub(ICache::class);
        $cache->method('getFolderContents')->willThrowException(new \RuntimeException('DB gone'));

        $this->expectException(ServiceUnavailable::class);
        (new StorageDirectory($this->storage($cache), ''))->getChildren();
    }

    public function testWritesAreForbidden(): void
    {
        $dir = new StorageDirectory($this->storage($this->createStub(ICache::class)), 'sub');
        $writes = [
            'createFile' => fn () => $dir->createFile('x', 'data'),
            'createDirectory' => fn () => $dir->createDirectory('x'),
            'delete' => fn () => $dir->delete(),
            'setName' => fn () => $dir->setName('y'),
        ];
        foreach ($writes as $name => $write) {
            try {
                $write();
                $this->fail("$name was accepted");
            } catch (Forbidden) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
