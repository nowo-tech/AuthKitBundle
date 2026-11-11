<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

/**
 * SPI (1.45): the invitation subjects of a user for the UserKit eraser and export section. Configure your service with
 * `nowo_auth_kit.personal_data.subject_resolver`; the default is {@see DefaultInvitationPersonalDataSubjectResolver}.
 */
interface InvitationPersonalDataSubjectResolverInterface
{
    /**
     * @param string|null $originalIdentifier the identifier captured when the erasure started (null for an export)
     *
     * @return list<InvitationPersonalDataSubject>
     */
    public function subjectsFor(object $user, ?string $originalIdentifier): array;
}
