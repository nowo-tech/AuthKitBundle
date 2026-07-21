<?php

declare(strict_types=1);

namespace App\Security;

use DateTimeImmutable;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use Symfony\Component\HttpFoundation\RequestStack;

use const DATE_ATOM;

/**
 * Demo-only outbox: stores the last password-reset / magic-login deliveries in session
 * so flows can be tried without a real mailer (links stay clickable in the UI).
 */
final class DemoDeliveryInbox
{
    private const SESSION_KEY = '_demo_auth_deliveries';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function rememberPasswordReset(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
    {
        $this->push('password_reset', [
            'type'              => 'password_reset',
            'reset_url'         => $context->resetUrl,
            'code'              => $token->code(),
            'link_token'        => $token->linkToken(),
            'masked_identifier' => $context->maskedIdentifier ?? $context->identifier,
            'delivery'          => $context->deliveryMode->value,
        ]);
    }

    public function rememberMagicLogin(MagicLoginNotificationContext $context): void
    {
        $this->push('magic_login', [
            'type'              => 'magic_login',
            'login_url'         => $context->loginUrl,
            'expires_at'        => $context->expiresAt->format(DATE_ATOM),
            'masked_identifier' => $context->maskedIdentifier ?? $context->identifier,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return [];
        }

        /** @var list<array<string, mixed>> $items */
        $items = $request->getSession()->get(self::SESSION_KEY, []);

        return $items;
    }

    public function clear(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return;
        }

        $request->getSession()->remove(self::SESSION_KEY);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function push(string $key, array $payload): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        /** @var array<string, array<string, mixed>> $byType */
        $byType       = $session->get(self::SESSION_KEY . '_map', []);
        $byType[$key] = $payload + ['at' => (new DateTimeImmutable())->format(DATE_ATOM)];
        $session->set(self::SESSION_KEY . '_map', $byType);
        $session->set(self::SESSION_KEY, array_values($byType));
    }
}
