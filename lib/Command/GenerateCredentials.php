<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Command;

use OCA\BackupDAV\Credentials;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateCredentials extends Command
{
    public function __construct(protected Credentials $credentials)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('backupdav:credentials:generate')
            ->setDescription('Create the backup-service secret; it is printed once and only its hash is stored');
    }

    /** @throws \RuntimeException */
    protected function newSecret(): string
    {
        return $this->credentials->generate();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $secret = $this->newSecret();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
        $output->writeln('User: ' . Credentials::USERNAME);
        $output->writeln('Secret: ' . $secret);
        $output->writeln('Store it now: it cannot be shown again. rclone expects it obscured (rclone obscure).');
        if (!$this->credentials->isEnabled()) {
            $output->writeln('The endpoint is disabled. Enable it with: occ backupdav:enable');
        }
        return self::SUCCESS;
    }
}
