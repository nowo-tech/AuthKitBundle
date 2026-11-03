<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount\Twig;

use Symfony\Component\Form\FormView;

/**
 * View model of the account switcher (1.34, `auth_kit_accounts()`): the accounts (active first), the add form when
 * one more fits, the "sign out of all accounts" form and the chooser URL.
 */
final readonly class AccountSwitcherView
{
    /**
     * @param list<AccountRow> $accounts
     */
    public function __construct(
        public string $profile,
        public array $accounts,
        public ?FormView $addForm,
        public ?FormView $signOutAllForm,
        public string $chooserUrl,
        public bool $crossTabNotice,
        public ?string $activeId,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->accounts === [];
    }
}
