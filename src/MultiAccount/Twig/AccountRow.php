<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount\Twig;

use Symfony\Component\Form\FormView;

/**
 * One account of the switcher partial (1.34). `identifier` is already masked when the page asks for it; `avatarUrl`
 * (1.35) already passed the avatar URL policy and is null for the other accounts unless `chooser.other_account_avatars`.
 */
final readonly class AccountRow
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $identifier,
        public ?string $avatarUrl,
        public string $initials,
        public string $state,
        public ?FormView $switchForm,
        public FormView $signOutForm,
    ) {
    }

    public function isActive(): bool
    {
        return $this->state === 'active';
    }
}
