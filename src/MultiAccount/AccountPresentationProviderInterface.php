<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * How an account appears in the switcher (1.46.0, `multi_account.presenter`): a framework-free port (REQ-ARCH-001
 * rule 3; 2.0: replaces the removed `AccountPresenterInterface`). The only data of one account another account of the
 * browser sees.
 */
interface AccountPresentationProviderInterface
{
    public function presentationFor(AuthUserRef $user): AccountPresentation;
}
