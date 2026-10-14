<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Dav;

use OCA\BackupDAV\GroupFolderIndex;
use Sabre\DAV\Collection;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\ServiceUnavailable;

/**
 * groupfolders/<id>/: each group folder once, named by its rename-stable id (`occ groupfolders:list`
 * maps ids to names), read straight from its storage: no member is needed and advanced
 * permissions (ACL) cannot hide content from the backup.
 */
class GroupFoldersCollection extends Collection
{
    public function __construct(private GroupFolderIndex $index)
    {
    }

    public function getName()
    {
        return 'groupfolders';
    }

    public function getChildren()
    {
        return array_map($this->folder(...), $this->ids());
    }

    public function getChild($name)
    {
        $id = ctype_digit($name) ? (int)$name : -1;
        if ((string)$id !== $name || !in_array($id, $this->ids(), true)) {
            throw new NotFound('Not found');
        }
        return $this->folder($id);
    }

    /** @return list<int> */
    private function ids(): array
    {
        try {
            return $this->index->ids();
        } catch (\RuntimeException $e) {
            throw new ServiceUnavailable('Group folders unavailable', 0, $e);
        }
    }

    private function folder(int $id): StorageDirectory
    {
        try {
            $storage = $this->index->storage($id);
        } catch (\RuntimeException $e) {
            throw new ServiceUnavailable('Group folders unavailable', 0, $e);
        }
        if ($storage === null) {
            throw new NotFound('Not found');
        }
        return new StorageDirectory($storage, '', (string)$id);
    }
}
