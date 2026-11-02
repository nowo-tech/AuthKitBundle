<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Why a host refused an acceptance (1.33), shown to the invitee on the "could not be accepted" page, e.g.
 * `new InvitationRefusal('invitations.no_seat_left', [], 'messages')`. A translation key in the host's domain, never
 * free text from the request: it is rendered escaped, without invitee data. Set it through
 * {@see Event\InvitationAcceptingEvent::refuse()} or {@see InvitationRefusedException::because()}.
 */
final readonly class InvitationRefusal implements TranslatableInterface
{
    /**
     * @param array<string, float|int|string|null> $parameters
     */
    public function __construct(
        public string $key,
        public array $parameters = [],
        public string $domain = 'messages',
    ) {
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->key, $this->parameters, $this->domain, $locale);
    }
}
