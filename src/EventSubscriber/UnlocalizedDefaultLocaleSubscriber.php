<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\EventSubscriber;

use Nowo\AuthKitBundle\Enum\UnlocalizedLocaleMode;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Routing\AuthKitRouteLoader;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;
use function str_ends_with;
use function strtolower;

/**
 * Forces {@code locale.default} on AuthKit {@code *_unlocalized} twins when {@code unlocalized: serve}.
 *
 * Bare {@code /login} (etc.) must render the configured default even when the compiled route
 * {@code _locale} default was warmed with a different value (image build vs runtime env).
 */
final readonly class UnlocalizedDefaultLocaleSubscriber implements EventSubscriberInterface
{
    private UnlocalizedLocaleMode $unlocalizedMode;

    public function __construct(
        private TranslatorInterface $translator,
        private string $defaultLocale,
        string $unlocalizedMode = 'redirect',
    ) {
        $this->unlocalizedMode = UnlocalizedLocaleMode::from($unlocalizedMode);
    }

    /**
     * @return array<string, list<array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // After LocaleListener (16) / LocaleAwareListener (15); before typical host sticky-session listeners.
            KernelEvents::REQUEST => [['onKernelRequest', 14]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->unlocalizedMode !== UnlocalizedLocaleMode::Serve) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->attributes->has(RequestProfileResolver::REQUEST_ATTRIBUTE)) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (!is_string($route) || !str_ends_with($route, AuthKitRouteLoader::UNLOCALIZED_ROUTE_SUFFIX)) {
            return;
        }

        $locale = strtolower($this->defaultLocale);
        $request->attributes->set('_locale', $locale);
        $request->setLocale($locale);
        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($locale);
        }
    }
}
