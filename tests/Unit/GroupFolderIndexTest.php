<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Tests\Unit;

use OCA\BackupDAV\GroupFolderIndex;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

class GroupFolderIndexTest extends TestCase
{
    private function index(bool $enabled): GroupFolderIndex
    {
        $apps = $this->createStub(IAppManager::class);
        $apps->method('isEnabledForAnyone')->willReturn($enabled);
        return new GroupFolderIndex($apps);
    }

    public function testEmptyWhenGroupfoldersIsNotEnabled(): void
    {
        $this->assertSame([], $this->index(false)->folders());
    }

    public function testUnusableGroupfoldersApiIsAnErrorNotAnEmptyList(): void
    {
        // In unit tests the groupfolders classes and the Nextcloud server do not exist, like an incompatible app version.
        $this->expectException(\RuntimeException::class);
        $this->index(true)->folders();
    }
}
