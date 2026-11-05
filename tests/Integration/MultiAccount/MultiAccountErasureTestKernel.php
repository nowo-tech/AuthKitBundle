<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\MultiAccount;

use Nowo\UserKitBundle\DependencyInjection\NowoUserKitExtension;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporter;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.36 (DATA-AUTHKIT-VAULT-ERASE, DATA-AUTHKIT-MFA-ERASE-EXPORT): the account-switcher kernel with UserKit personal
 * data and a host identity eraser that anonymises the identifier before UserKit's sessions eraser runs.
 */
final class MultiAccountErasureTestKernel extends MultiAccountTestKernel
{
    public const NAME = 'multi-account-erasure';

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('nowo_user_kit', ['personal_data' => ['enabled' => true]]);
        $container->register(RenameIdentityEraser::class)
            ->setArgument('$em', new Reference('doctrine.orm.entity_manager'))
            ->addTag(NowoUserKitExtension::TAG_ERASER, ['priority' => RenameIdentityEraser::PRIORITY]);
        $container->setAlias('test.personal_data.eraser', PersonalDataEraser::class)->setPublic(true);
        $container->setAlias('test.personal_data.exporter', PersonalDataExporter::class)->setPublic(true);
    }
}
