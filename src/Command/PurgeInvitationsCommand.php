<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Command;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Invitation\Event\InvitationsPurgedEvent;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\UnknownProfileException;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function ctype_digit;
use function is_string;
use function sprintf;

/**
 * Deletes closed invitations (personal data nobody needs any more): revoked ones and expired ones never accepted whose
 * last change is older than `--days` (default: the profile `invitations.purge_after_days`), in bounded batches.
 * `--include-accepted` also deletes accepted ones; `--dry-run` only counts; `--auth-profile` limits it to one profile.
 * Run it from a scheduler.
 */
#[AsCommand(
    name: 'nowo:auth-kit:invitations:purge',
    description: 'Delete revoked and expired invitations older than invitations.purge_after_days',
)]
final class PurgeInvitationsCommand extends Command
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly ProfileRegistry $profiles,
        private readonly ClockInterface $clock,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('auth-profile', null, InputOption::VALUE_REQUIRED, 'Only this AuthKit profile (default: every profile with invitations enabled)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Age in days (default: invitations.purge_after_days of each profile)')
            ->addOption('include-accepted', null, InputOption::VALUE_NONE, 'Also delete accepted invitations')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count only, delete nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $profile = $input->getOption('auth-profile');
        $days    = $input->getOption('days');
        if ($days !== null && (!is_string($days) || !ctype_digit($days) || (int) $days < 1)) {
            $io->error('--days must be a positive integer.');

            return Command::INVALID;
        }

        try {
            $selected = is_string($profile) && $profile !== '' ? [$this->profiles->getByName($profile)] : $this->profiles->all();
        } catch (UnknownProfileException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $dryRun          = (bool) $input->getOption('dry-run');
        $includeAccepted = (bool) $input->getOption('include-accepted');
        $now             = DateTimeImmutable::createFromInterface($this->clock->now());
        $total           = 0;
        foreach ($selected as $settings) {
            if (!$settings->invitationsEnabled()) {
                continue;
            }
            $age     = $days !== null ? (int) $days : (int) ($settings->invitations['purge_after_days'] ?? 90);
            $before  = $now->modify(sprintf('-%d days', $age));
            $deleted = $this->invitations->deleteClosedBefore($before, $settings->name, $includeAccepted, dryRun: $dryRun);
            $total += $deleted;
            $io->writeln(sprintf('%s: %d invitation(s) closed before %s%s.', $settings->name, $deleted, $before->format('Y-m-d'), $dryRun ? ' (dry run)' : ' deleted'));
            if (!$dryRun) {
                $this->eventDispatcher?->dispatch(new InvitationsPurgedEvent($deleted, $before, $settings->name, $includeAccepted));
            }
        }

        $io->success(sprintf($dryRun ? '%d invitation(s) would be deleted.' : 'Deleted %d invitation(s).', $total));

        return Command::SUCCESS;
    }
}
