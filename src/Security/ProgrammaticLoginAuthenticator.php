<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

use Nowo\AuthKitBundle\DependencyInjection\Compiler\ProgrammaticLoginAuthenticatorPass;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Symfony\Contracts\Service\ServiceProviderInterface;

use function array_filter;
use function array_keys;
use function count;
use function in_array;
use function sprintf;

/**
 * The authenticator name for a `Security::login()` that has no authenticator of its own (social, QR): null (Symfony
 * takes the firewall's only one) unless the firewall has several, where Symfony would refuse with "Too many
 * authenticators". Then `form_login`, as registration and invitations use, else `login_link`: both only build a token
 * here, and the first factor is marked apart ({@see MfaFirstFactor}). Several others stay null:
 * guessing among unknown host authenticators could sign in through the wrong one, so Symfony's error stands.
 *
 * Stateless (worker safe). Fed by {@see ProgrammaticLoginAuthenticatorPass}.
 */
final readonly class ProgrammaticLoginAuthenticator
{
    private const PREFERRED = ['form_login', 'login_link'];

    /**
     * @param array<string, ServiceProviderInterface<object>|null> $authenticators firewall => its authenticators, as `security.helper` holds them
     */
    public function __construct(
        private array $authenticators = [],
    ) {
    }

    public function nameFor(string $firewall): ?string
    {
        $locator = $this->authenticators[$firewall] ?? null;
        if (!$locator instanceof ServiceProviderInterface) {
            return null;
        }
        $rememberMe = sprintf('security.authenticator.remember_me.%s', $firewall);
        $ids        = array_filter(array_keys($locator->getProvidedServices()), static fn (string $id): bool => $id !== $rememberMe);
        if (count($ids) < 2) {
            return null;
        }
        foreach (self::PREFERRED as $name) {
            if (in_array(sprintf('security.authenticator.%s.%s', $name, $firewall), $ids, true)) {
                return $name;
            }
        }

        return null;
    }
}
