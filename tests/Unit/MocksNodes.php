<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Tests\Unit;

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
}
