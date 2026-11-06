<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_map;
use function get_object_vars;
use function is_string;

/**
 * UserKit personal-data eraser `auth_kit_social_account` (1.37, GY-L5; registered with UserKit installed and the
 * entities mapped, whatever `social_login.mode` says): deletes the user's social links (provider subject, e-mail,
 * display name, raw profile, tokens) of the profile's user class. Runs before host erasers. DBAL delete, no flush, so a
 * host eraser doing the same afterwards just finds nothing. Tolerates a missing table.
 */
final readonly class SocialAccountPersonalDataEraser implements PersonalDataEraserInterface
{
    public const KEY = 'auth_kit_social_account';

    public const PRIORITY = 200;

    public function __construct(
        private ManagerRegistry $doctrine,
        private ProfileRegistry $profiles,
        private MfaOwnerResolver $owners,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function supports(object $user): bool
    {
        return $user instanceof UserInterface && $this->profiles->resolveForObject($user) instanceof ProfileSettings;
    }

    public function erase(ErasureRequest $request): void
    {
        $user    = $request->user;
        $profile = $user instanceof UserInterface ? $this->profiles->resolveForObject($user) : null;
        $manager = $this->doctrine->getManagerForClass(SocialLoginAccount::class);
        if (!$user instanceof UserInterface || !$profile instanceof ProfileSettings || !$manager instanceof EntityManagerInterface) {
            return;
        }
        $original = get_object_vars($request)['originalIdentifier'] ?? null;
        $ids      = array_map(static fn (MfaOwner $owner): string => $owner->id, $this->owners->erasureOwners($user, is_string($original) ? $original : null));
        $table    = $manager->getClassMetadata(SocialLoginAccount::class)->getTableName();
        if ($ids === [] || !$manager->getConnection()->createSchemaManager()->tablesExist([$table])) {
            return;
        }
        $manager->getConnection()->executeStatement(
            'DELETE FROM ' . $table . ' WHERE user_class = :class AND user_id IN (:ids)',
            ['class' => $profile->userClass, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
    }
}
