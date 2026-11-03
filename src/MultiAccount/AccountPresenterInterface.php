<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * How an account appears in the switcher (1.34, `multi_account.presenter`): the only data of one account another
 * account of the browser sees.
 */
interface AccountPresenterInterface
{
    public function present(UserInterface $user): AccountPresentation;
}
