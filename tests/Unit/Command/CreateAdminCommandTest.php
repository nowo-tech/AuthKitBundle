<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Admin\AdminUserCreator;
use Nowo\AuthKitBundle\Command\CreateAdminCommand;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\PasswordStrengthBundle\Validator\PasswordStrength;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CreateAdminCommandTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery';

    /** @var list<object> */
    private array $persisted = [];

    public function testCommandMetadata(): void
    {
        $command = new CreateAdminCommand($this->creator());

        self::assertSame('nowo:auth-kit:create-admin', $command->getName());
        self::assertTrue($command->getDefinition()->hasArgument('identifier'));
        self::assertTrue($command->getDefinition()->hasOption('auth-profile'));
        self::assertFalse($command->getDefinition()->hasOption('password'));
    }

    public function testCreatesAdminFromHiddenPrompt(): void
    {
        $tester = new CommandTester(new CreateAdminCommand($this->creator()));
        $tester->setInputs([self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::SUCCESS, $tester->execute(['identifier' => ' Owner@Example.com ']));
        self::assertStringContainsString('Created admin owner@example.com (ROLE_ADMIN) in profile "default"', $this->display($tester));
        self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
        self::assertCount(1, $this->persisted);
        $user = $this->persisted[0];
        self::assertInstanceOf(TestUser::class, $user);
        self::assertSame('owner@example.com', $user->getEmail());
        self::assertSame('hashed:' . self::PASSWORD, $user->getPassword());
        self::assertContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testUsesConfiguredRolesMinLengthAndProfile(): void
    {
        $command = new CreateAdminCommand($this->creator(profileName: 'staff'), 8, ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN']);
        $tester  = new CommandTester($command);
        $tester->setInputs(['12345678', '12345678']);

        self::assertSame(Command::SUCCESS, $tester->execute(['identifier' => 'root@example.com', '--auth-profile' => 'staff']));
        self::assertStringContainsString('(ROLE_SUPER_ADMIN, ROLE_ADMIN) in profile "staff"', $this->display($tester));
    }

    public function testRejectsUnknownProfile(): void
    {
        $tester = new CommandTester(new CreateAdminCommand($this->creator()));

        self::assertSame(Command::INVALID, $tester->execute(['identifier' => 'a@b.c', '--auth-profile' => 'missing']));
        self::assertStringContainsString('missing', $tester->getDisplay());
    }

    public function testRejectsInvalidEmail(): void
    {
        $tester = new CommandTester(new CreateAdminCommand($this->creator()));

        self::assertSame(Command::INVALID, $tester->execute(['identifier' => 'not-an-email']));
        self::assertStringContainsString('is not a valid email address', $tester->getDisplay());
    }

    public function testRejectsEmptyNonEmailIdentifier(): void
    {
        $tester = new CommandTester(new CreateAdminCommand($this->creator(identifierField: 'username')));

        self::assertSame(Command::INVALID, $tester->execute(['identifier' => '  ']));
        self::assertStringContainsString('must not be empty', $tester->getDisplay());
    }

    public function testKeepsNonEmailIdentifierCase(): void
    {
        $tester = new CommandTester(new CreateAdminCommand($this->creator(identifierField: 'username')));
        $tester->setInputs([self::PASSWORD, self::PASSWORD]);

        // TestUser has no "username" property: the write fails, proving the identifier went to user_identifier_field.
        self::assertSame(Command::FAILURE, $tester->execute(['identifier' => 'RootAdmin']));
        self::assertStringContainsString('"username"', $tester->getDisplay());
    }

    public function testRejectsExistingUser(): void
    {
        $tester = new CommandTester(new CreateAdminCommand($this->creator(existing: true)));

        self::assertSame(Command::FAILURE, $tester->execute(['identifier' => 'taken@example.com']));
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testRefusesNonInteractiveRuns(): void
    {
        $tester = new CommandTester(new CreateAdminCommand($this->creator()));

        self::assertSame(Command::INVALID, $tester->execute(['identifier' => 'a@example.com'], ['interactive' => false]));
        self::assertStringContainsString('interactive terminal', $this->display($tester));
        self::assertSame([], $this->persisted);
    }

    public function testRejectsShortPassword(): void
    {
        $tester = new CommandTester(new CreateAdminCommand($this->creator()));
        $tester->setInputs(['short']);

        self::assertSame(Command::INVALID, $tester->execute(['identifier' => 'a@example.com']));
        self::assertStringContainsString('at least 12 characters', $this->display($tester));
    }

    public function testRejectsMismatchedRepeat(): void
    {
        $tester = new CommandTester(new CreateAdminCommand($this->creator()));
        $tester->setInputs([self::PASSWORD, self::PASSWORD . 'x']);

        self::assertSame(Command::INVALID, $tester->execute(['identifier' => 'a@example.com']));
        self::assertStringContainsString('Passwords do not match', $tester->getDisplay());
    }

    public function testFailsWhenPromptCannotBeAnswered(): void
    {
        $tester = new CommandTester(new CreateAdminCommand($this->creator()));
        $tester->setInputs([]);

        self::assertSame(Command::FAILURE, $tester->execute(['identifier' => 'a@example.com']));
        self::assertStringContainsString('Cannot read the password from a hidden prompt', $this->display($tester));
    }

    public function testReportsCreationFailure(): void
    {
        $tester = new CommandTester(new CreateAdminCommand($this->creator(flushFails: true)));
        $tester->setInputs([self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::FAILURE, $tester->execute(['identifier' => 'a@example.com']));
        self::assertStringContainsString('flush failed', $tester->getDisplay());
    }

    public function testAppliesProfilePasswordStrengthPolicy(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects(self::once())->method('validate')
            ->with(self::PASSWORD, self::callback(static fn (PasswordStrength $constraint): bool => $constraint->level === 'strong' && $constraint->policyMode === 'conditions'))
            ->willReturn(new ConstraintViolationList([new ConstraintViolation('Too weak.', null, [], null, '', self::PASSWORD)]));

        $command = new CreateAdminCommand($this->creator(strength: ['enabled' => true, 'level' => 'strong', 'policy_mode' => 'conditions']), validator: $validator);
        $tester  = new CommandTester($command);
        $tester->setInputs([self::PASSWORD]);

        self::assertSame(Command::INVALID, $tester->execute(['identifier' => 'a@example.com']));
        self::assertStringContainsString('does not meet the "strong" strength policy: Too weak.', $this->display($tester));
    }

    public function testAcceptsPasswordPassingStrengthPolicy(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects(self::once())->method('validate')
            ->with(self::PASSWORD, self::callback(static fn (PasswordStrength $constraint): bool => $constraint->level === 'medium' && $constraint->policyMode === 'level'))
            ->willReturn(new ConstraintViolationList());

        $command = new CreateAdminCommand($this->creator(strength: ['enabled' => true, 'level' => 'medium', 'policy_mode' => 'level']), validator: $validator);
        $tester  = new CommandTester($command);
        $tester->setInputs([self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::SUCCESS, $tester->execute(['identifier' => 'a@example.com']));
    }

    public function testSkipsStrengthPolicyWhenDisabledOrUnavailable(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects(self::never())->method('validate');

        foreach ([
            new CreateAdminCommand($this->creator(), validator: $validator),
            new CreateAdminCommand($this->creator(strength: ['enabled' => true, 'level' => 'medium', 'policy_mode' => 'level']), validator: $validator, strengthConstraintClass: 'Missing\\Constraint'),
            new CreateAdminCommand($this->creator(strength: ['enabled' => true, 'level' => 'medium', 'policy_mode' => 'level'])),
        ] as $command) {
            $tester = new CommandTester($command);
            $tester->setInputs([self::PASSWORD, self::PASSWORD]);
            self::assertSame(Command::SUCCESS, $tester->execute(['identifier' => 'a@example.com']));
        }
    }

    /**
     * @param array<string, mixed>|null $strength
     */
    private function creator(
        bool $existing = false,
        bool $flushFails = false,
        string $profileName = 'default',
        string $identifierField = 'email',
        ?array $strength = null,
    ): AdminUserCreator {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($existing ? new TestUser('taken@example.com') : null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('persist')->willReturnCallback(function (object $user): void {
            $this->persisted[] = $user;
        });
        if ($flushFails) {
            $entityManager->method('flush')->willThrowException(new RuntimeException('flush failed'));
        }

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturnCallback(static fn (object $user, string $plain): string => 'hashed:' . $plain);

        $overrides = ['user_identifier_field' => $identifierField];
        if ($strength !== null) {
            $overrides['password_strength'] = $strength;
        }

        return new AdminUserCreator(
            ProfileRegistryFactory::single(TestUser::class, $overrides, $profileName),
            $entityManager,
            $hasher,
            new PropertyAccessor(),
        );
    }

    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
