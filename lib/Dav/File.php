<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Dav;

use OCP\Files\File as NcFile;
use OCP\Files\NotFoundException;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\ServiceUnavailable;
use Sabre\DAV\File as DavFile;

/**
 * Read-only file. Metadata comes from the file cache; content is streamed by Sabre from the
 * storage stream, never loaded in memory. Sabre's base class rejects put, delete and setName (403).
 */
class File extends DavFile
{
    public function __construct(private NcFile $file)
    {
    }

    public function getName()
    {
        return $this->file->getName();
    }

    /** @return resource */
    public function get()
    {
        try {
            $stream = $this->file->fopen('r');
        } catch (NotFoundException $e) {
            throw new NotFound('Not found', 0, $e);
        } catch (\Exception $e) {
            // Locked by a writer, storage unavailable, S3 error: 503 so rclone retries.
            throw new ServiceUnavailable('Storage unavailable', 0, $e);
        }
        if (!is_resource($stream)) {
            throw new ServiceUnavailable('Storage unavailable');
        }
        return $stream;
    }

    public function getSize()
    {
        return $this->file->getSize();
    }

    public function getLastModified()
    {
        return $this->file->getMTime();
    }

    /** A change marker from the file cache, not a content checksum. */
    public function getETag()
    {
        return '"' . $this->file->getEtag() . '"';
    }

    public function getContentType()
    {
        return $this->file->getMimetype();
    }
}
