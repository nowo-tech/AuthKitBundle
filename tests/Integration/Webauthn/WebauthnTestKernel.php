<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The MFA kernel with passkeys (2.4.0): `webauthn.mode: enabled`, at most three passkeys, a fixed user-handle key, a
 * request attribute `csp_nonce` on every request (the script must carry it; the kernel is its own subscriber). `ROLE_ADMIN` requires a second factor.
 */
class WebauthnTestKernel extends MfaTestKernel implements EventSubscriberInterface
{
    public const NAME = 'webauthn';

    /** @var array<string, mixed> extra `webauthn` keys */
    protected const WEBAUTHN = [];

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['nonce', 512]];
    }

    public function nonce(RequestEvent $event): void
    {
        $event->getRequest()->attributes->set('csp_nonce', 'test-nonce');
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('nowo_auth_kit', [
            'webauthn' => static::WEBAUTHN + [
                'mode'            => 'enabled',
                'max_credentials' => 3,
                'hash_secret'     => 'webauthn-test-key',
            ],
        ]);
    }
}
