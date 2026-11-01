<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use function sys_get_temp_dir;

/**
 * The invitations kernel with `show_link_when_undelivered: true`.
 */
final class InvitationsShowLinkTestKernel extends InvitationsTestKernel
{
    protected const SHOW_LINK = true;

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/invitations-link-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/invitations-link-' . $this->environment . '/log';
    }
}
