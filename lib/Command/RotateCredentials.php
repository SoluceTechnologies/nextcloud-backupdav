<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Command;

class RotateCredentials extends GenerateCredentials
{
    protected function configure(): void
    {
        $this->setName('backupdav:credentials:rotate')
            ->setDescription('Replace the backup-service secret; the old one stops working immediately');
    }

    protected function newSecret(): string
    {
        return $this->credentials->rotate();
    }
}
