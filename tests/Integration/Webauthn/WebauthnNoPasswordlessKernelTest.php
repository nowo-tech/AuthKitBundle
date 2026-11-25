<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Webauthn\Controller\WebauthnLoginController;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `webauthn.passwordless: false` (2.4.0): no passkey form or conditional UI on the login page, no sign-in routes, and
 * the sign-in controller refuses to serve the profile.
 */
final class WebauthnNoPasswordlessKernelTest extends WebauthnKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return WebauthnNoPasswordlessTestKernel::class;
    }

    #[Test]
    public function theLoginPageHasNoPasskeySignIn(): void
    {
        $page = $this->client->request('GET', '/login');
        self::assertCount(0, $page->filter('[data-nowo-auth-kit-webauthn]'));
        self::assertNotSame('username webauthn', $page->filter('input[name="login_form[_username]"]')->attr('autocomplete'));
        $this->client->request('GET', '/login/webauthn/options');
        self::assertResponseStatusCodeSame(404);

        $controller = self::getContainer()->get(WebauthnLoginController::class);
        self::assertInstanceOf(WebauthnLoginController::class, $controller);
        $request = Request::create('/login/webauthn/options');
        $request->attributes->set(RequestProfileResolver::REQUEST_ATTRIBUTE, 'default');
        $this->expectException(NotFoundHttpException::class);
        $controller->options($request);
    }
}
