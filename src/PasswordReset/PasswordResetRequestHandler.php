<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function strlen;

/**
 * Orchestrates password reset requests without revealing whether the identifier exists.
 */
final class PasswordResetRequestHandler
{
    /**
     * @param array<string, array{path: string, name: string}> $routes
     */
    public function __construct(
        private readonly PasswordResetUserResolver $userResolver,
        private readonly PasswordResetTokenManagerInterface $tokenManager,
        private readonly PasswordResetNotifierInterface $notifier,
        private readonly AuthKitUrlGenerator $urlGenerator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly array $routes,
        private readonly string $deliveryMode,
    ) {
    }

    public function handle(string $identifier): void
    {
        $user = $this->userResolver->findByIdentifier($identifier);

        if ($user === null) {
            return;
        }

        $tokenResult = $this->tokenManager->createForUser($user);
        $delivery    = PasswordResetDeliveryMode::from($this->deliveryMode);

        $linkToken = $tokenResult->linkToken();
        $resetUrl  = $linkToken !== null
            ? $this->urlGenerator->generate(
                $this->routes['reset_password']['name'],
                ['token' => $linkToken],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            : $this->urlGenerator->generate(
                $this->routes['reset_password_code']['name'],
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
