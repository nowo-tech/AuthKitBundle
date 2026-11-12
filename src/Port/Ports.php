<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use DateTimeImmutable;
use LogicException;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\AuthKitPortsPass;
use Nowo\AuthKitBundle\Invitation\InvitationGrantHandlerInterface;
use Nowo\AuthKitBundle\Invitation\InvitationGrantInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionPolicyInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessCheckerInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessPolicyInterface;
use Nowo\AuthKitBundle\Mfa\MfaPolicyInterface;
use Nowo\AuthKitBundle\Mfa\MfaRequirementPolicyInterface;
use Nowo\AuthKitBundle\MultiAccount\AccountPresentationProviderInterface;
use Nowo\AuthKitBundle\MultiAccount\AccountPresenterInterface;
use Nowo\AuthKitBundle\MultiAccount\EnrolmentGateInterface;
use Nowo\AuthKitBundle\MultiAccount\EnrolmentPolicyInterface;

use function get_debug_type;
use function sprintf;

/**
 * Picks the interface the bundle asks a host service through (1.46.0, REQ-ARCH-001 rule 3), per object at call time:
 *
 * - the service implements the deprecated interface: it is returned as is and asked exactly as in 1.45 (also when it
 *   implements the 1.46 twin too, until 2.0; a decorator of it decides by its own class);
 * - it implements only the 1.46 twin: it is wrapped in the twin's gate (per call, stateless);
 * - neither: null for the per-profile ports (the caller keeps its 1.45 fallback; the build already failed for a class
 *   known before compilation, see {@see AuthKitPortsPass}), and a
 *   `LogicException` for the tagged ports (fail closed).
 *
 * Stateless.
 *
 * @internal
 */
final class Ports
{
    public static function mfaPolicy(mixed $service): ?MfaPolicyInterface
    {
        return match (true) {
            $service instanceof MfaPolicyInterface            => $service,
            $service instanceof MfaRequirementPolicyInterface => new MfaRequirementPolicyGate($service),
            default                                           => null,
        };
    }

    public static function accountPresenter(mixed $service): ?AccountPresenterInterface
    {
        return match (true) {
            $service instanceof AccountPresenterInterface            => $service,
            $service instanceof AccountPresentationProviderInterface => new AccountPresentationGate($service),
            default                                                  => null,
        };
    }

    public static function enrolmentGate(mixed $service): ?EnrolmentGateInterface
    {
        return match (true) {
            $service instanceof EnrolmentGateInterface   => $service,
            $service instanceof EnrolmentPolicyInterface => new EnrolmentPolicyGate($service),
            default                                      => null,
        };
    }

    public static function invitationsAccessChecker(mixed $service): ?InvitationsAccessCheckerInterface
    {
        return match (true) {
            $service instanceof InvitationsAccessCheckerInterface => $service,
            $service instanceof InvitationsAccessPolicyInterface  => new InvitationsAccessPolicyGate($service),
            default                                               => null,
        };
    }

    /**
     * @throws LogicException when the tagged service implements neither interface
     */
    public static function invitationActionAuthorizer(mixed $service, DateTimeImmutable $now): InvitationActionAuthorizerInterface
    {
        return match (true) {
            $service instanceof InvitationActionAuthorizerInterface => $service,
            $service instanceof InvitationActionPolicyInterface     => new InvitationActionPolicyGate($service, $now),
            default                                                 => throw self::neither($service, InvitationActionPolicyInterface::class),
        };
    }

    /**
     * @throws LogicException when the tagged service implements neither interface
     */
    public static function invitationGrant(mixed $service): InvitationGrantInterface
    {
        return match (true) {
            $service instanceof InvitationGrantInterface        => $service,
            $service instanceof InvitationGrantHandlerInterface => new InvitationGrantHandlerGate($service),
            default                                             => throw self::neither($service, InvitationGrantHandlerInterface::class),
        };
    }

    private static function neither(mixed $service, string $interface): LogicException
    {
        return new LogicException(sprintf('The AuthKit tagged service "%s" must implement "%s".', get_debug_type($service), $interface));
    }
}
