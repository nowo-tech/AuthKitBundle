<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Locale\RecipientLocale;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Routing\AbsoluteUrlGenerator;
use Nowo\AuthKitBundle\Security\LoginLinkHandlerLocator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkDetails;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_intersect;

/**
 * Magic links an administrator issues for another account (support sign-in): shown once to copy ({@see issue()}) or
 * e-mailed through the host's {@see MagicLoginNotifierInterface} ({@see send()}). Same signed Symfony login link as
 * the sign-in page, in the recipient's locale and against `absolute_url.base_uri`, with its own lifetime
 * (`magic_login.admin_link.lifetime`, default one hour so it survives a hand-over).
 *
 * Never for the issuer's own account nor for an account holding a protected role (`magic_login.admin_link
 * .protected_roles`, administrators by default). Every link dispatches {@see AdminMagicLinkIssuedEvent} so the host
 * audits it. The firewall `login_link.lifetime` must be at least this lifetime, or a used link can be replayed later
 * in its validity window (Symfony forgets the use once its own lifetime has passed).
 */
final class AdminMagicLinkIssuer
{
    public const DEFAULT_LIFETIME = 3600;

    /**
     * @param list<string> $protectedRoles
     */
    public function __construct(
        private readonly MagicLoginNotifierInterface $notifier,
        private readonly RequestStack $requestStack,
        private readonly ?LoginLinkHandlerInterface $loginLinkHandler = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly RecipientLocale $recipientLocale = new RecipientLocale(),
        private readonly ?AbsoluteUrlGenerator $absoluteUrlGenerator = null,
        private readonly int $lifetime = self::DEFAULT_LIFETIME,
        private readonly array $protectedRoles = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'],
        private readonly ?LoginLinkHandlerLocator $loginLinkHandlers = null,
        private readonly ?ProfileRegistry $profileRegistry = null,
    ) {
    }

    public function lifetime(): int
    {
        return $this->lifetime;
    }

    /** Whether `$issuer` may issue a link for `$target` (hosts hide the action otherwise). */
    public function canIssue(UserInterface $issuer, UserInterface $target): bool
    {
        return $this->refusal($issuer, $target) === null;
    }

    /**
     * A link to show once (never stored by the bundle).
     *
     * @throws AdminMagicLinkRefusedException
     */
    public function issue(UserInterface $issuer, UserInterface $target): LoginLinkDetails
    {
        $link = $this->create($issuer, $target, $this->recipientLocale->resolve($target));
        $this->dispatch(new AdminMagicLinkIssuedEvent($issuer, $target, AdminMagicLinkIssuedEvent::DELIVERY_SHOWN, DateTimeImmutable::createFromInterface($link->getExpiresAt())));

        return $link;
    }

    /**
     * E-mails the link to `$recipient` (default: the target's user identifier) in the target's locale.
     *
     * @throws AdminMagicLinkRefusedException
     */
    public function send(UserInterface $issuer, UserInterface $target, ?string $recipient = null): DateTimeImmutable
    {
        $locale  = $this->recipientLocale->resolve($target);
        $link    = $this->create($issuer, $target, $locale);
        $expires = DateTimeImmutable::createFromInterface($link->getExpiresAt());
        $context = new MagicLoginNotificationContext(
            identifier: $recipient ?? $target->getUserIdentifier(),
            loginUrl: $link->getUrl(),
            expiresAt: $expires,
            recipientLocale: $locale,
            user: $target,
        );
        $this->recipientLocale->run($locale, $this->notifier->notify(...), $context);
        $this->dispatch(new AdminMagicLinkIssuedEvent($issuer, $target, AdminMagicLinkIssuedEvent::DELIVERY_EMAIL, $expires));

        return $expires;
    }

    private function create(UserInterface $issuer, UserInterface $target, ?string $locale): LoginLinkDetails
    {
        $refusal = $this->refusal($issuer, $target);
        if ($refusal !== null) {
            throw new AdminMagicLinkRefusedException($refusal);
        }
        $loginLinkHandler = $this->handlerFor($target);
        if (!$loginLinkHandler instanceof LoginLinkHandlerInterface) {
            throw new AdminMagicLinkRefusedException(AdminMagicLinkRefusedException::NOT_CONFIGURED);
        }

        return (new MagicLinkFactory($this->absoluteUrlGenerator))->create(
            $loginLinkHandler,
            $target,
            $this->requestStack->getCurrentRequest(),
            $locale,
            $this->lifetime,
        );
    }

    /** SEC-004 L2: the login link handler of the firewall of the target's profile. */
    private function handlerFor(UserInterface $target): ?LoginLinkHandlerInterface
    {
        if (!$this->loginLinkHandlers instanceof LoginLinkHandlerLocator) {
            return $this->loginLinkHandler;
        }

        $firewall = $this->profileRegistry?->resolveForObject($target)?->firewall;

        return $firewall !== null ? $this->loginLinkHandlers->get($firewall) : null;
    }

    private function refusal(UserInterface $issuer, UserInterface $target): ?string
    {
        if ($issuer->getUserIdentifier() === $target->getUserIdentifier()) {
            return AdminMagicLinkRefusedException::OWN_ACCOUNT;
        }
        if (array_intersect($target->getRoles(), $this->protectedRoles) !== []) {
            return AdminMagicLinkRefusedException::PROTECTED_ROLE;
        }

        return null;
    }

    private function dispatch(AdminMagicLinkIssuedEvent $event): void
    {
        $this->eventDispatcher?->dispatch($event);
    }
}
