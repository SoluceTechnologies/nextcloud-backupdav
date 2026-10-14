<?php

declare(strict_types=1);

use OCA\BackupDAV\Credentials;
use OCA\BackupDAV\ServerFactory;
use OCP\Server;

// Registered by <remote> in info.xml: core remote.php sets $baseuri and requires this file.

// Large downloads: no PHP time limit.
if (!str_contains((string)@ini_get('disable_functions'), 'set_time_limit')) {
    @set_time_limit(0);
}
// ignore_user_abort stays off: a client that disconnects stops the transfer and its storage read.

// Stream responses directly.
while (ob_get_level()) {
    ob_end_clean();
}

if (!Server::get(Credentials::class)->isLive()) {
    // Disabled, or no secret: behave as if the endpoint did not exist.
    http_response_code(404);
    return;
}

/** @var string $baseuri */
Server::get(ServerFactory::class)->create($baseuri)->start();
