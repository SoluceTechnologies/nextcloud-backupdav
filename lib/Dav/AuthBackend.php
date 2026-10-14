<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Dav;

use OCA\BackupDAV\Credentials;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Bruteforce\MaxDelayReached;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Auth\Backend\AbstractBasic;
use Sabre\DAV\Exception as DavException;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/** HTTP Basic for the backup-service identity, behind Nextcloud's brute-force throttler. */
class AuthBackend extends AbstractBasic
{
    private const THROTTLE_ACTION = 'backupdav';

    public function __construct(
        private Credentials $credentials,
        private IThrottler $throttler,
        private IRequest $request,
        private LoggerInterface $logger,
    ) {
        $this->setRealm('BackupDAV');
    }

    public function check(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $this->throttler->sleepDelayOrThrowOnMax($this->request->getRemoteAddress(), self::THROTTLE_ACTION);
        } catch (MaxDelayReached) {
            throw new class('Too many failed attempts') extends DavException {
                public function getHTTPCode()
                {
                    return 429;
                }
            };
        }
        return parent::check($request, $response);
    }

    protected function validateUserPass($username, $password)
    {
        if ($this->credentials->verify((string)$username, (string)$password)) {
            return true;
        }
        $ip = $this->request->getRemoteAddress();
        $this->throttler->registerAttempt(self::THROTTLE_ACTION, $ip);
        // Never log the submitted username or password: either may contain the secret.
        $this->logger->warning('BackupDAV authentication failed', ['app' => Credentials::APP_ID, 'ip' => $ip]);
        return false;
    }
}
