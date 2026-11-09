<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User;

use Nowo\AuthKitBundle\Admin\AdminUserCreator;

/**
 * Which AuthKit flow created a user (1.41): {@see NewUser::$source}, {@see Event\UserRegisteredEvent::$source}.
 */
enum UserCreationSource: string
{
    /** The registration page (`registration_mode` always / first_user_only). */
    case Form = 'form';

    /** An accepted invitation. */
    case Invitation = 'invitation';

    /** First social sign-in with `social_login.create_user_if_missing`. */
    case Social = 'social';

    /** `nowo:auth-kit:create-admin` / {@see AdminUserCreator}. */
    case Admin = 'admin';
}
