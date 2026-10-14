<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Tests\Unit;

use OCA\BackupDAV\Dav\Directory;
use OCA\BackupDAV\Dav\GroupFoldersCollection;
use OCA\BackupDAV\GroupFolderIndex;
use OCP\Files\File as NcFile;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\ServiceUnavailable;

class GroupFoldersCollectionTest extends TestCase
{
    use MocksNodes;

    /** @param array<int, array{rootId: int, acl: bool}> $folders */
    private function index(array $folders): GroupFolderIndex
    {
        $index = $this->createStub(GroupFolderIndex::class);
        $index->method('folders')->willReturn($folders);
        return $index;
    }

    /** Root folder resolving file ids to nodes; unknown ids resolve to null (never mounted). */
    private function root(array $nodesById): IRootFolder
    {
        $root = $this->createStub(IRootFolder::class);
        $root->method('getFirstNodeById')->willReturnCallback(static fn (int $id) => $nodesById[$id] ?? null);
        return $root;
    }

    private function collection(?LoggerInterface $logger = null): GroupFoldersCollection
    {
        return new GroupFoldersCollection(
            $this->root([
                50 => $this->node(Folder::class, 'Compta', '/alice/files/Compta/'),
                54 => $this->node(Folder::class, 'RH', '/bob/files/RH/'),
            ]),
            $this->index([
                1 => ['rootId' => 50, 'acl' => false],
                12 => ['rootId' => 54, 'acl' => true],
                7 => ['rootId' => 99, 'acl' => false],
            ]),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    public function testListsReachableFoldersNamedById(): void
    {
        $children = $this->collection()->getChildren();

        $this->assertSame(['1', '12'], array_map(static fn ($c) => $c->getName(), $children));
        $this->assertContainsOnlyInstancesOf(Directory::class, $children);
    }

    public function testSkipsAndLogsFoldersNoMemberHasOpened(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'BackupDAV cannot reach group folder: no member has opened it yet',
            ['app' => 'backupdav', 'groupFolderId' => 7],
        );

        $this->collection($logger)->getChildren();
    }

    public function testFolderContentIsFilteredAgainstTheGroupMount(): void
    {
        $folder = $this->node(Folder::class, 'Compta', '/alice/files/Compta/');
        $folder->method('getDirectoryListing')->willReturn([
            $this->node(NcFile::class, 'bilan.xlsx', '/alice/files/Compta/'),
            $this->node(Folder::class, 'nested', '/alice/files/Compta/nested/'),
        ]);
        $collection = new GroupFoldersCollection(
            $this->root([50 => $folder]),
            $this->index([1 => ['rootId' => 50, 'acl' => false]]),
            $this->createStub(LoggerInterface::class),
        );

        $child = $collection->getChild('1');

        $this->assertSame('1', $child->getName());
        $this->assertSame(['bilan.xlsx'], array_map(static fn ($c) => $c->getName(), $child->getChildren()));
    }

    #[DataProvider('notFolderIds')]
    public function testGetChildRejectsUnknownUnreachableAndNonCanonicalIds(string $name): void
    {
        $this->expectException(NotFound::class);
        $this->collection()->getChild($name);
    }

    public static function notFolderIds(): array
    {
        return [
            'unknown id' => ['2'],
            'unreachable folder' => ['7'],
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
        $index->method('folders')->willThrowException(new \RuntimeException('groupfolders API changed'));

        $this->expectException(ServiceUnavailable::class);
        (new GroupFoldersCollection($this->root([]), $index, $this->createStub(LoggerInterface::class)))->getChildren();
    }
}
