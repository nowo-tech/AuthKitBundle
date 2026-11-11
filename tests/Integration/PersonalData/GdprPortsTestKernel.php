<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PersonalData;

use Nowo\AuthKitBundle\PersonalData\InvitationPersonalDataEraserInterface;
use Nowo\AuthKitBundle\PersonalData\InvitationPersonalDataExporterInterface;
use Nowo\AuthKitBundle\PersonalData\SocialAccountPersonalDataExporterInterface;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Integration\MultiAccount\RenameIdentityEraser;
use Nowo\UserKitBundle\DependencyInjection\NowoUserKitExtension;
use Nowo\UserKitBundle\NowoUserKitBundle;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporter;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function sys_get_temp_dir;

/**
 * UserKit personal data with the GDPR ports (1.45): mapped entities, invitations and social login off, a host identity
 * eraser (priority 90) that rewrites the e-mail. `PERSONAL_DATA` false: `nowo_auth_kit.personal_data.enabled: false`.
 */
class GdprPortsTestKernel extends AuthKitTestKernel
{
    protected const PERSONAL_DATA = true;

    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();
        yield new NowoUserKitBundle();
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/gdpr-ports-' . (static::PERSONAL_DATA ? 'on' : 'off') . '-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/gdpr-ports-' . (static::PERSONAL_DATA ? 'on' : 'off') . '-' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('nowo_user_kit', ['user_class' => KernelUser::class, 'personal_data' => ['enabled' => true]]);
        $container->loadFromExtension('nowo_auth_kit', ['doctrine' => ['register_mapping' => true], 'personal_data' => ['enabled' => static::PERSONAL_DATA]]);
        $container->register(RenameIdentityEraser::class)
            ->setArgument('$em', new Reference('doctrine.orm.entity_manager'))
            ->addTag(NowoUserKitExtension::TAG_ERASER, ['priority' => RenameIdentityEraser::PRIORITY]);
        $container->setAlias('test.personal_data.eraser', PersonalDataEraser::class)->setPublic(true);
        $container->setAlias('test.personal_data.exporter', PersonalDataExporter::class)->setPublic(true);
        $container->setAlias('test.invitation_eraser', InvitationPersonalDataEraserInterface::class)->setPublic(true);
        $container->setAlias('test.invitation_exporter', InvitationPersonalDataExporterInterface::class)->setPublic(true);
        $container->setAlias('test.social_exporter', SocialAccountPersonalDataExporterInterface::class)->setPublic(true);
    }
}
