<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Tests\Unit;

use OCA\BackupDAV\Dav\GroupFoldersCollection;
use OCA\BackupDAV\Dav\StorageDirectory;
use OCA\BackupDAV\GroupFolderIndex;
use OCP\Files\Cache\ICache;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\ServiceUnavailable;

class GroupFoldersCollectionTest extends TestCase
{
    use MocksNodes;

    private function collection(): GroupFoldersCollection
    {
        $cache = $this->createStub(ICache::class);
        $cache->method('getFolderContents')->willReturn([$this->cacheEntry('bilan.xlsx')]);
        $storage = $this->createStub(IStorage::class);
        $storage->method('getCache')->willReturn($cache);
        $index = $this->createStub(GroupFolderIndex::class);
        $index->method('ids')->willReturn([1, 12]);
        $index->method('storage')->willReturnCallback(static fn (int $id): ?IStorage => in_array($id, [1, 12], true) ? $storage : null);
        return new GroupFoldersCollection($index);
    }

    public function testListsEveryFolderNamedById(): void
    {
        $children = $this->collection()->getChildren();

        $this->assertSame(['1', '12'], array_map(static fn ($c) => $c->getName(), $children));
        $this->assertContainsOnlyInstancesOf(StorageDirectory::class, $children);
    }

    public function testGetChildIsTheRootOfTheFolderStorage(): void
    {
        $folder = $this->collection()->getChild('12');

        $this->assertSame('12', $folder->getName());
        $this->assertSame(['bilan.xlsx'], array_map(static fn ($c) => $c->getName(), $folder->getChildren()));
    }

    #[DataProvider('notFolderIds')]
    public function testGetChildRejectsUnknownAndNonCanonicalIds(string $name): void
    {
        $this->expectException(NotFound::class);
        $this->collection()->getChild($name);
    }

    public static function notFolderIds(): array
    {
        return [
            'unknown id' => ['2'],
            'leading zero alias' => ['01'],
            'mount point name' => ['Compta'],
            'dot dot' => ['..'],
            'empty' => [''],
            'negative' => ['-1'],
        ];
    }

    public function testIndexFailureIs503NotAnEmptyListing(): void
    {
        $index = $this->createStub(GroupFolderIndex::class);
        $index->method('ids')->willThrowException(new \RuntimeException('groupfolders API changed'));

        $this->expectException(ServiceUnavailable::class);
        (new GroupFoldersCollection($index))->getChildren();
    }

    public function testStorageFailureIs503(): void
    {
        $index = $this->createStub(GroupFolderIndex::class);
        $index->method('ids')->willReturn([1]);
        $index->method('storage')->willThrowException(new \RuntimeException('cannot open storage'));

        $this->expectException(ServiceUnavailable::class);
        (new GroupFoldersCollection($index))->getChild('1');
    }
}
