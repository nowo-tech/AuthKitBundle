<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Identity;

use Nowo\AuthKitBundle\Identity\ExactIdentifierNormalizer;
use Nowo\AuthKitBundle\Identity\LoginIdentifierNormalizationListener;
use Nowo\AuthKitBundle\Identity\LowercaseAsciiEmailIdentifierNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * 1.48.0: the password sign-in identifier is normalised before the firewall reads it.
 */
final class LoginIdentifierNormalizationListenerTest extends TestCase
{
    public function testThePostedIdentifierIsReplacedByItsNormalisedForm(): void
    {
        $request = Request::create('/login', 'POST', ['login_form' => ['_username' => 'Bob@Example.COM', '_password' => 'secret', '_csrf_token' => 't']]);
        (new LoginIdentifierNormalizationListener(new LowercaseAsciiEmailIdentifierNormalizer()))->onKernelRequest($this->event($request));

        self::assertSame(['_username' => 'bob@example.com', '_password' => 'secret', '_csrf_token' => 't'], $request->request->all('login_form'));
        self::assertSame(16, LoginIdentifierNormalizationListener::PRIORITY);
    }

    /** @return iterable<string, array{Request, int}> */
    public static function untouched(): iterable
    {
        $form = ['login_form' => ['_username' => 'Bob@Example.COM', '_password' => 'secret']];

        yield 'GET' => [Request::create('/login', 'GET', $form), HttpKernelInterface::MAIN_REQUEST];
        yield 'sub-request' => [Request::create('/login', 'POST', $form), HttpKernelInterface::SUB_REQUEST];
        yield 'another form' => [Request::create('/register', 'POST', ['register_form' => ['email' => 'Bob@Example.COM']]), HttpKernelInterface::MAIN_REQUEST];
        yield 'scalar form' => [Request::create('/login', 'POST', ['login_form' => 'Bob@Example.COM']), HttpKernelInterface::MAIN_REQUEST];
        yield 'no identifier' => [Request::create('/login', 'POST', ['login_form' => ['_password' => 'secret']]), HttpKernelInterface::MAIN_REQUEST];
        yield 'array identifier' => [Request::create('/login', 'POST', ['login_form' => ['_username' => ['Bob@Example.COM']]]), HttpKernelInterface::MAIN_REQUEST];
        yield 'empty identifier' => [Request::create('/login', 'POST', ['login_form' => ['_username' => '']]), HttpKernelInterface::MAIN_REQUEST];
        yield 'refused identifier' => [Request::create('/login', 'POST', ['login_form' => ['_username' => 'Bob@Example.COM ']]), HttpKernelInterface::MAIN_REQUEST];
        yield 'already normalised' => [Request::create('/login', 'POST', ['login_form' => ['_username' => 'bob@example.com']]), HttpKernelInterface::MAIN_REQUEST];
    }

    #[DataProvider('untouched')]
    public function testOtherRequestsAreLeftAlone(Request $request, int $type): void
    {
        $before = $request->request->all();
        (new LoginIdentifierNormalizationListener(new LowercaseAsciiEmailIdentifierNormalizer()))->onKernelRequest($this->event($request, $type));

        self::assertSame($before, $request->request->all());
    }

    public function testTheDefaultNormalizerNeverRewritesTheRequest(): void
    {
        $request = Request::create('/login', 'POST', ['login_form' => ['_username' => 'Bob@Example.COM']]);
        (new LoginIdentifierNormalizationListener(new ExactIdentifierNormalizer()))->onKernelRequest($this->event($request));

        self::assertSame('Bob@Example.COM', $request->request->all('login_form')['_username']);
    }

    private function event(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }
}
