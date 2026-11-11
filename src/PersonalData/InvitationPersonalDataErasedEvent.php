<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

/**
 * Dispatched after {@see InvitationPersonalDataEraserInterface::erase()} committed its own transaction and changed at
 * least one row (1.45). Not dispatched when the caller's transaction was open (UserKit's erasure): the returned result
 * is the outcome then. Carries the subject and the counts only.
 */
final readonly class InvitationPersonalDataErasedEvent
{
    public function __construct(
        public InvitationPersonalDataSubject $subject,
        public InvitationErasureResult $result,
    ) {
    }
}
