<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

/**
 * GDPR export port of the social links (1.45): one entry per linked provider account, `provider`, `linked_at` (ATOM),
 * `provider_email` and `display_name`. Never the provider subject id, tokens or the raw provider profile. Exact
 * matching: the SQL comparison only pre-filters, every row is re-checked with `===`.
 *
 * Erasure of the links stays with the UserKit eraser `auth_kit_social_account` ({@see SocialAccountPersonalDataEraser}).
 */
interface SocialAccountPersonalDataExporterInterface
{
    /**
     * @return list<array{provider: string, linked_at: string|null, provider_email: string|null, display_name: string|null}>
     */
    public function export(SocialAccountPersonalDataSubject $subject): array;
}
