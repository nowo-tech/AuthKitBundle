<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\PersonalData\DefaultInvitationPersonalDataSubjectResolver;
use Nowo\AuthKitBundle\PersonalData\DoctrineInvitationPersonalData;
use Nowo\AuthKitBundle\PersonalData\DoctrineSocialAccountPersonalDataExporter;
use Nowo\AuthKitBundle\PersonalData\InvitationPersonalDataEraserInterface;
use Nowo\AuthKitBundle\PersonalData\InvitationPersonalDataExporterInterface;
use Nowo\AuthKitBundle\PersonalData\InvitationPersonalDataSubjectResolverInterface;
use Nowo\AuthKitBundle\PersonalData\InvitationUserKitEraser;
use Nowo\AuthKitBundle\PersonalData\InvitationUserKitExportProvider;
use Nowo\AuthKitBundle\PersonalData\SocialAccountPersonalDataEraser;
use Nowo\AuthKitBundle\PersonalData\SocialAccountPersonalDataExporterInterface;
use Nowo\AuthKitBundle\PersonalData\SocialAccountUserKitExportProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * GDPR ports (1.45): ports always, UserKit adapters with mapping + `personal_data.enabled`.
 */
final class PersonalDataPortsExtensionTest extends TestCase
{
    public function testDefaultsRegisterPortsAndAdapters(): void
    {
        $container = $this->load(['doctrine' => ['register_mapping' => true]]);

        self::assertSame(DoctrineInvitationPersonalData::class, (string) $container->getAlias(InvitationPersonalDataEraserInterface::class));
        self::assertSame(DoctrineInvitationPersonalData::class, (string) $container->getAlias(InvitationPersonalDataExporterInterface::class));
        self::assertSame(DoctrineSocialAccountPersonalDataExporter::class, (string) $container->getAlias(SocialAccountPersonalDataExporterInterface::class));
        self::assertSame(DefaultInvitationPersonalDataSubjectResolver::class, (string) $container->getAlias(InvitationPersonalDataSubjectResolverInterface::class));
        self::assertSame([['priority' => 200]], $container->getDefinition(InvitationUserKitEraser::class)->getTag('nowo_user_kit.personal_data.eraser'));
        self::assertSame([['priority' => -50]], $container->getDefinition(InvitationUserKitExportProvider::class)->getTag('nowo_user_kit.personal_data.export_provider'));
        self::assertSame([['priority' => -50]], $container->getDefinition(SocialAccountUserKitExportProvider::class)->getTag('nowo_user_kit.personal_data.export_provider'));
    }

    public function testDisabledKeepsThePortsAndTheEarlierErasers(): void
    {
        $container = $this->load(['doctrine' => ['register_mapping' => true], 'personal_data' => ['enabled' => false, 'subject_resolver' => 'app.subjects']]);

        self::assertTrue($container->hasAlias(InvitationPersonalDataEraserInterface::class));
        self::assertSame('app.subjects', (string) $container->getAlias(InvitationPersonalDataSubjectResolverInterface::class));
        self::assertTrue($container->hasDefinition(SocialAccountPersonalDataEraser::class));
        self::assertFalse($container->hasDefinition(InvitationUserKitEraser::class));
        self::assertFalse($container->hasDefinition(InvitationUserKitExportProvider::class));
        self::assertFalse($container->hasDefinition(SocialAccountUserKitExportProvider::class));
    }

    public function testWithoutMappedEntitiesNoAdapter(): void
    {
        $container = $this->load(['doctrine' => ['register_mapping' => false]]);

        self::assertTrue($container->hasAlias(SocialAccountPersonalDataExporterInterface::class));
        self::assertFalse($container->hasDefinition(InvitationUserKitEraser::class));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoAuthKitExtension())->load([['user_class' => 'App\\Entity\\User'] + $config], $container);

        return $container;
    }
}
