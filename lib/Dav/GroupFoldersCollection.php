<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Dav;

use OCA\BackupDAV\Credentials;
use OCA\BackupDAV\GroupFolderIndex;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Collection;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\ServiceUnavailable;

/**
 * groupfolders/<id>/: each group folder once, named by its rename-stable id (`occ groupfolders:list`
 * maps ids to names). A folder is read through one member's mount, found in Nextcloud's mount cache
 * by its root file id: this works for separate storages and for the legacy __groupfolders layout.
 */
class GroupFoldersCollection extends Collection
{
    public function __construct(
        private IRootFolder $rootFolder,
        private GroupFolderIndex $index,
        private LoggerInterface $logger,
    ) {
    }

    public function getName()
    {
        return 'groupfolders';
    }

    public function getChildren()
    {
        $children = [];
        foreach ($this->folders() as $id => $folder) {
            $root = $this->resolve($id, $folder['rootId']);
            if ($root !== null) {
                $children[] = new Directory($root, null, (string)$id);
            }
        }
        return $children;
    }

    public function getChild($name)
    {
        $id = ctype_digit($name) ? (int)$name : -1;
        $folder = (string)$id === $name ? ($this->folders()[$id] ?? null) : null;
        $root = $folder === null ? null : $this->resolve($id, $folder['rootId']);
        if ($root === null) {
            throw new NotFound('Not found');
        }
        return new Directory($root, null, $name);
    }

    /** @return array<int, array{rootId: int, acl: bool}> */
    private function folders(): array
    {
        try {
            return $this->index->folders();
        } catch (\RuntimeException $e) {
            throw new ServiceUnavailable('Group folders unavailable', 0, $e);
        }
    }

    /** Null when no member's storage was ever set up: Nextcloud cannot locate the folder yet. */
    private function resolve(int $id, int $rootId): ?Folder
    {
        try {
            $node = $this->rootFolder->getFirstNodeById($rootId);
        } catch (\Exception $e) {
            throw new ServiceUnavailable('Storage unavailable', 0, $e);
        }
        if (!$node instanceof Folder) {
            $this->logger->warning(
                'BackupDAV cannot reach group folder: no member has opened it yet',
                ['app' => Credentials::APP_ID, 'groupFolderId' => $id],
            );
            return null;
        }
        return $node;
    }
}
