<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Command;

use OCA\BackupDAV\Credentials;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Disable extends Command
{
    public function __construct(private Credentials $credentials)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('backupdav:disable')
            ->setDescription('Turn the endpoint off; within seconds it answers 404');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->credentials->disable();
        $output->writeln('BackupDAV endpoint disabled.');
        return self::SUCCESS;
    }
}
