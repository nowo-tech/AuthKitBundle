<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_array;
use function sprintf;

/**
 * Fails closed when a profile enables a CSRF-dependent flow without form CSRF protection (SEC-004 P2).
 *
 * QR login approval (a signed-in phone approves a desktop challenge) and the magic-login confirm interstitial are
 * protected by the Symfony form CSRF extension. A host that disables `framework.form.csrf_protection` (or has no CSRF
 * token manager) would silently turn those checks off, letting a third-party page approve an attacker's QR challenge
 * with the victim's session; the container refuses to compile instead.
 */
final class CsrfProtectionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_auth_kit.profiles')) {
            return;
        }

        $profiles = $container->getParameter('nowo_auth_kit.profiles');
        if (!is_array($profiles)) {
            return;
        }

        $feature = null;
        foreach ($profiles as $name => $profile) {
            if (!is_array($profile)) {
                continue;
            }
            if (($profile['qr_login']['mode'] ?? 'disabled') !== 'disabled') {
                $feature = sprintf('profiles.%s.qr_login', $name);

                break;
            }
            if (($profile['magic_login']['mode'] ?? 'disabled') !== 'disabled' && ($profile['magic_login']['confirm_interstitial'] ?? false) === true) {
                $feature = sprintf('profiles.%s.magic_login.confirm_interstitial', $name);

                break;
            }
        }

        if ($feature === null) {
            return;
        }

        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;

        if (!$container->has('security.csrf.token_manager') || !$container->hasDefinition('form.type_extension.csrf') || $formCsrfDisabled) {
            throw new LogicException(sprintf('NowoAuthKitBundle: %s requires CSRF protection. Enable framework.csrf_protection and framework.form.csrf_protection (symfony/security-csrf), or disable the feature.', $feature));
        }
    }
}
