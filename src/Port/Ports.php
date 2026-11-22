<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use DateTimeImmutable;
use LogicException;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\AuthKitPortsPass;
use Nowo\AuthKitBundle\Invitation\InvitationGrantHandlerInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionPolicyInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessPolicyInterface;
use Nowo\AuthKitBundle\Mfa\MfaRequirementPolicyInterface;
use Nowo\AuthKitBundle\MultiAccount\AccountPresentationProviderInterface;
use Nowo\AuthKitBundle\MultiAccount\EnrolmentPolicyInterface;
use Nowo\AuthKitBundle\Port\Internal\AccountPresenterInterface;
use Nowo\AuthKitBundle\Port\Internal\EnrolmentGateInterface;
use Nowo\AuthKitBundle\Port\Internal\InvitationActionAuthorizerInterface;
use Nowo\AuthKitBundle\Port\Internal\InvitationGrantInterface;
use Nowo\AuthKitBundle\Port\Internal\InvitationsAccessCheckerInterface;
use Nowo\AuthKitBundle\Port\Internal\MfaPolicyInterface;

use function get_debug_type;
use function sprintf;

/**
 * Wraps a host port service in the gate AuthKit asks it through (REQ-ARCH-001 rule 3; 2.0: the 1.46 ports are the only
 * ones), per object at call time:
 *
 * - it implements the port: it is wrapped in the port's gate (per call, stateless);
 * - it does not: null for the per-profile ports (the caller keeps its built-in or fails closed; the build already
 *   failed for a class known before compilation, see {@see AuthKitPortsPass}), and a `LogicException` for the tagged
 *   ports (fail closed).
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
            $service instanceof MfaRequirementPolicyInterface => new MfaRequirementPolicyGate($service),
            default                                           => null,
        };
    }

    public static function accountPresenter(mixed $service): ?AccountPresenterInterface
    {
        return match (true) {
            $service instanceof AccountPresentationProviderInterface => new AccountPresentationGate($service),
            default                                                  => null,
        };
    }

    public static function enrolmentGate(mixed $service): ?EnrolmentGateInterface
    {
        return match (true) {
            $service instanceof EnrolmentPolicyInterface => new EnrolmentPolicyGate($service),
            default                                      => null,
        };
    }

    public static function invitationsAccessChecker(mixed $service): ?InvitationsAccessCheckerInterface
    {
        return match (true) {
            $service instanceof InvitationsAccessPolicyInterface => new InvitationsAccessPolicyGate($service),
            default                                              => null,
        };
    }

    /**
     * @throws LogicException when the tagged service implements neither interface
     */
    public static function invitationActionAuthorizer(mixed $service, DateTimeImmutable $now): InvitationActionAuthorizerInterface
    {
        return match (true) {
            $service instanceof InvitationActionPolicyInterface => new InvitationActionPolicyGate($service, $now),
            default                                             => throw self::neither($service, InvitationActionPolicyInterface::class),
        };
    }

    /**
     * @throws LogicException when the tagged service implements neither interface
     */
    public static function invitationGrant(mixed $service): InvitationGrantInterface
    {
        return match (true) {
            $service instanceof InvitationGrantHandlerInterface => new InvitationGrantHandlerGate($service),
            default                                             => throw self::neither($service, InvitationGrantHandlerInterface::class),
        };
    }

    private static function neither(mixed $service, string $interface): LogicException
    {
        return new LogicException(sprintf('The AuthKit tagged service "%s" must implement "%s".', get_debug_type($service), $interface));
    }
}
