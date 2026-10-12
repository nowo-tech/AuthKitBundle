<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Flash;

use Nowo\AuthKitBundle\Flash\AuthKitFlashMessenger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class AuthKitFlashMessengerTest extends TestCase
{
    public function testStoresTranslatedMessageInRequestLocale(): void
    {
        $request = $this->request('es');

        (new AuthKitFlashMessenger($this->translator()))->flash($request, 'success', 'magic_login.request.flash_sent');

        self::assertSame(['success' => ['Enlace enviado.']], $this->flashes($request));
    }

    public function testKeepsKeyWhenTranslationDisabled(): void
    {
        $request = $this->request('es');

        (new AuthKitFlashMessenger($this->translator(), false))->flash($request, 'error', 'magic_login.request.flash_sent');

        self::assertSame(['error' => ['magic_login.request.flash_sent']], $this->flashes($request));
    }

    public function testKeepsKeyWithoutTranslator(): void
    {
        $request = $this->request('es');

        (new AuthKitFlashMessenger())->flash($request, 'error', 'magic_login.request.flash_sent');

        self::assertSame(['error' => ['magic_login.request.flash_sent']], $this->flashes($request));
    }

    public function testIgnoresRequestsWithoutFlashBagSession(): void
    {
        $messenger = new AuthKitFlashMessenger($this->translator());

        $messenger->flash(Request::create('/'), 'success', 'magic_login.request.flash_sent');

        $request = Request::create('/');
        $request->setSession($this->createMock(SessionInterface::class));
        $messenger->flash($request, 'success', 'magic_login.request.flash_sent');

        $this->addToAssertionCount(1);
    }

    private function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['magic_login.request.flash_sent' => 'Enlace enviado.'], 'es', 'NowoAuthKitBundle');
        $translator->addResource('array', ['magic_login.request.flash_sent' => 'Link sent.'], 'en', 'NowoAuthKitBundle');

        return $translator;
    }

    private function request(string $locale): Request
    {
        $request = Request::create('/');
        $request->setLocale($locale);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /**
     * @return array<string, list<string>>
     */
    private function flashes(Request $request): array
    {
        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session->getFlashBag()->all();
    }
}
