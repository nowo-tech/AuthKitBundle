<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function strlen;

/**
 * Orchestrates password reset requests without revealing whether the identifier exists.
 */
final class PasswordResetRequestHandler
{
    public function __construct(
        private readonly PasswordResetUserResolver $userResolver,
        private readonly PasswordResetTokenManagerInterface $tokenManager,
        private readonly PasswordResetNotifierInterface $notifier,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ProfileRegistry $profileRegistry,
        private readonly AuthKitAttemptLimiter $attemptLimiter,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function handle(string $identifier, ?string $profileName = null): void
    {
        $profile = $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();

        $limit  = (int) ($profile->passwordReset['request_rate_limit'] ?? 5);
        $window = (int) ($profile->passwordReset['request_rate_window'] ?? 900);
        $client = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
        $key    = 'reset_request:' . $profile->name . ':' . $client;

        if (!$this->attemptLimiter->consume($key, $limit, $window)) {
            return;
        }

        $user = $this->userResolver->findByIdentifier($identifier, $profile->name);

        if ($user === null) {
            return;
        }

        $tokenResult = $this->tokenManager->createForUser($user);
        $delivery    = PasswordResetDeliveryMode::from($profile->passwordReset['delivery']);

        $linkToken = $tokenResult->linkToken();
        $resetUrl  = $linkToken !== null
            ? $this->urlGenerator->generate(
                $profile->routes['reset_password']['name'],
                ['token' => $linkToken],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            : $this->urlGenerator->generate(
                $profile->routes['reset_password_code']['name'],
                referenceType: UrlGeneratorInterface::ABSOLUTE_URL,
            );

        $context = new PasswordResetNotificationContext(
            identifier: $identifier,
            resetUrl: $resetUrl,
            deliveryMode: $delivery,
            maskedIdentifier: $this->maskIdentifier($identifier),
        );

        $this->eventDispatcher->dispatch(new PasswordResetRequestedEvent($tokenResult, $context));
        $this->notifier->notify($tokenResult, $context);
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
