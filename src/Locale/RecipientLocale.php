<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Locale;

use Symfony\Component\Translation\LocaleSwitcher;

use function in_array;
use function is_string;

/**
 * Resolves a notification recipient's locale and runs notifier code in it.
 *
 * The resolved locale is only used when `nowo_auth_kit.recipient_locale.enabled` is true and the
 * value is one of `nowo_auth_kit.locale.enabled`. {@see run()} relies on Symfony's LocaleSwitcher,
 * which restores the translator / router / ICU locale in a finally block (worker-safe).
 */
final class RecipientLocale
{
    /**
     * @param list<string> $allowedLocales
     */
    public function __construct(
        private readonly RecipientLocaleResolverInterface $resolver = new PreferredLocaleAwareRecipientLocaleResolver(),
        private readonly ?LocaleSwitcher $localeSwitcher = null,
        private readonly bool $enabled = false,
        private readonly array $allowedLocales = [],
    ) {
    }

    public function resolve(object $user): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $locale = $this->resolver->resolve($user);
        if (!is_string($locale) || !in_array($locale, $this->allowedLocales, true)) {
            return null;
        }

        return $locale;
    }

    /**
     * Calls $callback(...$arguments) with the translator, router context and ICU default locale switched
     * to $locale (restored afterwards, also on exceptions). Without a locale or a LocaleSwitcher the
     * callback runs as-is.
     *
     * @return mixed the callback's return value
     */
    public function run(?string $locale, callable $callback, mixed ...$arguments): mixed
    {
        if ($locale === null || !$this->localeSwitcher instanceof LocaleSwitcher) {
            return $callback(...$arguments);
        }

        return $this->localeSwitcher->runWithLocale($locale, static fn (): mixed => $callback(...$arguments));
    }
}
