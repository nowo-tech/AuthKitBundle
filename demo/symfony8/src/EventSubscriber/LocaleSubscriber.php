<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

use function is_string;

/**
 * Applies locale from the route or session.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $defaultLocale,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $locale  = $request->attributes->get('_locale');

        if (!is_string($locale) || $locale === '') {
            $locale = $request->hasSession()
                ? $request->getSession()->get('_locale', $this->defaultLocale)
                : $this->defaultLocale;
        }

        $request->setLocale($locale);
        $this->localeSwitcher->setLocale($locale);

        if ($request->hasSession()) {
            $request->getSession()->set('_locale', $locale);
        }
    }
}
