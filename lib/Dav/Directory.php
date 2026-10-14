<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Dav;

use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use Sabre\DAV\Collection;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\ServiceUnavailable;

/**
 * Read-only directory of an export root (a home or a group folder) or of one of its subfolders.
 *
 * Only children on the export root's own mount are visible, in listings and on direct access:
 * incoming shares, group folder mounts and external storages inside a home are skipped (shares
 * are exported under their owner, group folders under /groupfolders/<id>/).
 * Sabre's Collection base rejects createFile, createDirectory, delete and setName (403).
 */
class Directory extends Collection
{
    private string $exportMount;

    /** @param ?string $name overrides the folder name (group folder roots are exposed by id) */
    public function __construct(
        private Folder $folder,
        ?string $exportMount = null,
        private ?string $name = null,
    ) {
        $this->exportMount = $exportMount ?? $folder->getMountPoint()->getMountPoint();
    }

    /** Rejects names that could address anything other than a direct child. */
    public static function isValidName(string $name): bool
    {
        return $name !== '' && $name !== '.' && $name !== '..' && strpbrk($name, "/\\\0") === false;
    }

    public function getName()
    {
        return $this->name ?? $this->folder->getName();
    }

    public function getLastModified()
    {
        return $this->folder->getMTime();
    }

    public function getChildren()
    {
        try {
            $nodes = $this->folder->getDirectoryListing();
        } catch (NotFoundException $e) {
            throw new NotFound('Not found', 0, $e);
        } catch (\Exception $e) {
            throw new ServiceUnavailable('Storage unavailable', 0, $e);
        }
        $children = [];
        foreach ($nodes as $node) {
            if ($this->isExported($node)) {
                $children[] = $this->wrap($node);
            }
        }
        return $children;
    }

    public function getChild($name)
    {
        if (!self::isValidName($name)) {
            throw new NotFound('Not found');
        }
        try {
            $node = $this->folder->get($name);
        } catch (NotFoundException|NotPermittedException $e) {
            throw new NotFound('Not found', 0, $e);
        } catch (\Exception $e) {
            throw new ServiceUnavailable('Storage unavailable', 0, $e);
        }
        if (!$this->isExported($node)) {
            throw new NotFound('Not found');
        }
        return $this->wrap($node);
    }

    private function isExported(Node $node): bool
    {
        return $node->getMountPoint()->getMountPoint() === $this->exportMount;
    }

    private function wrap(Node $node): self|File
    {
        return $node instanceof Folder ? new self($node, $this->exportMount) : new File($node);
    }
}
