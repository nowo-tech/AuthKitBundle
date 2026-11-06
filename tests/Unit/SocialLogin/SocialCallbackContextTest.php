<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialCallbackContextTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?bool, ?string}>
     */
    public static function cases(): iterable
    {
        yield 'verified' => ['a@example.com', true, 'a@example.com'];
        yield 'not verified' => ['a@example.com', false, null];
        yield 'provider says nothing' => ['a@example.com', null, null];
        yield 'no e-mail' => [null, true, null];
        yield 'empty e-mail' => ['', true, null];
    }

    #[DataProvider('cases')]
    public function testVerifiedEmail(?string $email, ?bool $verified, ?string $expected): void
    {
        $context = new SocialCallbackContext(
            ProfileRegistryFactory::single(TestUser::class)->getDefault(),
            'google',
            new SocialUserProfile('subject', $email, 'Name', [], $verified),
            ['access_token' => 'at-placeholder', 'refresh_token' => null, 'expires_in' => null, 'raw' => []],
        );

        self::assertSame($expected, $context->verifiedEmail());
    }
}
