<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function is_int;
use function strlen;

/**
 * Orchestrates magic-login requests without revealing whether the identifier exists.
 *
 * Requires Symfony firewall `login_link` (see configure-security) so LoginLinkHandlerInterface is available.
 */
final class MagicLoginRequestHandler
{
    public function __construct(
        private readonly MagicLoginUserResolver $userResolver,
        private readonly MagicLoginNotifierInterface $notifier,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ProfileRegistry $profileRegistry,
        private readonly RequestStack $requestStack,
        private readonly AuthKitAttemptLimiter $attemptLimiter,
        private readonly ?LoginLinkHandlerInterface $loginLinkHandler = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function handle(string $identifier, ?string $profileName = null): void
    {
        $profile = $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();

        $limit  = (int) ($profile->magicLogin['request_rate_limit'] ?? 5);
        $window = (int) ($profile->magicLogin['request_rate_window'] ?? 900);
        $client = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
        $key    = 'magic_request:' . $profile->name . ':' . $client;

        if (!$this->attemptLimiter->consume($key, $limit, $window)) {
            return;
        }

        $user = $this->userResolver->findByIdentifier($identifier, $profile->name);

        if ($user === null) {
            return;
        }

        if (!$user instanceof UserInterface) {
            return;
        }

        if (!$this->loginLinkHandler instanceof LoginLinkHandlerInterface) {
            // Avoid user-existence oracle (500 vs 302) when login_link is misconfigured.
            $this->logger->warning('Magic login skipped: firewall login_link is not configured.');

            return;
        }

        $lifetime = is_int($profile->magicLogin['lifetime'] ?? null)
            ? $profile->magicLogin['lifetime']
            : null;

        $request = $this->requestStack->getCurrentRequest();
        $details = $this->loginLinkHandler->createLoginLink($user, $request instanceof Request ? $request : null, $lifetime);
        $expires = DateTimeImmutable::createFromInterface($details->getExpiresAt());

        $context = new MagicLoginNotificationContext(
            identifier: $identifier,
            loginUrl: $details->getUrl(),
            expiresAt: $expires,
            maskedIdentifier: $this->maskIdentifier($identifier),
        );

        $this->eventDispatcher->dispatch(new MagicLoginRequestedEvent($context));
        $this->notifier->notify($context);
    }

    private function maskIdentifier(string $identifier): string
    {
        if (str_contains($identifier, '@')) {
            [$local, $domain] = explode('@', $identifier, 2);

            return substr($local, 0, 1) . '***@' . $domain;
        }

        if (strlen($identifier) <= 2) {
            return '***';
        }

        return substr($identifier, 0, 1) . str_repeat('*', max(1, strlen($identifier) - 2)) . substr($identifier, -1);
    }
}
