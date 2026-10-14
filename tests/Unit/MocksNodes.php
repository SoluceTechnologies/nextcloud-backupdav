<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Tests\Unit;

use OCP\Files\Cache\ICacheEntry;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Node;

trait MocksNodes
{
    /**
     * A Files node with a name and the mount point it lives on. $mock = true returns a mock
     * (for expects()), otherwise a stub.
     *
     * @param class-string<Node> $class
     */
    private function node(string $class, string $name, string $mount, bool $mock = false): Node
    {
        $mountPoint = $this->createStub(IMountPoint::class);
        $mountPoint->method('getMountPoint')->willReturn($mount);
        $node = $mock ? $this->createMock($class) : $this->createStub($class);
        $node->method('getName')->willReturn($name);
        $node->method('getMountPoint')->willReturn($mountPoint);
        return $node;
    }

    /** A file cache entry, as read from a group folder's storage; $path is relative to the folder root. */
    private function cacheEntry(string $path, string $mime = 'text/plain', int $size = 7): ICacheEntry
    {
        $entry = $this->createStub(ICacheEntry::class);
        $entry->method('getPath')->willReturn($path);
        $entry->method('getName')->willReturn(basename($path));
        $entry->method('getMimeType')->willReturn($mime);
        $entry->method('getSize')->willReturn($size);
        $entry->method('getMTime')->willReturn(1760000000);
        $entry->method('getEtag')->willReturn('etag-' . $path);
        return $entry;
    }
}
