<?php

declare(strict_types=1);

namespace OCA\BackupDAV;

use OCP\App\IAppManager;
use OCP\Server;

/**
 * Lists group folders through the groupfolders app's FolderManager: Nextcloud has no public API
 * for it. This class is the app's only dependency on another app's internals (verified with
 * groupfolders 23.0.1). Folder contents are read through OCP, see GroupFoldersCollection.
 */
class GroupFolderIndex
{
    private const FOLDER_MANAGER = 'OCA\GroupFolders\Folder\FolderManager';

    public function __construct(private IAppManager $appManager)
    {
    }

    /**
     * @return array<int, array{rootId: int, acl: bool}> keyed by group folder id; empty when groupfolders is not enabled
     * @throws \RuntimeException when the groupfolders API no longer matches: never a silently empty export
     */
    public function folders(): array
    {
        if (!$this->appManager->isEnabledForAnyone('groupfolders')) {
            return [];
        }
        try {
            $folders = [];
            foreach (Server::get(self::FOLDER_MANAGER)->getAllFolders() as $folder) {
                $folders[(int)$folder->id] = ['rootId' => (int)$folder->rootId, 'acl' => (bool)$folder->acl];
            }
            return $folders;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Cannot list group folders: the groupfolders app API changed', 0, $e);
        }
    }
}
