<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Command;

use OCA\BackupDAV\Credentials;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Enable extends Command
{
    public function __construct(private Credentials $credentials)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('backupdav:enable')
            ->setDescription('Turn the endpoint on (requires a secret)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->credentials->enable();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
        $output->writeln('BackupDAV endpoint enabled.');
        return self::SUCCESS;
    }
}
