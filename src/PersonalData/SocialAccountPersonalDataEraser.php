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
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_filter;
use function array_map;
use function array_values;
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
        $original   = get_object_vars($request)['originalIdentifier'] ?? null;
        $original   = is_string($original) ? $original : null;
        $ids        = array_map(static fn (MfaOwner $owner): string => $owner->id, $this->owners->erasureOwners($user, $original));
        $table      = $manager->getClassMetadata(SocialLoginAccount::class)->getTableName();
        $connection = $manager->getConnection();
        if ($ids === [] || !$connection->createSchemaManager()->tablesExist([$table])) {
            return;
        }
        // Before 1.37 the linker stored the identifier for an object id (a Uid), so those rows hold it in both columns. Both
        // must match: a username equal to another user's id must never reach that user's link (GY-M1).
        $wantedIds = [];
        foreach ($ids as $id) {
            $wantedIds[UserIdentifierMatcher::normalize($id)] = $id;
        }
        $legacy = [];
        foreach ([$user->getUserIdentifier(), $original] as $identifier) {
            if ($identifier !== null && $identifier !== '') {
                $legacy[UserIdentifierMatcher::normalize($identifier)] = $identifier;
            }
        }
        $rows = $connection->fetchAllAssociative(
            'SELECT id, user_id, user_identifier FROM ' . $table . ' WHERE user_class = :class AND (user_id IN (:ids) OR (user_id IN (:legacy) AND user_identifier IN (:legacy)))',
            ['class' => $profile->userClass, 'ids' => array_values($wantedIds), 'legacy' => array_values($legacy)],
            ['ids' => ArrayParameterType::STRING, 'legacy' => ArrayParameterType::STRING],
        );
        // GY-I8: an accent-insensitive collation also returns look-alikes of a natural key (jose@ for josé@).
        $rows = array_filter($rows, static function (array $row) use ($wantedIds, $legacy): bool {
            $userId = UserIdentifierMatcher::normalize((string) $row['user_id']);

            return isset($wantedIds[$userId]) || (isset($legacy[$userId]) && isset($legacy[UserIdentifierMatcher::normalize((string) $row['user_identifier'])]));
        });
        if ($rows !== []) {
            $connection->executeStatement('DELETE FROM ' . $table . ' WHERE id IN (:rows)', ['rows' => array_map(static fn (array $row): int => (int) $row['id'], array_values($rows))], ['rows' => ArrayParameterType::INTEGER]);
        }
    }
}
