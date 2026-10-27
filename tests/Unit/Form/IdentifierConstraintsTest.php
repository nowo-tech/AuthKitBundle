<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Form;

use Nowo\AuthKitBundle\Form\IdentifierConstraints;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

/** SEC-004 H1 (defence in depth): look-alike Unicode addresses are refused before any lookup. */
final class IdentifierConstraintsTest extends TestCase
{
    public function testEmailIdentifiersMustBePlainAsciiAddresses(): void
    {
        $constraints = IdentifierConstraints::for(ProfileRegistryFactory::single(TestUser::class)->getDefault(), 'required');
        $validator   = Validation::createValidator();

        self::assertCount(0, $validator->validate('victim@example.com', $constraints));
        $violations = $validator->validate("victim@ex\u{00E1}mple.com", $constraints);
        self::assertCount(1, $violations);
        self::assertSame(IdentifierConstraints::EMAIL_MESSAGE, $violations[0]?->getMessageTemplate());
    }

    public function testOtherIdentifiersOnlyNeedAValue(): void
    {
        $constraints = IdentifierConstraints::for(
            ProfileRegistryFactory::single(TestUser::class, ['user_identifier_field' => 'username'])->getDefault(),
            'required',
        );

        self::assertCount(1, $constraints);
        self::assertInstanceOf(NotBlank::class, $constraints[0]);
    }
}
