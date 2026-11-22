<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\AuthKitBundle\Identity\UserIdentifierNormalizerInterface;
use Nowo\AuthKitBundle\Invitation\InvitationGrantHandlerInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionPolicyInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessPolicyInterface;
use Nowo\AuthKitBundle\Mfa\MfaRequirementPolicyInterface;
use Nowo\AuthKitBundle\MultiAccount\AccountPresentationProviderInterface;
use Nowo\AuthKitBundle\MultiAccount\EnrolmentPolicyInterface;
use Nowo\AuthKitBundle\Port\Internal\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpPolicyInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackStepInterface;
use Nowo\AuthKitBundle\User\UserCreatorInterface;
use Nowo\AuthKitBundle\User\UserDirectoryInterface;
use Nowo\AuthKitBundle\User\UserStoreInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Throwable;

use function class_exists;
use function is_a;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Checks the host ports (REQ-ARCH-001 rule 3, framework-free since 1.46.0; 2.0: the only ones).
 *
 * - Aliased ports (user directory / creator / store, QR step-up): the service behind each port id (the built-in
 *   default, or the application's alias, decorators included) must implement the port. The AuthKit services ask it
 *   through an internal gate (`nowo_auth_kit.port.<name>`, services.yaml).
 * - Per-profile services (`mfa.policy`, `multi_account.enrolment_gate`, `multi_account.presenter`,
 *   `invitations.security.access_checker`) and tagged services (`nowo_auth_kit.invitation_grant`,
 *   `nowo_auth_kit.invitation_action_authorizer`, `nowo_auth_kit.social_callback_handler`): the build fails when the
 *   class (known before compilation) does not implement the port. AuthKit's own invitation acceptance page is the only
 *   social callback handler on the internal interface.
 *
 * A class that cannot be loaded because it still implements an interface removed in 2.0 fails the build with
 * {@see RemovedPortsPass::message()}, never with a fatal error later. {@see RemovedPortsPass} runs first and refuses
 * every service, alias, decorator and autodiscovered class on a removed interface.
 *
 * Runs before optimization, after the autoconfiguration tags are applied and before decorators.
 *
 * @internal
 */
final class AuthKitPortsPass implements CompilerPassInterface
{
    /** `[port, service id, configuration path]` of each per-profile port service (set by the extension). */
    public const SERVICES_PARAMETER = 'nowo_auth_kit.port_services';

    /** Service id prefix of the internal gates (services.yaml). */
    public const GATE_PREFIX = 'nowo_auth_kit.port.';

    /**
     * @var array<string, class-string> port name => port interface (aliased to the built-in by default)
     */
    public const ALIASED = [
        'user_lookup'  => UserDirectoryInterface::class,
        'user_factory' => UserCreatorInterface::class,
        'user_writer'  => UserStoreInterface::class,
        'qr_step_up'   => QrLoginStepUpPolicyInterface::class,
    ];

    /**
     * @var array<string, class-string> port => interface
     */
    public const PER_PROFILE = [
        'mfa_policy'                 => MfaRequirementPolicyInterface::class,
        'enrolment_gate'             => EnrolmentPolicyInterface::class,
        'account_presenter'          => AccountPresentationProviderInterface::class,
        'invitations_access_checker' => InvitationsAccessPolicyInterface::class,
    ];

    /**
     * @var array<string, class-string> tag => interface
     */
    public const TAGGED = [
        InvitationGrantHandlerInterface::TAG            => InvitationGrantHandlerInterface::class,
        InvitationActionPolicyInterface::AUTHORIZER_TAG => InvitationActionPolicyInterface::class,
        'nowo_auth_kit.social_callback_handler'         => SocialCallbackStepInterface::class,
    ];

    /**
     * @throws LogicException on a port service that does not implement its port
     */
    public function process(ContainerBuilder $container): void
    {
        foreach (self::ALIASED as $port) {
            if (!$container->hasAlias($port) && !$container->hasDefinition($port)) {
                continue;
            }
            $service = $this->resolve($container, $port);
            $class   = $this->classOf($container, $service);
            if ($class !== null && !is_a($class, $port, true)) {
                throw new LogicException(sprintf('The service "%s" (%s) behind "%s" must implement it.', $service, $class, $port));
            }
        }

        $services = $container->hasParameter(self::SERVICES_PARAMETER) ? $container->getParameter(self::SERVICES_PARAMETER) : [];
        $container->getParameterBag()->remove(self::SERVICES_PARAMETER);
        foreach (is_array($services) ? $services : [] as $entry) {
            if (!is_array($entry) || !is_string($entry[0] ?? null) || !is_string($entry[1] ?? null) || !isset(self::PER_PROFILE[$entry[0]])) {
                continue;
            }
            $this->assertImplements($container, $entry[1], self::PER_PROFILE[$entry[0]], is_string($entry[2] ?? null) ? $entry[2] : $entry[0]);
        }

        // 1.49.0: nowo_auth_kit.identifier_normalizer (or an application alias) must name a normaliser.
        if ($container->hasAlias(UserIdentifierNormalizerInterface::class)) {
            $normalizer = $this->resolve($container, UserIdentifierNormalizerInterface::class);
            $class      = $this->classOf($container, $normalizer);
            if ($class !== null && !is_a($class, UserIdentifierNormalizerInterface::class, true)) {
                throw new LogicException(sprintf('nowo_auth_kit: the identifier normaliser "%s" (%s) must implement "%s".', $normalizer, $class, UserIdentifierNormalizerInterface::class));
            }
        }

        foreach (self::TAGGED as $tag => $port) {
            foreach ($container->findTaggedServiceIds($tag) as $id => $tags) {
                $this->assertImplements($container, (string) $id, $port, sprintf('the tag "%s"', $tag));
            }
        }
    }

    /**
     * @param class-string $port
     *
     * @throws LogicException when the class does not implement the port
     */
    private function assertImplements(ContainerBuilder $container, string $id, string $port, string $option): void
    {
        $class = $this->classOf($container, $id);
        if ($class === null || is_a($class, $port, true) || $this->isOwnSocialCallbackHandler($class, $port)) {
            return;
        }

        throw new LogicException(sprintf('nowo_auth_kit: the service "%s" (%s) of %s must implement "%s".', $id, $class, $option, $port));
    }

    /**
     * AuthKit's own invitation acceptance page owns the social callback of an acceptance through the internal handler
     * interface (its tests too); an application class never does.
     */
    private function isOwnSocialCallbackHandler(string $class, string $port): bool
    {
        return $port === SocialCallbackStepInterface::class
            && is_a($class, SocialCallbackHandlerInterface::class, true)
            && str_starts_with($class, 'Nowo\\AuthKitBundle\\');
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
     * @throws LogicException when the class cannot be loaded because it implements an interface removed in 2.0
     *
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
        if (!is_string($class)) {
            return null;
        }

        try {
            return class_exists($class) ? $class : null;
        } catch (Throwable $failure) {
            $removed = RemovedPortsPass::named($failure->getMessage());
            if ($removed === null) {
                throw $failure;
            }

            throw new LogicException(RemovedPortsPass::message($id, $removed), 0, $failure);
        }
    }
}
