<?php

declare(strict_types=1);

namespace OCA\BackupDAV;

use OCP\App\IAppManager;
use OCP\Encryption\IManager;
use OCP\Files\Storage\IStorage;
use Psr\Container\ContainerInterface;

/**
 * Gives access to group folders through the groupfolders app's FolderManager and FolderStorageManager:
 * Nextcloud has no public API for them. These two classes are the app's only dependency on another
 * app's internals (verified with groupfolders 23.0.1). A folder's storage is opened without a user,
 * the way groupfolders itself opens trash and versions, so advanced permissions (ACL) do not apply.
 */
class GroupFolderIndex
{
    private const FOLDER_MANAGER = 'OCA\GroupFolders\Folder\FolderManager';
    private const STORAGE_MANAGER = 'OCA\GroupFolders\Mount\FolderStorageManager';

    /** @var ?array<int, object> folder definitions by id, loaded once per request */
    private ?array $folders = null;

    public function __construct(
        private IAppManager $appManager,
        private IManager $encryptionManager,
        private ContainerInterface $container,
    ) {
    }

    /**
     * @return list<int> group folder ids; empty when groupfolders is not enabled
     * @throws \RuntimeException when the groupfolders API no longer matches: never a silently empty export
     */
    public function ids(): array
    {
        return array_keys($this->folders());
    }

    /**
     * Storage holding the folder's files (trash and versions excluded), or null for an unknown id.
     *
     * @throws \RuntimeException when the groupfolders API no longer matches
     */
    public function storage(int $id): ?IStorage
    {
        $folder = $this->folders()[$id] ?? null;
        if ($folder === null) {
            return null;
        }
        try {
            return $this->container->get(self::STORAGE_MANAGER)
                ->getBaseStorageForFolder($folder->id, $folder->useSeparateStorage(), $folder, null);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Cannot open group folder storage: the groupfolders app API changed', 0, $e);
        }
    }

    /** @return array<int, object> */
    private function folders(): array
    {
        if ($this->folders !== null) {
            return $this->folders;
        }
        if (!$this->appManager->isEnabledForAnyone('groupfolders')) {
            return $this->folders = [];
        }
        try {
            $folders = [];
            foreach ($this->container->get(self::FOLDER_MANAGER)->getAllFolders() as $folder) {
                $folders[(int)$folder->id] = $folder;
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Cannot list group folders: the groupfolders app API changed', 0, $e);
        }
        if ($folders !== [] && $this->encryptionManager->isEnabled()) {
            // Raw storage bypasses the encryption wrapper: the export would contain encrypted bytes.
            throw new \RuntimeException('Group folders cannot be exported while server-side encryption is enabled');
        }
        return $this->folders = $folders;
    }
}
