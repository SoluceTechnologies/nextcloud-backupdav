<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Dav;

use OCP\Files\Cache\ICacheEntry;
use OCP\Files\Storage\IStorage;
use Sabre\DAV\Collection;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\ServiceUnavailable;

/**
 * Read-only directory of a group folder, listed from the folder storage's file cache. No user view
 * is involved, so advanced permissions (ACL) do not hide anything from the backup.
 * Sabre's Collection base rejects createFile, createDirectory, delete and setName (403).
 */
class StorageDirectory extends Collection
{
    /**
     * @param string $path relative to the folder root, '' for the root itself
     * @param ?string $name overrides the name derived from $path (folder roots are exposed by id)
     */
    public function __construct(
        private IStorage $storage,
        private string $path,
        private ?string $name = null,
        private ?ICacheEntry $entry = null,
    ) {
    }

    public function getName()
    {
        return $this->name ?? basename($this->path);
    }

    public function getLastModified()
    {
        return $this->entry?->getMTime();
    }

    public function getChildren()
    {
        try {
            $entries = $this->storage->getCache()->getFolderContents($this->path);
        } catch (\Exception $e) {
            throw new ServiceUnavailable('Storage unavailable', 0, $e);
        }
        return array_map($this->wrap(...), $entries);
    }

    public function getChild($name)
    {
        if (!Directory::isValidName($name)) {
            throw new NotFound('Not found');
        }
        try {
            $entry = $this->storage->getCache()->get($this->path === '' ? $name : $this->path . '/' . $name);
        } catch (\Exception $e) {
            throw new ServiceUnavailable('Storage unavailable', 0, $e);
        }
        if (!$entry instanceof ICacheEntry) {
            throw new NotFound('Not found');
        }
        return $this->wrap($entry);
    }

    private function wrap(ICacheEntry $entry): self|StorageFile
    {
        return $entry->getMimeType() === ICacheEntry::DIRECTORY_MIMETYPE
            ? new self($this->storage, $entry->getPath(), $entry->getName(), $entry)
            : new StorageFile($this->storage, $entry);
    }
}
