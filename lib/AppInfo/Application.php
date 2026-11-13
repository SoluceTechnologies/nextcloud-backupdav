<?php

declare(strict_types=1);

namespace OCA\BackupDAV\AppInfo;

use OCA\BackupDAV\Capabilities;
use OCA\BackupDAV\Credentials;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap
{
    public function __construct()
    {
        parent::__construct(Credentials::APP_ID);
    }

    public function register(IRegistrationContext $context): void
    {
        $context->registerCapability(Capabilities::class);
    }

    public function boot(IBootContext $context): void
    {
    }
}
