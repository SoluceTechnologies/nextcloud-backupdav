<?php

declare(strict_types=1);

namespace OCA\BackupDAV;

use OCP\Capabilities\ICapability;

/**
 * +
 */
class Capabilities implements ICapability
{
    public function getCapabilities(): array
    {
        return ['checksums' => ['supportedTypes' => ['MD5'], 'preferredUploadType' => 'MD5']];
    }
}
