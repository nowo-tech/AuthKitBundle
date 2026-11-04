<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Nowo\UiKitBundle\Avatar\AvatarUrlPolicy;

use function mb_strlen;
use function mb_strpos;
use function mb_strtoupper;
use function mb_substr;
use function preg_split;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Display data of one account (name, identifier for the secondary line, optional avatar URL).
 *
 * `avatarUrl` (from the host's {@see AccountPresenterInterface}) should be stable and access-checked when the browser
 * fetches it (no bearer secret). Since 1.35 the switcher renders it only through {@see avatarUrlFor()}: UiKit's avatar
 * URL policy (`https://` without userinfo, or a same-origin `/path`) and, by default, for the active account only.
 */
final readonly class AccountPresentation
{
    public function __construct(
        public string $name,
        public string $identifier,
        public ?string $avatarUrl = null,
    ) {
    }

    /**
     * Initials for an avatar placeholder (two letters at most).
     */
    public function initials(): string
    {
        $initials = '';
        foreach (preg_split('/[\s._@-]+/u', $this->name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            if (mb_strlen($initials) === 2) {
                break;
            }
        }

        return $initials;
    }

    /**
     * Initials as shown with `identifier_display` (1.35, audit EC-I1): with `full`, or when the name is the presenter's
     * own display name (shown in full anyway), {@see initials()}; when the name is the identifier and it is masked or
     * hidden, only the first letter of the masked value (the letter the page already shows), never the domain's.
     */
    public function initialsFor(string $display): string
    {
        if ($display === 'full' || $this->name !== $this->identifier) {
            return $this->initials();
        }

        return mb_strtoupper(mb_substr($this->identifier, 0, 1));
    }

    /**
     * The avatar URL the switcher may show (1.35): null unless it passes UiKit's `AvatarUrlPolicy` and the account is
     * the active one or `$otherAccounts` allows the other accounts of the browser (`multi_account.chooser
     * .other_account_avatars`, applied only while the browser is signed in and never on anonymous pages). Their
     * pictures are fetched with the active session's cookie, which the host may refuse.
     */
    public function avatarUrlFor(bool $active, bool $otherAccounts = false): ?string
    {
        return $active || $otherAccounts ? AvatarUrlPolicy::sanitize($this->avatarUrl) : null;
    }

    /**
     * The identifier as shown on the sign-in page (D4): `full`, `masked` (`a•••@example.com`) or `none`.
     */
    public function identifierFor(string $display): ?string
    {
        if ($display === 'none') {
            return null;
        }
        if ($display === 'full') {
            return $this->identifier;
        }
        $at = mb_strpos($this->identifier, '@');

        return mb_substr($this->identifier, 0, 1) . '•••' . ($at !== false ? mb_substr($this->identifier, $at) : '');
    }

    /**
     * The name as shown on the sign-in page: never the full identifier when it is masked.
     */
    public function nameFor(string $display): string
    {
        return $this->name === $this->identifier && $display !== 'full' ? (string) $this->identifierFor('masked') : $this->name;
    }
}
