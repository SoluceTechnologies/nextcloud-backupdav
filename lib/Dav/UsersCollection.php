<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Dav;

use OCP\Files\IRootFolder;
use OCP\IUserManager;
use Sabre\DAV\Collection;
use Sabre\DAV\Exception\NotFound;

/**
 * users/: every user who logged in at least once, disabled accounts included.
 * Never-seen users are hidden: IRootFolder::getUserFolder() would create their home (a write).
 * Listed with one unpaginated lookup: getSeenUsers() pages without ORDER BY and can skip users,
 * which `rclone sync` would turn into deletions at the destination.
 */
class UsersCollection extends Collection
{
    public function __construct(
        private IUserManager $userManager,
        private IRootFolder $rootFolder,
    ) {
    }

    public function getName()
    {
        return 'users';
    }

    public function getChildren()
    {
        $children = [];
        foreach ($this->userManager->search('') as $user) {
            if ($user->getLastLogin() > 0) {
                $children[] = new UserCollection($this->rootFolder, $user->getUID());
            }
        }
        return $children;
    }

    public function getChild($name)
    {
        $user = Directory::isValidName($name) ? $this->userManager->get($name) : null;
        // Exact uid only: user backends may resolve case variants to the same account.
        if ($user === null || $user->getUID() !== $name || $user->getLastLogin() === 0) {
            throw new NotFound('Not found');
        }
        return new UserCollection($this->rootFolder, $name);
    }
}
