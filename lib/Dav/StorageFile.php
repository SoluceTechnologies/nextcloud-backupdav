<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Dav;

use OCP\Files\Cache\ICacheEntry;
use OCP\Files\Storage\IStorage;
use Sabre\DAV\Exception\ServiceUnavailable;
use Sabre\DAV\File as DavFile;

/**
 * Read-only file of a group folder, read straight from the folder's storage (see GroupFolderIndex).
 * Metadata comes from the storage's file cache. Sabre's base class rejects put, delete and setName (403).
 */
class StorageFile extends DavFile
{
    public function __construct(
        private IStorage $storage,
        private ICacheEntry $entry,
    ) {
    }

    public function getName()
    {
        return $this->entry->getName();
    }

    /** @return resource */
    public function get()
    {
        try {
            $stream = $this->storage->fopen($this->entry->getPath(), 'r');
        } catch (\Exception $e) {
            throw new ServiceUnavailable('Storage unavailable', 0, $e);
        }
        if (!is_resource($stream)) {
            throw new ServiceUnavailable('Storage unavailable');
        }
        return $stream;
    }

    public function getSize()
    {
        return $this->entry->getSize();
    }

    public function getLastModified()
    {
        return $this->entry->getMTime();
    }

    /** A change marker from the file cache, not a content checksum. */
    public function getETag()
    {
        return '"' . $this->entry->getEtag() . '"';
    }

    public function getContentType()
    {
        return $this->entry->getMimeType();
    }
}
