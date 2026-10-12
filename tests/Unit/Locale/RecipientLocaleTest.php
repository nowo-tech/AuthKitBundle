<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Locale;

use Nowo\AuthKitBundle\Locale\PreferredLocaleAwareRecipientLocaleResolver;
use Nowo\AuthKitBundle\Locale\RecipientLocale;
use Nowo\AuthKitBundle\Locale\RecipientLocaleResolverInterface;
use Nowo\AuthKitBundle\Tests\Stub\PreferredLocaleTestUser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Translation\Translator;

final class RecipientLocaleTest extends TestCase
{
    public function testDefaultResolverReadsPreferredLocaleAwareUsers(): void
    {
        $resolver = new PreferredLocaleAwareRecipientLocaleResolver();

        self::assertSame('es', $resolver->resolve(new PreferredLocaleTestUser(preferredLocale: 'es')));
        self::assertNull($resolver->resolve(new PreferredLocaleTestUser()));
        self::assertNull($resolver->resolve(new TestUser('a@b.c')));
    }

    public function testDisabledByDefaultWhenConstructedWithoutArguments(): void
    {
        self::assertNull((new RecipientLocale())->resolve(new PreferredLocaleTestUser(preferredLocale: 'es')));
    }

    public function testResolvesOnlyAllowedLocales(): void
    {
        $recipientLocale = new RecipientLocale(enabled: true, allowedLocales: ['en', 'es']);

        self::assertSame('es', $recipientLocale->resolve(new PreferredLocaleTestUser(preferredLocale: 'es')));
        self::assertNull($recipientLocale->resolve(new PreferredLocaleTestUser(preferredLocale: 'fr')));
        self::assertNull($recipientLocale->resolve(new PreferredLocaleTestUser()));
        self::assertNull($recipientLocale->resolve(new TestUser('a@b.c')));
    }

    public function testUsesCustomResolver(): void
    {
        $resolver = $this->createMock(RecipientLocaleResolverInterface::class);
        $resolver->method('resolve')->willReturn('de');

        $recipientLocale = new RecipientLocale($resolver, enabled: true, allowedLocales: ['de']);

        self::assertSame('de', $recipientLocale->resolve(new TestUser('a@b.c')));
    }

    public function testRunCallsCallbackDirectlyWithoutLocaleOrSwitcher(): void
    {
        $recipientLocale = new RecipientLocale(enabled: true, allowedLocales: ['es']);

        self::assertSame('direct', $recipientLocale->run(null, static fn (): string => 'direct'));
        self::assertSame('no-switcher:x', $recipientLocale->run('es', static fn (string $suffix): string => 'no-switcher:' . $suffix, 'x'));
    }

    public function testRunSwitchesTranslatorAndRouterLocaleThenRestores(): void
    {
        $translator = new Translator('en');
        $context    = new RequestContext();
        $context->setParameter('_locale', 'en');
        $switcher        = new LocaleSwitcher('en', [$translator], $context);
        $recipientLocale = new RecipientLocale(localeSwitcher: $switcher, enabled: true, allowedLocales: ['es']);

        $seen = $recipientLocale->run('es', static fn (): array => [$translator->getLocale(), $context->getParameter('_locale')]);

        self::assertSame(['es', 'es'], $seen);
        self::assertSame('en', $translator->getLocale());
        self::assertSame('en', $context->getParameter('_locale'));
    }

    public function testRunRestoresLocaleWhenCallbackThrows(): void
    {
        $translator      = new Translator('en');
        $switcher        = new LocaleSwitcher('en', [$translator]);
        $recipientLocale = new RecipientLocale(localeSwitcher: $switcher, enabled: true, allowedLocales: ['es']);

        $thrown = false;
        try {
            $recipientLocale->run('es', static function (string $message): never {
                throw new RuntimeException($message);
            }, 'boom');
        } catch (RuntimeException $exception) {
            $thrown = $exception->getMessage() === 'boom';
        }

        self::assertTrue($thrown);
        self::assertSame('en', $translator->getLocale());
    }
}
