<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\User;

use Nowo\AuthKitBundle\Identity\LowercaseAsciiEmailIdentifierNormalizer;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\User\NewUser;
use Nowo\AuthKitBundle\User\UserCreationSource;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use PHPUnit\Framework\TestCase;

/**
 * 1.49.0 (audit OV-L1): {@see NewUser} carries the normalised identifier to the factory / host creator.
 */
final class NewUserNormalizationTest extends TestCase
{
    public function testFormDataIdentifierFieldIsNormalisedAndTheRestKept(): void
    {
        $profile = $this->profile();
        $newUser = NewUser::fromForm(['email' => 'Bob@Example.COM', 'password' => 'Pass-Word', 'name' => 'Bob'], ['ROLE_USER'], UserCreationSource::Invitation);

        self::assertSame('Bob@Example.COM', $newUser->identifierValue($profile));
        $normalized = $newUser->normalizedWith($profile, new LowercaseAsciiEmailIdentifierNormalizer());
        self::assertSame(['email' => 'bob@example.com', 'password' => 'Pass-Word', 'name' => 'Bob'], $normalized->formData);
        self::assertSame(UserCreationSource::Invitation, $normalized->source);
        self::assertSame(['ROLE_USER'], $normalized->roles);
        self::assertSame('Bob@Example.COM', $newUser->formData['email'], 'Immutable: the original is unchanged.');
    }

    public function testAdminAndSocialIdentifierIsNormalised(): void
    {
        $profile = $this->profile();
        $admin   = NewUser::forAdmin('Root@Example.COM', 'pw', ['ROLE_ADMIN'])->normalizedWith($profile, new LowercaseAsciiEmailIdentifierNormalizer());
        self::assertSame('root@example.com', $admin->identifier);
        self::assertSame('pw', $admin->plainPassword);

        $social = NewUser::forSocial('Ann@Example.COM', 'Ann', 'random', ['ROLE_USER'])->normalizedWith($profile, new LowercaseAsciiEmailIdentifierNormalizer());
        self::assertSame('ann@example.com', $social->identifier);
        self::assertSame('Ann', $social->displayName);
    }

    public function testUnchangedRefusedOrMissingIdentifiersReturnTheSameObject(): void
    {
        $profile = $this->profile();
        $lower   = new LowercaseAsciiEmailIdentifierNormalizer();
        foreach ([
            NewUser::fromForm(['email' => 'bob@example.com'], []),
            NewUser::fromForm(['email' => "Bob@Example.com\u{200B}"], []),
            NewUser::fromForm(['email' => ['Bob@Example.com']], []),
            NewUser::fromForm([], []),
            new NewUser(UserCreationSource::Admin, []),
        ] as $newUser) {
            self::assertSame($newUser, $newUser->normalizedWith($profile, $lower));
        }
        self::assertNull(NewUser::fromForm(['email' => ['x']], [])->identifierValue($profile));
    }

    public function testTheIdentifierFieldIsTheMappedNonPasswordFieldOfTheIdentifierProperty(): void
    {
        $profile = $this->profile([
            ['name' => '', 'property' => 'email', 'type' => 'email'],
            ['name' => 'secret', 'property' => 'email', 'type' => 'password'],
            ['name' => 'unmapped', 'property' => 'email', 'mapped' => false],
            ['name' => 'nickname', 'property' => 'nickname'],
            ['name' => 'login', 'property' => 'email'],
        ]);
        self::assertSame('Bob@X.org', NewUser::fromForm(['secret' => 'S@X', 'unmapped' => 'U@X', 'nickname' => 'N@X', 'login' => 'Bob@X.org'], [])->identifierValue($profile));

        $none = $this->profile([['name' => 'nickname', 'property' => 'nickname']]);
        self::assertNull(NewUser::fromForm(['nickname' => 'N@X'], [])->identifierValue($none));
        self::assertNull(NewUser::fromForm(['login' => 'Bob@X.org'], [])->identifierValue($none));
    }

    public function testPrepareLeavesAMissingOrEmptyIdentifierToTheHost(): void
    {
        $profile = $this->profile();
        $lookup  = $this->createMock(UserLookupInterface::class);
        $lookup->expects(self::never())->method('hasCaseVariant');
        $matcher = new UserIdentifierMatcher(null, new LowercaseAsciiEmailIdentifierNormalizer());

        foreach ([NewUser::fromForm([], []), NewUser::fromForm(['email' => ''], [])] as $newUser) {
            self::assertSame($newUser, $matcher->prepareNewUser($profile, $newUser, $lookup, 'Registration failed'));
        }
    }

    public function testPrepareWithTheDefaultNormaliserSendsNoCaseVariantQuery(): void
    {
        $lookup = $this->createMock(UserLookupInterface::class);
        $lookup->expects(self::never())->method('hasCaseVariant');
        $newUser = NewUser::fromForm(['email' => 'Bob@Example.com'], []);

        self::assertSame($newUser, (new UserIdentifierMatcher())->prepareNewUser($this->profile(), $newUser, $lookup, 'Registration failed'));
    }

    public function testPrepareAsksTheLookupWithTheRawValueOnTheIdentifierProperty(): void
    {
        $profile = $this->profile();
        $lookup  = $this->createMock(UserLookupInterface::class);
        $lookup->expects(self::once())->method('hasCaseVariant')->with($profile, 'email', 'Bob@Example.com')->willReturn(false);

        $prepared = (new UserIdentifierMatcher(null, new LowercaseAsciiEmailIdentifierNormalizer()))
            ->prepareNewUser($profile, NewUser::fromForm(['email' => 'Bob@Example.com'], []), $lookup, 'Registration failed');
        self::assertSame('bob@example.com', $prepared->formData['email']);
    }

    /**
     * @param list<array<string, mixed>>|null $registrationFields
     */
    private function profile(?array $registrationFields = null): ProfileSettings
    {
        $config = ProfileRegistryFactory::defaultProfileConfig(TestUser::class);
        if ($registrationFields !== null) {
            $config['registration_fields'] = $registrationFields;
        }

        return ProfileRegistryFactory::fromProfiles(['default' => $config])->getDefault();
    }
}
