<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Integration\MultiAccount\RenameIdentityEraser;
use Nowo\UserKitBundle\DependencyInjection\NowoUserKitExtension;
use Nowo\UserKitBundle\NowoUserKitBundle;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function sys_get_temp_dir;

/**
 * UserKit personal data with MFA and the account switcher off in every profile, mapped entities and a host identity
 * eraser that rewrites the e-mail.
 */
final class ErasureFeaturesOffTestKernel extends AuthKitTestKernel
{
    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();
        yield new NowoUserKitBundle();
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/erasure-off-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/erasure-off-' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('nowo_user_kit', ['user_class' => KernelUser::class, 'personal_data' => ['enabled' => true]]);
        $container->loadFromExtension('nowo_auth_kit', ['doctrine' => ['register_mapping' => true]]);
        $container->register(RenameIdentityEraser::class)
            ->setArgument('$em', new Reference('doctrine.orm.entity_manager'))
            ->addTag(NowoUserKitExtension::TAG_ERASER, ['priority' => RenameIdentityEraser::PRIORITY]);
        $container->setAlias('test.personal_data.eraser', PersonalDataEraser::class)->setPublic(true);
    }
}
