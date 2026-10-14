<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Command;

use OCA\BackupDAV\Credentials;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RevokeCredentials extends Command
{
    public function __construct(private Credentials $credentials)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('backupdav:credentials:revoke')
            ->setDescription('Delete the backup-service secret; the endpoint answers 404 from the next request');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->credentials->revoke();
        $output->writeln('Secret revoked. Run occ backupdav:credentials:generate to create a new one.');
        return self::SUCCESS;
    }
}
