<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Dav;

use OCP\Files\IRootFolder;
use Sabre\DAV\Collection;
use Sabre\DAV\Exception\ServiceUnavailable;

/**
 * users/<uid>/: the user's home, set up only when entered so that listing users/ never
 * triggers a filesystem setup per user. No mtime for the same reason.
 */
class UserCollection extends Collection
{
    private ?Directory $home = null;

    public function __construct(
        private IRootFolder $rootFolder,
        private string $uid,
    ) {
    }

    public function getName()
    {
        return $this->uid;
    }

    public function getChildren()
    {
        return $this->home()->getChildren();
    }

    public function getChild($name)
    {
        return $this->home()->getChild($name);
    }

    private function home(): Directory
    {
        try {
            return $this->home ??= new Directory($this->rootFolder->getUserFolder($this->uid));
        } catch (\Exception $e) {
            throw new ServiceUnavailable('User storage unavailable', 0, $e);
        }
    }
}
