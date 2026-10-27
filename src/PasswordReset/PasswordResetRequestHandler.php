<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use Nowo\AuthKitBundle\DeviceIntelligence\DeviceIntelligenceContext;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\Locale\RecipientLocale;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Routing\AuthKitUrlGenerator;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
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
        private readonly DeviceIntelligenceContext $deviceIntelligence = new DeviceIntelligenceContext(),
        private readonly RecipientLocale $recipientLocale = new RecipientLocale(),
        private readonly UserIdentifierMatcher $identifierMatcher = new UserIdentifierMatcher(),
    ) {
    }

    /**
     * Returns the outcome (throttled or not); callers that ignore it keep the pre-1.30 behaviour.
     */
    public function handle(string $identifier, ?string $profileName = null): PasswordResetRequestOutcome
    {
        $profile = $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();

        $limit   = (int) ($profile->passwordReset['request_rate_limit'] ?? 5);
        $window  = (int) ($profile->passwordReset['request_rate_window'] ?? 900);
        $request = $this->requestStack->getCurrentRequest();
        $client  = $request?->getClientIp() ?? 'unknown';
        $key     = 'reset_request:' . $profile->name . ':' . $client;

        if (!$this->attemptLimiter->consume($key, $limit, $window)
            || !$this->deviceIntelligence->consumeDeviceRateLimit(
                $request,
                $this->attemptLimiter,
                $profile->deviceIntelligence,
                'reset_request',
                $profile->name,
                $limit,
                $window,
            )
        ) {
            return PasswordResetRequestOutcome::accepted();
        }

        $user = $this->userResolver->findByIdentifier($identifier, $profile->name);

        if ($user === null) {
            return PasswordResetRequestOutcome::accepted();
        }

        // SEC-004 H1: notifiers get the identifier stored on the account, never the typed input (the resolver only
        // returns an account whose stored identifier is readable and equals the input).
        $recipient = (string) $this->identifierMatcher->storedIdentifier($user, $profile->userIdentifierField);

        try {
            $tokenResult = $this->tokenManager->createForUser($user);
        } catch (PasswordResetThrottledException $exception) {
            return PasswordResetRequestOutcome::throttled($exception);
        }
        $delivery = PasswordResetDeliveryMode::from($profile->passwordReset['delivery']);

        $locale    = $this->recipientLocale->resolve($user);
        $linkToken = $tokenResult->linkToken();
        $resetUrl  = $linkToken !== null
            ? $this->urlGenerator->generate(
                $profile->routes['reset_password']['name'],
                ['token' => $linkToken],
                UrlGeneratorInterface::ABSOLUTE_URL,
                $locale,
            )
            : $this->urlGenerator->generate(
                $profile->routes['reset_password_code']['name'],
                referenceType: UrlGeneratorInterface::ABSOLUTE_URL,
                locale: $locale,
            );

        $context = new PasswordResetNotificationContext(
            identifier: $recipient,
            resetUrl: $resetUrl,
            deliveryMode: $delivery,
            maskedIdentifier: $this->maskIdentifier($recipient),
            recipientLocale: $locale,
            user: $user,
        );

        $this->recipientLocale->run($locale, $this->dispatchAndNotify(...), $tokenResult, $context);

        return PasswordResetRequestOutcome::accepted();
    }

    private function dispatchAndNotify(PasswordResetTokenResult $tokenResult, PasswordResetNotificationContext $context): void
    {
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
