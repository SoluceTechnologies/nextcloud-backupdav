<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Tests\Unit;

use OCA\BackupDAV\Dav\UserCollection;
use OCA\BackupDAV\Dav\UsersCollection;
use OCP\Files\File as NcFile;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\ServiceUnavailable;

class UsersCollectionTest extends TestCase
{
    use MocksNodes;

    private function user(string $uid, int $lastLogin = 1760000000): IUser
    {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getLastLogin')->willReturn($lastLogin);
        return $user;
    }

    public function testListsEverySeenUserFromOneUnpaginatedLookupWithoutStorageSetup(): void
    {
        // getSeenUsers() pages by 1000 without ORDER BY: users could be skipped, and rclone sync would delete them.
        $all = ['ghost' => $this->user('ghost', 0), 'bd-carol x@y.z' => $this->user('bd-carol x@y.z')];
        for ($i = 0; $i < 1500; $i++) {
            $all["user$i"] = $this->user("user$i");
        }
        $users = $this->createMock(IUserManager::class);
        $users->expects($this->once())->method('search')->with('')->willReturn($all);
        $users->expects($this->never())->method('getSeenUsers');
        $root = $this->createMock(IRootFolder::class);
        $root->expects($this->never())->method('getUserFolder');

        $names = array_map(static fn ($c) => $c->getName(), (new UsersCollection($users, $root))->getChildren());

        $this->assertCount(1501, $names);
        $this->assertContains('bd-carol x@y.z', $names);
        $this->assertNotContains('ghost', $names);
    }

    #[DataProvider('hiddenUsers')]
    public function testGetChildHidesUnknownUnseenAndAliasedUsers(string $name, ?string $uid, int $lastLogin): void
    {
        $users = $this->createStub(IUserManager::class);
        $users->method('get')->willReturn($uid === null ? null : $this->user($uid, $lastLogin));

        $this->expectException(NotFound::class);
        (new UsersCollection($users, $this->createStub(IRootFolder::class)))->getChild($name);
    }

    public static function hiddenUsers(): array
    {
        return [
            'unknown' => ['nobody', null, 0],
            'never logged in' => ['ghost', 'ghost', 0],
            'case alias of alice' => ['ALICE', 'alice', 1760000000],
        ];
    }

    public function testGetChildRejectsInvalidNamesWithoutLookup(): void
    {
        $users = $this->createMock(IUserManager::class);
        $users->expects($this->never())->method('get');

        $this->expectException(NotFound::class);
        (new UsersCollection($users, $this->createStub(IRootFolder::class)))->getChild('..');
    }

    public function testGetChildReturnsSeenUserIncludingSpecialCharacters(): void
    {
        $users = $this->createStub(IUserManager::class);
        $users->method('get')->willReturn($this->user('bd-carol x@y.z'));
        $root = $this->createMock(IRootFolder::class);
        $root->expects($this->never())->method('getUserFolder');

        $child = (new UsersCollection($users, $root))->getChild('bd-carol x@y.z');

        $this->assertInstanceOf(UserCollection::class, $child);
        $this->assertSame('bd-carol x@y.z', $child->getName());
    }

    public function testUserCollectionSetsUpStorageOnceAndFiltersByHomeMount(): void
    {
        $home = $this->node(Folder::class, 'files', '/alice/');
        $home->method('getDirectoryListing')->willReturn([
            $this->node(NcFile::class, 'a.txt', '/alice/'),
            $this->node(Folder::class, 'Shared', '/alice/files/Shared/'),
        ]);
        $root = $this->createMock(IRootFolder::class);
        $root->expects($this->once())->method('getUserFolder')->with('alice')->willReturn($home);

        $user = new UserCollection($root, 'alice');
        $names = array_map(static fn ($c) => $c->getName(), $user->getChildren());
        $user->getChildren();

        $this->assertSame(['a.txt'], $names);
        $this->assertNull($user->getLastModified());
    }

    public function testUserCollectionMapsStorageSetupFailureTo503(): void
    {
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willThrowException(new \RuntimeException('S3 down'));

        $this->expectException(ServiceUnavailable::class);
        (new UserCollection($root, 'alice'))->getChildren();
    }
}
