<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Twig;

use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

use function is_string;

/**
 * `flash_trans` Twig filter: translates a plain-string flash key using the first domain of
 * `nowo_auth_kit.flash.twig_filter_domains` whose catalogue (current locale, with fallbacks) has the key.
 *
 * Keys found in no domain go through `messages` (already-translated text is returned unchanged).
 * {@see TranslatableInterface} flashes (e.g. `TranslatableMessage`) are translated with their own domain and
 * parameters. Other non-string flashes (structured arrays) are returned untouched. Stateless (worker-safe): the catalogue
 * cache lives in the translator, which is reset per locale by Symfony.
 */
final class AuthKitFlashExtension extends AbstractExtension
{
    /**
     * @param list<string> $domains
     */
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly array $domains = ['messages', 'NowoAuthKitBundle'],
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('flash_trans', $this->translate(...)),
        ];
    }

    /**
     * @param array<string, mixed> $parameters ignored for {@see TranslatableInterface} flashes (they carry their own)
     * @param string|null $locale null = the translator's current locale
     */
    public function translate(mixed $message, array $parameters = [], ?string $locale = null): mixed
    {
        if ($message instanceof TranslatableInterface) {
            return $message->trans($this->translator, $locale);
        }

        if (!is_string($message) || $message === '') {
            return $message;
        }

        return $this->translator->trans($message, $parameters, $this->domainFor($message, $locale), $locale);
    }

    private function domainFor(string $message, ?string $locale): string
    {
        if (!$this->translator instanceof TranslatorBagInterface) {
            return 'messages';
        }

        $catalogue = $this->translator->getCatalogue($locale);
        foreach ($this->domains as $domain) {
            if ($catalogue->has($message, $domain)) {
                return $domain;
            }
        }

        return 'messages';
    }
}
