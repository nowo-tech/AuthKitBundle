<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Command;

use Nowo\AuthKitBundle\Admin\AdminUserCreator;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Profile\UnknownProfileException;
use Nowo\PasswordStrengthBundle\Validator\PasswordStrength;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function is_string;
use function sprintf;

use const FILTER_VALIDATE_EMAIL;

/**
 * Provisions an admin account for an AuthKit profile from the CLI.
 *
 * The password is always read from a hidden prompt (never an option or argument, so it stays out of
 * shell history and `ps`); without a TTY that can hide input, or in non-interactive mode, the command
 * refuses instead of echoing. Applies min_password_length and, when the profile enables
 * password_strength and nowo-tech/password-strength-bundle is installed, the same strength policy as
 * the registration form. Bypasses registration_mode on purpose (operators provision the first admin
 * when public registration is disabled).
 */
#[AsCommand(
    name: 'nowo:auth-kit:create-admin',
    description: 'Creates an admin user for an AuthKit profile (password read from a hidden prompt)',
)]
final class CreateAdminCommand extends Command
{
    public const STRENGTH_CONSTRAINT = 'Nowo\PasswordStrengthBundle\Validator\PasswordStrength';

    /**
     * @param list<string> $roles
     */
    public function __construct(
        private readonly AdminUserCreator $creator,
        private readonly int $minPasswordLength = 12,
        private readonly array $roles = ['ROLE_ADMIN'],
        private readonly ?ValidatorInterface $validator = null,
        private readonly string $strengthConstraintClass = self::STRENGTH_CONSTRAINT,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('identifier', InputArgument::REQUIRED, 'Login identifier (user_identifier_field, usually the email)')
            ->addOption('auth-profile', null, InputOption::VALUE_REQUIRED, 'AuthKit profile name (default: nowo_auth_kit.default_profile). Not --profile: Symfony reserves it for the console profiler')
            ->setHelp(<<<'HELP'
                Creates a user with the roles from <info>nowo_auth_kit.create_admin.roles</info> (default ROLE_ADMIN).
                The password is prompted twice with hidden input; it can never be passed on the command line,
                and the command refuses to run non-interactively.

                Listen to <info>Nowo\AuthKitBundle\Admin\AdminUserCreatedEvent</info> for follow-up work
                (e.g. mark an installation wizard as completed).
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $profileName = $input->getOption('auth-profile');
        $profileName = is_string($profileName) && $profileName !== '' ? $profileName : null;

        try {
            $profile = $this->creator->profile($profileName);
        } catch (UnknownProfileException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $identifier = trim((string) $input->getArgument('identifier'));
        if ($profile->userIdentifierField === 'email') {
            $identifier = mb_strtolower($identifier);
            if (filter_var($identifier, FILTER_VALIDATE_EMAIL) === false) {
                $io->error(sprintf('"%s" is not a valid email address.', $identifier));

                return Command::INVALID;
            }
        } elseif ($identifier === '') {
            $io->error('The identifier must not be empty.');

            return Command::INVALID;
        }

        if ($this->creator->exists($identifier, $profile->name)) {
            $io->error(sprintf('A user with %s "%s" already exists.', $profile->userIdentifierField, $identifier));

            return Command::FAILURE;
        }

        if (!$input->isInteractive()) {
            $io->error('The password is read from a hidden prompt: run this command in an interactive terminal.');

            return Command::INVALID;
        }

        try {
            $password = $this->askHidden($io, 'Password');
            if (mb_strlen($password) < $this->minPasswordLength) {
                $io->error(sprintf('The password must be at least %d characters long.', $this->minPasswordLength));

                return Command::INVALID;
            }

            $violation = $this->strengthViolation($profile, $password);
            if ($violation !== null) {
                $io->error($violation);

                return Command::INVALID;
            }

            if ($password !== $this->askHidden($io, 'Repeat password')) {
                $io->error('Passwords do not match.');

                return Command::INVALID;
            }
        } catch (RuntimeException $exception) {
            // Hidden input unavailable (no stty / TTY): never fall back to visible input.
            $io->error(sprintf('Cannot read the password from a hidden prompt: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        try {
            $this->creator->create($identifier, $password, $this->roles, $profile->name);
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Created admin %s (%s) in profile "%s".', $identifier, implode(', ', $this->roles), $profile->name));

        return Command::SUCCESS;
    }

    private function askHidden(SymfonyStyle $io, string $label): string
    {
        $question = new Question($label);
        $question->setHidden(true);
        $question->setHiddenFallback(false);

        return (string) $io->askQuestion($question);
    }

    private function strengthViolation(ProfileSettings $profile, string $password): ?string
    {
        if (($profile->passwordStrength['enabled'] ?? false) !== true
            || !$this->validator instanceof ValidatorInterface
            || !class_exists($this->strengthConstraintClass)
        ) {
            return null;
        }

        /** @var PasswordStrength $constraint */
        $constraint             = new ($this->strengthConstraintClass)();
        $constraint->policyMode = ($profile->passwordStrength['policy_mode'] ?? 'level') === 'conditions' ? 'conditions' : 'level';
        $constraint->level      = (string) ($profile->passwordStrength['level'] ?? 'medium');

        $violations = $this->validator->validate($password, $constraint);
        if ($violations->count() === 0) {
            return null;
        }

        return sprintf('The password does not meet the "%s" strength policy: %s', $constraint->level, (string) $violations->get(0)->getMessage());
    }
}
