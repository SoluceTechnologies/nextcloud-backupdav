<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Tests\Unit;

use OCA\BackupDAV\Dav\Directory;
use OCA\BackupDAV\Dav\File;
use OCP\Files\File as NcFile;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\ServiceUnavailable;

class DirectoryTest extends TestCase
{
    use MocksNodes;

    private const HOME = '/alice/';

    private static function names(array $nodes): array
    {
        return array_map(static fn ($node) => $node->getName(), $nodes);
    }

    public function testListingHidesChildrenOnOtherMounts(): void
    {
        $home = $this->node(Folder::class, 'files', self::HOME);
        $home->method('getDirectoryListing')->willReturn([
            $this->node(NcFile::class, 'a.txt', self::HOME),
            $this->node(Folder::class, 'Documents', self::HOME),
            $this->node(Folder::class, 'Shared with me', '/alice/files/Shared with me/'),
            $this->node(Folder::class, 'Team', '/alice/files/Team/'),
        ]);

        $children = (new Directory($home))->getChildren();

        $this->assertSame(['a.txt', 'Documents'], self::names($children));
        $this->assertInstanceOf(File::class, $children[0]);
        $this->assertInstanceOf(Directory::class, $children[1]);
    }

    public function testGetChildRefusesForeignMount(): void
    {
        $home = $this->node(Folder::class, 'files', self::HOME);
        $home->method('get')->willReturn($this->node(Folder::class, 'Shared with me', '/alice/files/Shared with me/'));

        $this->expectException(NotFound::class);
        (new Directory($home))->getChild('Shared with me');
    }

    public function testSubdirectoriesFilterAgainstTheExportRootMount(): void
    {
        $docs = $this->node(Folder::class, 'Documents', self::HOME);
        $docs->method('getDirectoryListing')->willReturn([
            $this->node(NcFile::class, 'contrat.pdf', self::HOME),
            $this->node(Folder::class, 'mounted', '/alice/files/Documents/mounted/'),
        ]);
        $home = $this->node(Folder::class, 'files', self::HOME);
        $home->method('get')->willReturn($docs);

        $children = (new Directory($home))->getChild('Documents')->getChildren();

        $this->assertSame(['contrat.pdf'], self::names($children));
    }

    #[DataProvider('invalidNames')]
    public function testGetChildRejectsInvalidNamesWithoutTouchingStorage(string $name): void
    {
        $home = $this->node(Folder::class, 'files', self::HOME, mock: true);
        $home->expects($this->never())->method('get');

        $this->expectException(NotFound::class);
        (new Directory($home))->getChild($name);
    }

    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'dot' => ['.'],
            'dot dot' => ['..'],
            'slash' => ['a/b'],
            'backslash' => ['a\\b'],
            'nul byte' => ["a\0b"],
        ];
    }

    #[DataProvider('validNames')]
    public function testUnusualButValidNamesAreAccepted(string $name): void
    {
        $this->assertTrue(Directory::isValidName($name));
    }

    public static function validNames(): array
    {
        return [
            'unicode and spaces' => ['Été 2026 — Ünïcødé spaced.txt'],
            'url-reserved characters' => ["100% #1 & co; a+b=c 'q'?.txt"],
            'emoji' => ['photo 📷.txt'],
            'leading dot' => ['.hidden'],
            'dots inside' => ['a..b'],
        ];
    }

    #[DataProvider('lookupFailures')]
    public function testGetChildMapsLookupFailures(\Exception $failure, string $expected): void
    {
        $home = $this->node(Folder::class, 'files', self::HOME);
        $home->method('get')->willThrowException($failure);

        $this->expectException($expected);
        (new Directory($home))->getChild('x');
    }

    public static function lookupFailures(): array
    {
        return [
            'missing' => [new NotFoundException(), NotFound::class],
            'invalid path' => [new NotPermittedException(), NotFound::class],
            'storage error' => [new \RuntimeException('S3 timeout'), ServiceUnavailable::class],
        ];
    }

    public function testListingStorageErrorIs503(): void
    {
        $home = $this->node(Folder::class, 'files', self::HOME);
        $home->method('getDirectoryListing')->willThrowException(new \RuntimeException('DB gone'));

        $this->expectException(ServiceUnavailable::class);
        (new Directory($home))->getChildren();
    }

    public function testWritesAreForbidden(): void
    {
        $dir = new Directory($this->node(Folder::class, 'files', self::HOME));
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
