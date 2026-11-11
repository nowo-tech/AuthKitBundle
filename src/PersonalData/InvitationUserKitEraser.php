<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_values;

/**
 * UserKit personal-data eraser `auth_kit_invitations` (1.45; registered with UserKit installed, the entities mapped and
 * `nowo_auth_kit.personal_data.enabled`, whatever `invitations.mode` says). Calls
 * {@see InvitationPersonalDataEraserInterface::erase()} once per distinct subject of the user
 * ({@see InvitationPersonalDataSubjectResolverInterface}, with `ErasureRequest::identifier()`), plus
 * `context['previous_email']` (as an e-mail and as an identifier) when the host passes a non-empty string. Priority
 * {@see self::PRIORITY}: before host identity erasers, while the account still has its e-mail. Runs inside UserKit's
 * transaction (no event, no flush).
 */
final readonly class InvitationUserKitEraser implements PersonalDataEraserInterface
{
    public const KEY = 'auth_kit_invitations';

    public const PRIORITY = 200;

    public function __construct(
        private InvitationPersonalDataEraserInterface $eraser,
        private InvitationPersonalDataSubjectResolverInterface $subjects,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function supports(object $user): bool
    {
        return $user instanceof UserInterface;
    }

    public function erase(ErasureRequest $request): void
    {
        $subjects = [];
        foreach ($this->subjects->subjectsFor($request->user, $request->identifier()) as $subject) {
            $subjects[$subject->key()] = $subject;
        }
        $previous = $request->context['previous_email'] ?? null;
        DefaultInvitationPersonalDataSubjectResolver::add($subjects, $previous, true);
        DefaultInvitationPersonalDataSubjectResolver::add($subjects, $previous, false);
        foreach (array_values($subjects) as $subject) {
            $this->eraser->erase($subject);
        }
    }
}
