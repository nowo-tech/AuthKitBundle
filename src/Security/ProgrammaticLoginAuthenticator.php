<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

use Nowo\AuthKitBundle\DependencyInjection\Compiler\ProgrammaticLoginAuthenticatorPass;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Psr\Log\LoggerInterface;
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
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Whether `Security::login()` can work on this firewall with {@see self::nameFor()}. Asked before anything is written:
     * a sign-in that cannot happen must not replace a password, create an account or consume an invitation (GY-L9). A
     * refusal is a host misconfiguration, so it is logged. Without the firewall list (a controller built by hand) there
     * is nothing to refuse on.
     */
    public function canSignIn(string $firewall): bool
    {
        if ($this->authenticators === []) {
            return true;
        }
        $ids     = $this->authenticatorIds($firewall);
        $canSign = count($ids) === 1 || $this->nameFor($firewall) !== null;
        if (!$canSign) {
            $this->logger?->error('AuthKit cannot sign in programmatically on firewall {firewall}: it needs one authenticator, or form_login or login_link among several.', ['firewall' => $firewall]);
        }

        return $canSign;
    }

    /**
     * For registration and invitations, which have always named `form_login`: the same answer on a firewall that has it
     * (its only authenticator, or preferred among several), and {@see self::nameFor()} on one that does not.
     */
    public function nameForAccountFlow(string $firewall): ?string
    {
        return $this->authenticators === [] ? 'form_login' : $this->nameFor($firewall);
    }

    public function nameFor(string $firewall): ?string
    {
        $ids = $this->authenticatorIds($firewall);
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

    /**
     * The same list `Security::login()` counts.
     *
     * @return array<int, string>
     */
    private function authenticatorIds(string $firewall): array
    {
        $locator = $this->authenticators[$firewall] ?? null;
        if (!$locator instanceof ServiceProviderInterface) {
            return [];
        }
        $rememberMe = sprintf('security.authenticator.remember_me.%s', $firewall);

        return array_filter(array_keys($locator->getProvidedServices()), static fn (string $id): bool => $id !== $rememberMe);
    }
}
