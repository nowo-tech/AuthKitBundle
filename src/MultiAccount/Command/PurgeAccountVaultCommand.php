<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount\Command;

use DateTimeImmutable;
use Nowo\AuthKitBundle\MultiAccount\AccountVaultStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * Deletes the account-switcher entries whose `max_lifetime` passed (1.34). Entries are also removed lazily when a
 * browser presents them; run this daily from a scheduler for the browsers that never come back.
 */
#[AsCommand(name: 'nowo:auth-kit:accounts:purge', description: 'Delete expired account-switcher entries')]
final class PurgeAccountVaultCommand extends Command
{
    public function __construct(
        private readonly AccountVaultStore $store,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->store->purge(DateTimeImmutable::createFromInterface($this->clock->now()));
        (new SymfonyStyle($input, $output))->success(sprintf('%d expired account entr%s deleted.', $deleted, $deleted === 1 ? 'y' : 'ies'));

        return Command::SUCCESS;
    }
}
