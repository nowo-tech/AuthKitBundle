<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Embed;

use Nowo\AuthKitBundle\Embed\AuthEmbedOptions;
use PHPUnit\Framework\TestCase;

final class AuthEmbedOptionsTest extends TestCase
{
    public function testFromArrayAndToArrayRoundTrip(): void
    {
        $options = AuthEmbedOptions::fromArray([
            'profile'      => 'admin',
            'active_panel' => 'register',
            'template'     => '@App/embed.html.twig',
            'form_theme'   => '@App/form.html.twig',
            'custom'       => true,
        ]);

        self::assertSame('admin', $options->profile);
        self::assertSame('register', $options->activePanel);
        self::assertSame('@App/embed.html.twig', $options->template);
        self::assertSame('@App/form.html.twig', $options->formTheme);
        self::assertSame(['custom' => true], $options->extra);

        self::assertSame([
            'custom'       => true,
            'profile'      => 'admin',
            'active_panel' => 'register',
            'template'     => '@App/embed.html.twig',
            'form_theme'   => '@App/form.html.twig',
        ], $options->toArray());
    }

    public function testFromArrayIgnoresEmptyProfileAndNonStringValues(): void
    {
        $options = AuthEmbedOptions::fromArray([
            'profile'      => '',
            'active_panel' => 1,
            'template'     => null,
            'form_theme'   => false,
        ]);

        self::assertNull($options->profile);
        self::assertNull($options->activePanel);
        self::assertNull($options->template);
        self::assertNull($options->formTheme);
        self::assertSame([], $options->toArray());
    }
}
