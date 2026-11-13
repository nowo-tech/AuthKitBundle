<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PersonalData;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\PersonalData\DefaultInvitationPersonalDataSubjectResolver;
use Nowo\AuthKitBundle\PersonalData\InvitationErasureResult;
use Nowo\AuthKitBundle\PersonalData\InvitationPersonalDataEraserInterface;
use Nowo\AuthKitBundle\PersonalData\InvitationPersonalDataExporterInterface;
use Nowo\AuthKitBundle\PersonalData\InvitationPersonalDataSubject;
use Nowo\AuthKitBundle\PersonalData\InvitationPersonalDataSubjectResolverInterface;
use Nowo\AuthKitBundle\PersonalData\InvitationUserKitEraser;
use Nowo\AuthKitBundle\PersonalData\InvitationUserKitExportProvider;
use Nowo\AuthKitBundle\PersonalData\SocialAccountPersonalDataExporterInterface;
use Nowo\AuthKitBundle\PersonalData\SocialAccountPersonalDataSubject;
use Nowo\AuthKitBundle\PersonalData\SocialAccountUserKitExportProvider;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_map;
use function str_repeat;

final class UserKitPersonalDataAdaptersTest extends TestCase
{
    public function testTheDefaultResolverListsIdentifiersAndEmails(): void
    {
        $resolver = new DefaultInvitationPersonalDataSubjectResolver(ProfileRegistryFactory::single(TestUser::class));

        $subjects = $resolver->subjectsFor(new TestUser('Current@Example.com'), 'Old@Example.com');

        self::assertSame([
            "user_identifier\0Old@Example.com",
            "user_identifier\0Current@Example.com",
            "email\0current@example.com",
            "email\0old@example.com",
        ], $this->keys($subjects));
        self::assertSame([], $resolver->subjectsFor(new stdClass(), null), 'Not a user, no identifier.');
        self::assertSame(["user_identifier\0username", "email\0username"], $this->keys($resolver->subjectsFor(new TestUser('username'), null)), 'The e-mail property is taken as stored.');
    }

    public function testTheResolverReadsTheProfileInvitationEmailField(): void
    {
        $user = new class implements UserInterface {
            public string $contactEmail = 'Contact@Example.com';

            public function getUserIdentifier(): string
            {
                return 'nick';
            }

            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }
        };
        $registry = ProfileRegistryFactory::single($user::class, [
            'registration_fields' => [['name' => 'contact', 'type' => 'email', 'property' => 'contactEmail']],
            'invitations'         => ['mode' => 'enabled', 'email_field' => 'contact'],
        ]);

        $keys = $this->keys((new DefaultInvitationPersonalDataSubjectResolver($registry))->subjectsFor($user, str_repeat('x', 300)));

        self::assertSame(["user_identifier\0" . str_repeat('x', 180), "user_identifier\0nick", "email\0contact@example.com"], $keys);
    }

    public function testAddSkipsWhatCannotBeASubject(): void
    {
        $subjects = [];
        DefaultInvitationPersonalDataSubjectResolver::add($subjects, null, true);
        DefaultInvitationPersonalDataSubjectResolver::add($subjects, '', false);
        DefaultInvitationPersonalDataSubjectResolver::add($subjects, str_repeat('a', 200) . '@example.com', true);
        DefaultInvitationPersonalDataSubjectResolver::add($subjects, "a\0b", false);

        self::assertSame([], $subjects);
    }

    public function testTheEraserCallsThePortOncePerDistinctSubject(): void
    {
        $port = new class implements InvitationPersonalDataEraserInterface {
            /** @var list<string> */
            public array $calls = [];

            public function erase(InvitationPersonalDataSubject $subject): InvitationErasureResult
            {
                $this->calls[] = $subject->key();

                return new InvitationErasureResult();
            }
        };
        $eraser = new InvitationUserKitEraser($port, $this->resolver([
            InvitationPersonalDataSubject::fromUserIdentifier('a@example.com'),
            InvitationPersonalDataSubject::fromUserIdentifier('a@example.com'),
            InvitationPersonalDataSubject::fromEmail('a@example.com'),
        ]));

        self::assertSame('auth_kit_invitations', $eraser->getKey());
        self::assertTrue($eraser->supports(new TestUser('a@example.com')));
        self::assertFalse($eraser->supports(new stdClass()));

        $eraser->erase(new ErasureRequest(new TestUser('a@example.com'), null, ['previous_email' => 'Before@Example.com']));
        $eraser->erase(new ErasureRequest(new TestUser('a@example.com'), null, ['previous_email' => ['not a string']]));

        self::assertSame([
            "user_identifier\0a@example.com",
            "email\0a@example.com",
            "email\0before@example.com",
            "user_identifier\0Before@Example.com",
            "user_identifier\0a@example.com",
            "email\0a@example.com",
        ], $port->calls);
    }

    public function testTheInvitationExportMergesTheSubjects(): void
    {
        $port = new class implements InvitationPersonalDataExporterInterface {
            public function export(InvitationPersonalDataSubject $subject): array
            {
                return $subject->isEmail()
                    ? ['received' => [['profile' => $subject->value]], 'sent' => []]
                    : ['received' => [], 'sent' => [['email' => $subject->value]]];
            }
        };
        $provider = new InvitationUserKitExportProvider($port, $this->resolver([
            InvitationPersonalDataSubject::fromEmail('a@example.com'),
            InvitationPersonalDataSubject::fromEmail('A@example.com'),
            InvitationPersonalDataSubject::fromUserIdentifier('a'),
        ]));

        self::assertSame('invitations', $provider->getSectionKey());
        self::assertTrue($provider->supports(new TestUser('a')));
        self::assertFalse($provider->supports(new stdClass()));
        self::assertSame(['received' => [['profile' => 'a@example.com']], 'sent' => [['email' => 'a']]], $provider->export(new TestUser('a')));
    }

    public function testTheSocialExportAsksForTheOwnerAndTheLegacyRows(): void
    {
        $port = new class implements SocialAccountPersonalDataExporterInterface {
            /** @var list<array{string, string, bool}> */
            public array $calls = [];

            public function export(SocialAccountPersonalDataSubject $subject): array
            {
                $this->calls[] = [$subject->userClass, $subject->value, $subject->legacy];

                return [['provider' => 'github', 'linked_at' => null, 'provider_email' => null, 'display_name' => null]];
            }
        };
        $registry = ProfileRegistryFactory::single(TestUser::class);

        // A Doctrine user with id 7: the owner, then the rows stored under the identifier before 1.37.
        $provider = new SocialAccountUserKitExportProvider($port, $registry, new MfaOwnerResolver($this->doctrineWithId(7)));
        self::assertSame('social_accounts', $provider->getSectionKey());
        self::assertTrue($provider->supports(new TestUser('alice')));
        self::assertFalse($provider->supports(new stdClass()));
        self::assertCount(2, $provider->export(new TestUser('alice')));
        self::assertSame([[TestUser::class, '7', false], [TestUser::class, 'alice', true]], $port->calls);

        // Not a Doctrine user: the owner id is the identifier, asked once.
        $port->calls = [];
        $provider    = new SocialAccountUserKitExportProvider($port, $registry, new MfaOwnerResolver());
        self::assertCount(1, $provider->export(new TestUser('alice')));
        self::assertSame([[TestUser::class, 'alice', false]], $port->calls);

        // An id longer than the column, or no owner at all: no row can hold it.
        $port->calls = [];
        $provider->export(new TestUser(str_repeat('a', 100)));
        self::assertSame([], $port->calls);
        $port->calls = [];
        $provider->export(new TestUser(str_repeat('a', 300)));
        self::assertSame([], $port->calls);
        self::assertSame([], $provider->export(new stdClass()));

        // OG-4 (1.47.0): an identifier refused as an MFA key since REQ-SEC-006 is still exported, byte for byte.
        $port->calls = [];
        $provider->export(new TestUser('alice '));
        self::assertSame([[TestUser::class, 'alice ', false]], $port->calls);
    }

    /**
     * @param list<InvitationPersonalDataSubject> $subjects
     */
    private function resolver(array $subjects): InvitationPersonalDataSubjectResolverInterface
    {
        $resolver = $this->createStub(InvitationPersonalDataSubjectResolverInterface::class);
        $resolver->method('subjectsFor')->willReturn($subjects);

        return $resolver;
    }

    private function doctrineWithId(int $id): ManagerRegistry
    {
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => $id]);
        $metadata->rootEntityName = TestUser::class;
        $factory                  = $this->createStub(ClassMetadataFactory::class);
        $factory->method('isTransient')->willReturn(false);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getMetadataFactory')->willReturn($factory);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn($manager);

        return $doctrine;
    }

    /**
     * @param list<InvitationPersonalDataSubject> $subjects
     *
     * @return list<string>
     */
    private function keys(array $subjects): array
    {
        return array_map(static fn (InvitationPersonalDataSubject $subject): string => $subject->key(), $subjects);
    }
}
