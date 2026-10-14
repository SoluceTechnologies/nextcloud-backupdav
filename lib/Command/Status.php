<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Command;

use OCA\BackupDAV\Credentials;
use OCA\BackupDAV\GroupFolderIndex;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\ServerVersion;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Status extends Command
{
    public function __construct(
        private Credentials $credentials,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
        private GroupFolderIndex $groupFolders,
        private IAppManager $appManager,
        private ServerVersion $serverVersion,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('backupdav:status')
            ->setDescription('Show the BackupDAV endpoint and service identity state (never the secret)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $credentials = $this->credentials;
        $state = match (true) {
            $credentials->isLive() => 'live',
            !$credentials->hasSecret() => 'off (no secret)',
            default => 'off (disabled)',
        };
        $ips = $credentials->allowedIps();
        (new Table($output))
            ->setHeaders(['Item', 'Value'])
            ->setRows([
                ['Endpoint', $state],
                ['URL', $this->urlGenerator->getAbsoluteURL('/remote.php/backupdav/')],
                ['Service user', Credentials::USERNAME],
                ['Secret', $credentials->hasSecret() ? 'set on ' . date(DATE_ATOM, $credentials->createdAt()) : 'none'],
                ['Allowed IPs', $ips === [] ? 'any' : implode(', ', $ips)],
                ['Exportable users', (string)$this->userManager->countSeenUsers()],
                ['Group folders', $this->groupFolderSummary()],
                ['BackupDAV version', $this->appManager->getAppVersion(Credentials::APP_ID)],
                ['Nextcloud version', $this->serverVersion->getVersionString()],
            ])
            ->render();
        return self::SUCCESS;
    }

    private function groupFolderSummary(): string
    {
        try {
            return (string)count($this->groupFolders->ids());
        } catch (\RuntimeException $e) {
            return 'error: ' . $e->getMessage();
        }
    }
}
