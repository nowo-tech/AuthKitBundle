<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

/**
 * The host-API kernel with `show_link_when_undelivered: true` (SEC-004 CP N1: the shown-once link after a failed mail).
 */
final class InvitationsHostApiShowLinkTestKernel extends InvitationsHostApiTestKernel
{
    protected const SHOW_LINK = true;

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/invitations-host-show-link-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/invitations-host-show-link-' . $this->environment . '/log';
    }
}
