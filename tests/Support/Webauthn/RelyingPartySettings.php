<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Webauthn;

/**
 * Stands for a host's database row of instance settings (2.6.0 tests): the RP id and origins an administrator saved
 * (null: fall back to the configuration), or a store that is down. Tests change it between requests, as another
 * process would change the row.
 */
final class RelyingPartySettings
{
    public ?string $rpId = null;

    /** @var list<string>|null */
    public ?array $origins = null;

    public bool $down = false;
}
