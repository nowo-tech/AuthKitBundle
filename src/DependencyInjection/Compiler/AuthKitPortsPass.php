<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\AuthKitBundle\Identity\UserIdentifierNormalizerInterface;
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
use Nowo\AuthKitBundle\Port\Ports;
use Nowo\AuthKitBundle\Port\QrLoginStepUpGate;
use Nowo\AuthKitBundle\Port\UserCreatorGate;
use Nowo\AuthKitBundle\Port\UserDirectoryGate;
use Nowo\AuthKitBundle\Port\UserStoreGate;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpInterface;
use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpPolicyInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackStepInterface;
use Nowo\AuthKitBundle\User\UserCreatorInterface;
use Nowo\AuthKitBundle\User\UserDirectoryInterface;
use Nowo\AuthKitBundle\User\UserFactoryInterface;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use Nowo\AuthKitBundle\User\UserStoreInterface;
use Nowo\AuthKitBundle\User\UserWriterInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function is_a;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Wires the framework-free 1.46 twins of the host ports (REQ-ARCH-001 rule 3) and refuses a port service that
 * implements neither interface.
 *
 * Aliased ports (user lookup / factory / writer, QR step-up): when the application defines the 1.46 interface (an alias
 * or a service with that id, e.g. the autowiring alias of its only implementation), the deprecated interface is pointed
 * at an internal gate over it (`nowo_auth_kit.port.<name>`), so the bundle's consumers and every decorator of the
 * deprecated id (AuthKit's own Device Intelligence step-up included) keep working. When the application also points the
 * deprecated interface at another service than the built-in, the build fails (keep one). When both ids name the same
 * service (a class implementing both), nothing changes: it is asked through the deprecated interface until 2.0.
 *
 * Per-profile services (`mfa.policy`, `multi_account.enrolment_gate`, `multi_account.presenter`,
 * `invitations.security.access_checker`) and tagged services (`nowo_auth_kit.invitation_grant`,
 * `nowo_auth_kit.invitation_action_authorizer`, `nowo_auth_kit.social_callback_handler`): the build fails when the
 * class (known before compilation) implements neither the deprecated interface nor its twin. Which interface is asked
 * is decided per object at call time ({@see Ports}).
 *
 * Deprecations of the old interfaces reach the application through their `@deprecated` tag (Symfony's
 * DebugClassLoader, in debug mode), not from this pass.
 *
 * Runs before optimization, after the autoconfiguration tags are applied and before decorators.
 *
 * @internal
 */
final class AuthKitPortsPass implements CompilerPassInterface
{
    /** `[port, service id, configuration path]` of each per-profile port service (set by the extension). */
    public const SERVICES_PARAMETER = 'nowo_auth_kit.port_services';

    /** Service id prefix of the alias gates. */
    public const GATE_PREFIX = 'nowo_auth_kit.port.';

    /**
     * @var array<string, array{legacy: class-string, twin: class-string, gate: class-string, built_in: string}>
     */
    public const ALIASED = [
        'user_lookup'  => ['legacy' => UserLookupInterface::class, 'twin' => UserDirectoryInterface::class, 'gate' => UserDirectoryGate::class, 'built_in' => 'Nowo\\AuthKitBundle\\User\\Doctrine\\DoctrineUserLookup'],
        'user_factory' => ['legacy' => UserFactoryInterface::class, 'twin' => UserCreatorInterface::class, 'gate' => UserCreatorGate::class, 'built_in' => 'Nowo\\AuthKitBundle\\User\\PropertyAccessUserFactory'],
        'user_writer'  => ['legacy' => UserWriterInterface::class, 'twin' => UserStoreInterface::class, 'gate' => UserStoreGate::class, 'built_in' => 'Nowo\\AuthKitBundle\\User\\Doctrine\\DoctrineUserWriter'],
        'qr_step_up'   => ['legacy' => QrLoginStepUpInterface::class, 'twin' => QrLoginStepUpPolicyInterface::class, 'gate' => QrLoginStepUpGate::class, 'built_in' => 'Nowo\\AuthKitBundle\\QrLogin\\NullQrLoginStepUp'],
    ];

    /**
     * @var array<string, array{0: class-string, 1: class-string}> port => [deprecated, twin]
     */
    public const PER_PROFILE = [
        'mfa_policy'                 => [MfaPolicyInterface::class, MfaRequirementPolicyInterface::class],
        'enrolment_gate'             => [EnrolmentGateInterface::class, EnrolmentPolicyInterface::class],
        'account_presenter'          => [AccountPresenterInterface::class, AccountPresentationProviderInterface::class],
        'invitations_access_checker' => [InvitationsAccessCheckerInterface::class, InvitationsAccessPolicyInterface::class],
    ];

    /**
     * @var array<string, array{0: class-string, 1: class-string}> tag => [deprecated, twin]
     */
    public const TAGGED = [
        InvitationGrantInterface::TAG                       => [InvitationGrantInterface::class, InvitationGrantHandlerInterface::class],
        InvitationActionAuthorizerInterface::AUTHORIZER_TAG => [InvitationActionAuthorizerInterface::class, InvitationActionPolicyInterface::class],
        'nowo_auth_kit.social_callback_handler'             => [SocialCallbackHandlerInterface::class, SocialCallbackStepInterface::class],
    ];

    /**
     * @throws LogicException on an impossible or ambiguous wiring (see the class comment)
     */
    public function process(ContainerBuilder $container): void
    {
        foreach (self::ALIASED as $name => $port) {
            $this->wireAliased($container, $name, $port);
        }

        $services = $container->hasParameter(self::SERVICES_PARAMETER) ? $container->getParameter(self::SERVICES_PARAMETER) : [];
        $container->getParameterBag()->remove(self::SERVICES_PARAMETER);
        foreach (is_array($services) ? $services : [] as $entry) {
            if (!is_array($entry) || !is_string($entry[0] ?? null) || !is_string($entry[1] ?? null) || !isset(self::PER_PROFILE[$entry[0]])) {
                continue;
            }
            [$legacy, $twin] = self::PER_PROFILE[$entry[0]];
            $this->assertImplements($container, $entry[1], $legacy, $twin, is_string($entry[2] ?? null) ? $entry[2] : $entry[0]);
        }

        // 1.48.0: nowo_auth_kit.identifier_normalizer (or an application alias) must name a normaliser.
        if ($container->hasAlias(UserIdentifierNormalizerInterface::class)) {
            $normalizer = $this->resolve($container, UserIdentifierNormalizerInterface::class);
            $class      = $this->classOf($container, $normalizer);
            if ($class !== null && !is_a($class, UserIdentifierNormalizerInterface::class, true)) {
                throw new LogicException(sprintf('nowo_auth_kit: the identifier normaliser "%s" (%s) must implement "%s".', $normalizer, $class, UserIdentifierNormalizerInterface::class));
            }
        }

        foreach (self::TAGGED as $tag => [$legacy, $twin]) {
            foreach ($container->findTaggedServiceIds($tag) as $id => $tags) {
                $this->assertImplements($container, (string) $id, $legacy, $twin, sprintf('the tag "%s"', $tag));
            }
        }
    }

    /**
     * @param array{legacy: class-string, twin: class-string, gate: class-string, built_in: string} $port
     */
    private function wireAliased(ContainerBuilder $container, string $name, array $port): void
    {
        $twin = $port['twin'];
        if (!$container->hasAlias($twin) && !$container->hasDefinition($twin)) {
            return;
        }

        $legacy       = $port['legacy'];
        $twinService  = $this->resolve($container, $twin);
        $legacyTarget = $container->hasAlias($legacy) || $container->hasDefinition($legacy) ? $this->resolve($container, $legacy) : null;
        if ($legacyTarget === $twinService) {
            return; // One service implements both: asked through the deprecated interface until 2.0.
        }
        if ($legacyTarget !== null && $legacyTarget !== $port['built_in']) {
            throw new LogicException(sprintf('"%s" is served by "%s" and the deprecated "%s" by "%s": keep only one of them (the 1.46 interface).', $twin, $twinService, $legacy, $legacyTarget));
        }

        $class = $this->classOf($container, $twinService);
        if ($class !== null && !is_a($class, $twin, true)) {
            throw new LogicException(sprintf('The service "%s" (%s) behind "%s" must implement it.', $twinService, $class, $twin));
        }

        $arguments = [new Reference($twin)];
        if ($port['gate'] === QrLoginStepUpGate::class) {
            $arguments[] = new Reference(RequestProfileResolver::class);
            $arguments[] = new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        }

        $gateId = self::GATE_PREFIX . $name;
        $container->register($gateId, $port['gate'])->setArguments($arguments);
        $container->setAlias($legacy, $gateId)->setPublic(false);
    }

    /**
     * @throws LogicException when the class implements neither interface
     */
    private function assertImplements(ContainerBuilder $container, string $id, string $legacy, string $twin, string $option): void
    {
        $class = $this->classOf($container, $id);
        if ($class !== null && !is_a($class, $legacy, true) && !is_a($class, $twin, true)) {
            throw new LogicException(sprintf('nowo_auth_kit: the service "%s" (%s) of %s must implement "%s" (or the deprecated "%s").', $id, $class, $option, $twin, $legacy));
        }
    }

    private function resolve(ContainerBuilder $container, string $id): string
    {
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id        = (string) $container->getAlias($id);
        }

        return $id;
    }

    /**
     * @return class-string|null null when the class cannot be known before the container is compiled
     */
    private function classOf(ContainerBuilder $container, string $service): ?string
    {
        $id = $this->resolve($container, $service);
        if (!$container->hasDefinition($id)) {
            return null;
        }

        $definition = $container->getDefinition($id);
        if ($definition->getFactory() !== null || $definition->isSynthetic()) {
            return null;
        }

        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

        return is_string($class) && class_exists($class) ? $class : null;
    }
}
