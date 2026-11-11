<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * UserKit personal-data export section `invitations` (1.45; registered like {@see InvitationUserKitEraser}):
 * `{received: [...], sent: [...]}` from {@see InvitationPersonalDataExporterInterface::export()} for every subject of
 * the user. Never tokens, public ids or host payloads.
 */
final readonly class InvitationUserKitExportProvider implements PersonalDataExportProviderInterface
{
    public const SECTION = 'invitations';

    /** After host sections, before UserKit's built-ins (-100 and below); as the MFA section. */
    public const PRIORITY = -50;

    public function __construct(
        private InvitationPersonalDataExporterInterface $exporter,
        private InvitationPersonalDataSubjectResolverInterface $subjects,
    ) {
    }

    public function getSectionKey(): string
    {
        return self::SECTION;
    }

    public function supports(object $user): bool
    {
        return $user instanceof UserInterface;
    }

    /**
     * @return array{received: list<array<string, mixed>>, sent: list<array<string, mixed>>}
     */
    public function export(object $user): array
    {
        $section = ['received' => [], 'sent' => []];
        $seen    = [];
        foreach ($this->subjects->subjectsFor($user, null) as $subject) {
            if (isset($seen[$subject->key()])) {
                continue;
            }
            $seen[$subject->key()] = true;
            $part                  = $this->exporter->export($subject);
            foreach (['received', 'sent'] as $list) {
                foreach ($part[$list] as $entry) {
                    $section[$list][] = $entry;
                }
            }
        }

        return $section;
    }
}
