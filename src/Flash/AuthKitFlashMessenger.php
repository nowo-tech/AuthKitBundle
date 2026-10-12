<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Flash;

use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adds AuthKit flash messages to the session.
 *
 * With `nowo_auth_kit.flash.translate: true` (default) the flash holds the text already translated
 * from the NowoAuthKitBundle domain in the request locale, so any host flash template works
 * (`{{ message }}`, `{{ message|trans }}` or UiKit's toast partial). With `false` the raw
 * translation key is stored (pre-1.23 behaviour). Stateless (worker-safe).
 */
final class AuthKitFlashMessenger
{
    public function __construct(
        private readonly ?TranslatorInterface $translator = null,
        private readonly bool $translate = true,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters translation parameters
     */
    public function flash(Request $request, string $type, string $key, array $parameters = []): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $session->getFlashBag()->add($type, $this->message($request, $key, $parameters));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function message(Request $request, string $key, array $parameters = []): string
    {
        if (!$this->translate || !$this->translator instanceof TranslatorInterface) {
            return $key;
        }

        return $this->translator->trans($key, $parameters, NowoAuthKitBundle::TRANSLATION_DOMAIN, $request->getLocale());
    }
}
