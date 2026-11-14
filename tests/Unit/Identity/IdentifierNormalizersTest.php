<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Identity;

use Nowo\AuthKitBundle\Identity\ExactIdentifierNormalizer;
use Nowo\AuthKitBundle\Identity\LowercaseAsciiEmailIdentifierNormalizer;
use Nowo\AuthKitBundle\Identity\UserIdentifierNormalizerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * 1.49.0: the built-in identifier normalisers. The default keeps every usable value as typed (behaviour before
 * 1.49.0); the opt-in one lower-cases ASCII letters of e-mail addresses only, never folding a look-alike.
 */
final class IdentifierNormalizersTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function unusable(): iterable
    {
        yield 'empty' => [''];
        yield 'leading space' => [' bob@example.com'];
        yield 'trailing space' => ['bob@example.com '];
        yield 'NBSP' => ["bob@example.com\u{00A0}"];
        yield 'new line' => ["bob@example.com\n"];
        yield 'ZWSP' => ["bob\u{200B}@example.com"];
        yield 'BOM' => ["\u{FEFF}bob@example.com"];
        yield 'bidi override' => ["bob\u{202E}@example.com"];
        yield 'NUL' => ["bob\0@example.com"];
        yield 'invalid UTF-8' => ["b\xC3@example.com"];
        yield 'over-long' => [str_repeat('b', ExactIdentifierNormalizer::MAX_LENGTH + 1)];
    }

    #[DataProvider('unusable')]
    public function testBothNormalizersRefuseUnusableValues(string $raw): void
    {
        self::assertNull((new ExactIdentifierNormalizer())->normalize($raw));
        self::assertNull((new LowercaseAsciiEmailIdentifierNormalizer())->normalize($raw));
    }

    /** @return iterable<string, array{string}> */
    public static function usable(): iterable
    {
        yield 'mixed-case e-mail' => ['Bob@Example.COM'];
        yield 'lower-case e-mail' => ['bob@example.com'];
        yield 'username' => ['Bob'];
        yield 'accented' => ['JOSÉ@Example.com'];
        yield 'longest accepted' => [str_repeat('b', ExactIdentifierNormalizer::MAX_LENGTH)];
    }

    #[DataProvider('usable')]
    public function testTheDefaultReturnsUsableValuesUnchanged(string $raw): void
    {
        self::assertSame($raw, (new ExactIdentifierNormalizer())->normalize($raw));
    }

    /** @return iterable<string, array{string, string}> */
    public static function lowerCased(): iterable
    {
        yield 'mixed-case e-mail' => ['Bob@Example.COM', 'bob@example.com'];
        yield 'already lower case' => ['bob@example.com', 'bob@example.com'];
        yield 'plus tag and digits' => ['Bob+News2@Mail.Example.ORG', 'bob+news2@mail.example.org'];
        // Only A-Z: Unicode letters keep their case, so no two stored accounts ever fold into one.
        yield 'non-ASCII upper case kept' => ['JOSÉ@Example.com', 'josÉ@example.com'];
        yield 'KELVIN SIGN kept' => ["\u{212A}im@Example.com", "\u{212A}im@example.com"];
        yield 'dotted capital I kept' => ["\u{0130}van@Example.com", "\u{0130}van@example.com"];
        yield 'fullwidth A kept' => ["\u{FF21}da@Example.com", "\u{FF21}da@example.com"];
        yield 'decomposed accent kept' => ["Jose\u{0301}@X.test", "jose\u{0301}@x.test"];
    }

    #[DataProvider('lowerCased')]
    public function testTheEmailNormalizerLowerCasesAsciiLettersOfEmailAddresses(string $raw, string $expected): void
    {
        $normalizer = new LowercaseAsciiEmailIdentifierNormalizer();

        self::assertSame($expected, $normalizer->normalize($raw));
        self::assertSame($expected, $normalizer->normalize($expected), 'Idempotent.');
    }

    /** @return iterable<string, array{string}> */
    public static function notEmail(): iterable
    {
        yield 'username' => ['AdminUser'];
        yield 'two @' => ['Bob@Team@Example.com'];
        yield 'leading @' => ['@BobExample'];
        yield 'trailing @' => ['BobExample@'];
    }

    #[DataProvider('notEmail')]
    public function testTheEmailNormalizerKeepsValuesThatAreNotEmailAddresses(string $raw): void
    {
        self::assertSame($raw, (new LowercaseAsciiEmailIdentifierNormalizer())->normalize($raw));
    }

    public function testTheEmailNormalizerAppliesTheGivenRefusalsFirst(): void
    {
        $normalizer = new LowercaseAsciiEmailIdentifierNormalizer(new ExactIdentifierNormalizer());

        self::assertInstanceOf(UserIdentifierNormalizerInterface::class, $normalizer);
        self::assertNull($normalizer->normalize('Bob@Example.com '));
    }
}
