<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

use DateTimeInterface;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;

use function is_string;
use function strcmp;
use function usort;

/**
 * Default {@see SocialAccountPersonalDataExporterInterface} (1.45): a DBAL read on the connection of the entity manager
 * that maps {@see SocialLoginAccount}, ordered by provider. Nothing without that manager or table. No per-call state.
 */
final readonly class DoctrineSocialAccountPersonalDataExporter implements SocialAccountPersonalDataExporterInterface
{
    public function __construct(
        private ManagerRegistry $doctrine,
    ) {
    }

    public function export(SocialAccountPersonalDataSubject $subject): array
    {
        $manager = $this->doctrine->getManagerForClass(SocialLoginAccount::class);
        if (!$manager instanceof EntityManagerInterface) {
            return [];
        }
        $table      = $manager->getClassMetadata(SocialLoginAccount::class)->getTableName();
        $connection = $manager->getConnection();
        if (!$connection->createSchemaManager()->tablesExist([$table])) {
            return [];
        }
        $equals = ['user_class' => $subject->userClass, 'user_id' => $subject->value];
        if ($subject->legacy) {
            $equals['user_identifier'] = $subject->value;
        }
        $rows = ExactRows::find($connection, $table, $equals, ['provider', 'email', 'display_name', 'created_at']);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['provider'], (string) $b['provider']) ?: (int) $a['id'] <=> (int) $b['id']);
        $platform = $connection->getDatabasePlatform();
        $links    = [];
        foreach ($rows as $row) {
            $linkedAt = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($row['created_at'] ?? null, $platform);
            $links[]  = [
                'provider'       => (string) $row['provider'],
                'linked_at'      => $linkedAt instanceof DateTimeInterface ? $linkedAt->format(DateTimeInterface::ATOM) : null,
                'provider_email' => is_string($row['email'] ?? null) ? $row['email'] : null,
                'display_name'   => is_string($row['display_name'] ?? null) ? $row['display_name'] : null,
            ];
        }

        return $links;
    }
}
