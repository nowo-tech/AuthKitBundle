<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\AuthKitBundle\Doctrine\TablePrefixListener;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TablePrefixListenerTest extends TestCase
{
    #[Test]
    public function emptyPrefixLeavesNamesUnchanged(): void
    {
        $metadata = $this->metadata(QrLoginChallenge::class, 'auth_kit_qr_login_challenge');

        (new TablePrefixListener(''))->loadClassMetadata($this->event($metadata));

        self::assertSame('auth_kit_qr_login_challenge', $metadata->getTableName());
        self::assertArrayHasKey('idx_qr_challenge_status_expires', $metadata->table['indexes'] ?? []);
    }

    #[Test]
    public function hostEntitiesAreIgnored(): void
    {
        $metadata = $this->metadata(stdClass::class, 'user');

        (new TablePrefixListener('app_'))->loadClassMetadata($this->event($metadata));

        self::assertSame('user', $metadata->getTableName());
        self::assertArrayHasKey('idx_qr_challenge_status_expires', $metadata->table['indexes'] ?? []);
    }

    #[Test]
    public function authKitTablesAndNamedIndexesArePrefixed(): void
    {
        $metadata                             = $this->metadata(QrLoginChallenge::class, 'auth_kit_qr_login_challenge');
        $metadata->table['uniqueConstraints'] = [
            'uniq_code' => ['columns' => ['public_code']],
            0           => ['columns' => ['status']],
        ];

        (new TablePrefixListener('app_'))->loadClassMetadata($this->event($metadata));

        self::assertSame('app_auth_kit_qr_login_challenge', $metadata->getTableName());
        self::assertSame(['app_idx_qr_challenge_status_expires'], array_keys($metadata->table['indexes'] ?? []));
        self::assertSame(['app_uniq_code', 0], array_keys($metadata->table['uniqueConstraints']));
    }

    #[Test]
    public function entitiesWithoutIndexesOnlyGetTheTablePrefix(): void
    {
        $metadata = new ClassMetadata(SocialLoginAccount::class);
        $metadata->setPrimaryTable(['name' => 'auth_kit_social_account']);

        (new TablePrefixListener('app_'))->loadClassMetadata($this->event($metadata));

        self::assertSame('app_auth_kit_social_account', $metadata->getTableName());
        self::assertArrayNotHasKey('indexes', $metadata->table);
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadata(string $class, string $table): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable([
            'name'    => $table,
            'indexes' => ['idx_qr_challenge_status_expires' => ['columns' => ['status', 'expires_at']]],
        ]);

        return $metadata;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function event(ClassMetadata $metadata): LoadClassMetadataEventArgs
    {
        return new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class));
    }
}
