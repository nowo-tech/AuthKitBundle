<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\View;

use InvalidArgumentException;
use Nowo\AuthKitBundle\View\ProviderLink;
use Nowo\AuthKitBundle\View\ProviderMark;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\Link;
use PHPUnit\Framework\TestCase;

/**
 * 1.48: one provider button of the login page (`social_links` / `sso_links`).
 */
final class ProviderLinkTest extends TestCase
{
    public function testCarriesTheProviderLabelHrefAndMark(): void
    {
        $label = new Label('social.continue_with', 'NowoAuthKitBundle', ['%provider%' => 'Google']);
        $mark  = new ProviderMark('img/social/google.svg', 'nowo_auth_kit', true);
        $link  = new ProviderLink('google', $label, '/login/social/google', $mark);

        self::assertSame('google', $link->provider);
        self::assertSame($label, $link->label);
        self::assertSame('/login/social/google', $link->href);
        self::assertSame($mark, $link->mark);
    }

    public function testMarkIsOptional(): void
    {
        self::assertNull((new ProviderLink('my_idp', 'My IdP', '/login/social/my_idp'))->mark);
    }

    public function testExposesAUiKitLinkForPartialsTypedAgainstIt(): void
    {
        $label = new Label('social.continue_with', 'NowoAuthKitBundle', ['%provider%' => 'Google']);
        $link  = (new ProviderLink('google', $label, '/login/social/google', new ProviderMark('img/social/google.svg')))->link();

        self::assertInstanceOf(Link::class, $link);
        self::assertSame($label, $link->label);
        self::assertSame('/login/social/google', $link->href);
    }

    public function testProviderCannotBeEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty provider key');

        new ProviderLink(' ', 'My IdP', '/login/social/x');
    }

    public function testHrefCannotBeEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty href');

        new ProviderLink('my_idp', 'My IdP', ' ');
    }
}
