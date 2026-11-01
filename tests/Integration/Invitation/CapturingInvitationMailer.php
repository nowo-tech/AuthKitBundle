<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use Nowo\AuthKitBundle\Invitation\InvitationMail;
use Nowo\AuthKitBundle\Invitation\InvitationMailerInterface;

use function count;

/**
 * Keeps the invitation mails so kernel tests can follow the link; `fail` simulates a transport error.
 */
final class CapturingInvitationMailer implements InvitationMailerInterface
{
    /** @var list<InvitationMail> */
    public array $sent = [];

    public bool $fail = false;

    public function send(InvitationMail $mail): bool
    {
        if ($this->fail) {
            return false;
        }
        $this->sent[] = $mail;

        return true;
    }

    public function last(): ?InvitationMail
    {
        return $this->sent[count($this->sent) - 1] ?? null;
    }
}
