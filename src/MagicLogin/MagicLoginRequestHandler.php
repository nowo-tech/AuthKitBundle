<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

use DateTimeImmutable;
use LogicException;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
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
        private readonly ?LoginLinkHandlerInterface $loginLinkHandler = null,
    ) {
    }

    public function handle(string $identifier, ?string $profileName = null): void
    {
        $profile = $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();

        $user = $this->userResolver->findByIdentifier($identifier, $profile->name);

        if ($user === null) {
            return;
        }

        if (!$user instanceof UserInterface) {
            return;
        }

        if ($this->loginLinkHandler === null) {
            throw new LogicException('Magic login requires Symfony firewall "login_link". Run: php bin/console nowo:auth-kit:configure-security');
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
