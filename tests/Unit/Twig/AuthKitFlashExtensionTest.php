<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Twig;

use Nowo\AuthKitBundle\Twig\AuthKitFlashExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader as TwigArrayLoader;

final class AuthKitFlashExtensionTest extends TestCase
{
    public function testRegistersFlashTransFilter(): void
    {
        $twig = new Environment(new TwigArrayLoader(['t' => '{{ message|flash_trans }}']));
        $twig->addExtension(new AuthKitFlashExtension($this->translator()));

        self::assertSame('Link sent.', $twig->render('t', ['message' => 'magic_login.request.flash_sent']));
    }

    public function testPicksFirstDomainThatHasTheKey(): void
    {
        $extension = new AuthKitFlashExtension($this->translator());

        self::assertSame('Saved (app).', $extension->translate('app.saved'));
        self::assertSame('Link sent.', $extension->translate('magic_login.request.flash_sent'));
        self::assertSame('Shared (app).', $extension->translate('shared.key'));
        self::assertSame('Hello Ana', $extension->translate('app.hello', ['%name%' => 'Ana']));
    }

    public function testDomainOrderIsConfigurable(): void
    {
        $extension = new AuthKitFlashExtension($this->translator(), ['NowoAuthKitBundle', 'messages']);

        self::assertSame('Shared (kit).', $extension->translate('shared.key'));
    }

    public function testUnknownTextAndNonStringsPassThrough(): void
    {
        $extension = new AuthKitFlashExtension($this->translator());

        self::assertSame('Already translated text.', $extension->translate('Already translated text.'));
        self::assertSame('', $extension->translate(''));
        $structured = ['title' => 'T', 'message' => 'M'];
        self::assertSame($structured, $extension->translate($structured));
    }

    public function testFallsBackToMessagesWithoutCatalogueAccess(): void
    {
        self::assertSame('plain.key', (new AuthKitFlashExtension(new IdentityTranslator()))->translate('plain.key'));
    }

    private function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'app.saved'  => 'Saved (app).',
            'app.hello'  => 'Hello %name%',
            'shared.key' => 'Shared (app).',
        ], 'en', 'messages');
        $translator->addResource('array', [
            'magic_login.request.flash_sent' => 'Link sent.',
            'shared.key'                     => 'Shared (kit).',
        ], 'en', 'NowoAuthKitBundle');

        return $translator;
    }
}
