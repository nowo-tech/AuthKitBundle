<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use function mb_strlen;
use function mb_strpos;
use function mb_strtoupper;
use function mb_substr;
use function preg_split;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Display data of one account (name, identifier for the secondary line, optional avatar URL).
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
