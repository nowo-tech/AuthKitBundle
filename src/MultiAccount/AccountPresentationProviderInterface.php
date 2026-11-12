<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * How an account appears in the switcher (1.46.0, `multi_account.presenter`): the framework-free twin of the
 * deprecated {@see AccountPresenterInterface} (REQ-ARCH-001 rule 3). The only data of one account another account of
 * the browser sees. A service implementing both interfaces is asked through the deprecated one until 2.0.
 */
interface AccountPresentationProviderInterface
{
    public function presentationFor(AuthUserRef $user): AccountPresentation;
}
