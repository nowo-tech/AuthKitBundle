<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\EventSubscriber;

use Nowo\AuthKitBundle\EventSubscriber\UnlocalizedDefaultLocaleSubscriber;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class UnlocalizedDefaultLocaleSubscriberTest extends TestCase
{
    public function testSubscribesAfterLocaleAwareListener(): void
    {
        self::assertSame(
            [KernelEvents::REQUEST => [['onKernelRequest', 14]]],
            UnlocalizedDefaultLocaleSubscriber::getSubscribedEvents(),
        );
    }

    public function testForcesDefaultLocaleOnUnlocalizedAuthRouteWhenServe(): void
    {
        $translator = new RecordingTranslator();
        $request    = Request::create('/login');
        $request->attributes->set('_route', 'nowo_auth_kit_login_unlocalized');
        $request->attributes->set(RequestProfileResolver::REQUEST_ATTRIBUTE, 'default');
        $request->attributes->set('_locale', 'en');
        $request->setLocale('en');
        $event = $this->mainEvent($request);

        $subscriber = new UnlocalizedDefaultLocaleSubscriber($translator, 'es', 'serve');

        $subscriber->onKernelRequest($event);

        self::assertSame('es', $request->getLocale());
        self::assertSame('es', $request->attributes->get('_locale'));
        self::assertSame('es', $translator->locale);
    }

    public function testIgnoresWhenUnlocalizedModeIsRedirect(): void
    {
        $translator = new RecordingTranslator();
        $request    = Request::create('/login');
        $request->attributes->set('_route', 'nowo_auth_kit_login_unlocalized');
        $request->attributes->set(RequestProfileResolver::REQUEST_ATTRIBUTE, 'default');
        $request->attributes->set('_locale', 'en');
        $request->setLocale('en');
        $event = $this->mainEvent($request);

        $subscriber = new UnlocalizedDefaultLocaleSubscriber($translator, 'es', 'redirect');

        $subscriber->onKernelRequest($event);

        self::assertSame('en', $request->getLocale());
        self::assertSame('en', $request->attributes->get('_locale'));
        self::assertSame('en', $translator->locale);
    }

    public function testIgnoresLocalizedAuthRoutes(): void
    {
        $translator = new RecordingTranslator();
        $request    = Request::create('/en/login');
        $request->attributes->set('_route', 'nowo_auth_kit_login');
        $request->attributes->set(RequestProfileResolver::REQUEST_ATTRIBUTE, 'default');
        $request->attributes->set('_locale', 'en');
        $request->setLocale('en');
        $event = $this->mainEvent($request);

        $subscriber = new UnlocalizedDefaultLocaleSubscriber($translator, 'es', 'serve');

        $subscriber->onKernelRequest($event);

        self::assertSame('en', $request->getLocale());
        self::assertSame('en', $request->attributes->get('_locale'));
        self::assertSame('en', $translator->locale);
    }

    public function testIgnoresNonAuthKitUnlocalizedRoutes(): void
    {
        $translator = new RecordingTranslator();
        $request    = Request::create('/setup');
        $request->attributes->set('_route', 'nowo_site_backup_setup_unlocalized');
        $request->attributes->set('_locale', 'en');
        $request->setLocale('en');
        $event = $this->mainEvent($request);

        $subscriber = new UnlocalizedDefaultLocaleSubscriber($translator, 'es', 'serve');

        $subscriber->onKernelRequest($event);

        self::assertSame('en', $request->getLocale());
        self::assertSame('en', $request->attributes->get('_locale'));
        self::assertSame('en', $translator->locale);
    }

    private function mainEvent(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}

/**
 * @internal
 */
final class RecordingTranslator implements TranslatorInterface, LocaleAwareInterface
{
    public string $locale = 'en';

    /**
     * @param array<string, mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $id;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }
}
