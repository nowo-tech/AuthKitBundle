<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security;

use Nowo\AuthKitBundle\Security\AuthKitFormLoginParameters;
use PHPUnit\Framework\TestCase;

final class AuthKitFormLoginParametersTest extends TestCase
{
    public function testFieldParametersUseLoginFormPrefix(): void
    {
        self::assertSame('login_form[_username]', AuthKitFormLoginParameters::usernameParameter());
        self::assertSame('login_form[_password]', AuthKitFormLoginParameters::passwordParameter());
        self::assertSame('login_form[_csrf_token]', AuthKitFormLoginParameters::csrfParameter());
        self::assertSame('login_form[_remember_me]', AuthKitFormLoginParameters::rememberMeParameter());
    }

    public function testFormLoginOptionsIncludeNestedParameters(): void
    {
        $options = AuthKitFormLoginParameters::formLoginOptions();

        self::assertTrue($options['enable_csrf']);
        self::assertSame('login_form[_username]', $options['username_parameter']);
        self::assertSame('authenticate', $options['csrf_token_id']);
    }

    public function testRememberMeOptions(): void
    {
        $options = AuthKitFormLoginParameters::rememberMeOptions(604800, '/');

        self::assertSame('%kernel.secret%', $options['secret']);
        self::assertSame(604800, $options['lifetime']);
        self::assertSame('login_form[_remember_me]', $options['remember_me_parameter']);
    }
}
