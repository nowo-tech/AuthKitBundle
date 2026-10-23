<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

use Nowo\AuthKitBundle\Routing\AbsoluteUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkDetails;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * Builds a Symfony login link in the recipient's locale, shared by the sign-in page ({@see MagicLoginRequestHandler})
 * and administrator-issued links ({@see AdminMagicLinkIssuer}).
 *
 * With `absolute_url.base_uri` configured the link is built against that origin: no request is handed to the
 * LoginLinkHandler (it would rebuild the router context from the request Host) and the router context is swapped to
 * the base for the call, keeping {_locale} (recipient locale, else the request locale).
 */
final class MagicLinkFactory
{
    public function __construct(
        private readonly ?AbsoluteUrlGenerator $absoluteUrlGenerator = null,
    ) {
    }

    public function create(
        LoginLinkHandlerInterface $handler,
        UserInterface $user,
        ?Request $request,
        ?string $locale,
        ?int $lifetime,
    ): LoginLinkDetails {
        if (!$this->absoluteUrlGenerator instanceof AbsoluteUrlGenerator || !$this->absoluteUrlGenerator->isEnabled()) {
            return $handler->createLoginLink($user, $this->linkRequest($request, $locale), $lifetime);
        }

        $linkLocale = $locale ?? $request?->getLocale();

        return $this->absoluteUrlGenerator->withBaseContext(
            static fn (): LoginLinkDetails => $handler->createLoginLink($user, null, $lifetime),
            $linkLocale !== null ? ['_locale' => $linkLocale] : [],
        );
    }

    /**
     * Symfony's LoginLinkHandler takes {_locale} from the request it is given: hand it a copy in the
     * recipient's locale so a localized check route links to /{recipient-locale}/… (the live request is untouched).
     */
    private function linkRequest(?Request $request, ?string $locale): ?Request
    {
        if (!$request instanceof Request || $locale === null) {
            return $request;
        }

        $copy = $request->duplicate();
        $copy->setLocale($locale);
        $copy->attributes->set('_locale', $locale);

        return $copy;
    }
}
