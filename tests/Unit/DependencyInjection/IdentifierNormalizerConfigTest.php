<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\AuthKitPortsPass;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Identity\ExactIdentifierNormalizer;
use Nowo\AuthKitBundle\Identity\LoginIdentifierNormalizationListener;
use Nowo\AuthKitBundle\Identity\LowercaseAsciiEmailIdentifierNormalizer;
use Nowo\AuthKitBundle\Identity\NormalizingUserLookup;
use Nowo\AuthKitBundle\Identity\UserIdentifierNormalizerInterface;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * 1.49.0: `nowo_auth_kit.identifier_normalizer` selects the normaliser behind the port; the lookup decorator and the
 * sign-in listener are always wired.
 */
final class IdentifierNormalizerConfigTest extends TestCase
{
    private const USER = 'App\\Entity\\User';

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function choices(): iterable
    {
        yield 'not set' => [[], ExactIdentifierNormalizer::class];
        yield 'null' => [['identifier_normalizer' => null], ExactIdentifierNormalizer::class];
        yield 'exact' => [['identifier_normalizer' => 'exact'], ExactIdentifierNormalizer::class];
        yield 'lowercase_ascii_email' => [['identifier_normalizer' => 'lowercase_ascii_email'], LowercaseAsciiEmailIdentifierNormalizer::class];
        yield 'service id' => [['identifier_normalizer' => 'app.identifier_normalizer'], 'app.identifier_normalizer'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('choices')]
    public function testTheOptionPointsThePortAtTheChosenNormalizer(array $config, string $expected): void
    {
        $container = $this->load($config);

        self::assertSame($expected, (string) $container->getAlias(UserIdentifierNormalizerInterface::class));
        self::assertFalse($container->getAlias(UserIdentifierNormalizerInterface::class)->isPublic());
        self::assertTrue($container->hasDefinition(ExactIdentifierNormalizer::class));
        self::assertTrue($container->hasDefinition(LowercaseAsciiEmailIdentifierNormalizer::class));

        $decorator = $container->getDefinition(NormalizingUserLookup::class);
        self::assertSame([UserLookupInterface::class, null, -1024], $decorator->getDecoratedService());

        $listener = $container->getDefinition(LoginIdentifierNormalizationListener::class);
        self::assertSame([['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => LoginIdentifierNormalizationListener::PRIORITY]], $listener->getTag('kernel.event_listener'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['  '];
        yield 'list' => [['lowercase_ascii_email']];
    }

    #[DataProvider('invalid')]
    public function testAnInvalidValueIsRefused(mixed $value): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['identifier_normalizer' => $value]);
    }

    public function testTheBuildFailsWhenTheNormalizerDoesNotImplementThePort(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.identifier_normalizer', stdClass::class);
        $container->setAlias(UserIdentifierNormalizerInterface::class, 'app.identifier_normalizer');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the identifier normaliser "app.identifier_normalizer" (stdClass) must implement');
        (new AuthKitPortsPass())->process($container);
    }

    public function testAnImplementationOrAnUnknownClassPassesTheBuild(): void
    {
        $container = new ContainerBuilder();
        $container->register(LowercaseAsciiEmailIdentifierNormalizer::class, LowercaseAsciiEmailIdentifierNormalizer::class);
        $container->setAlias(UserIdentifierNormalizerInterface::class, LowercaseAsciiEmailIdentifierNormalizer::class);
        (new AuthKitPortsPass())->process($container);

        $factory = new ContainerBuilder();
        $factory->register('app.identifier_normalizer')->setFactory(['App\\Factory', 'create']);
        $factory->setAlias(UserIdentifierNormalizerInterface::class, 'app.identifier_normalizer');
        (new AuthKitPortsPass())->process($factory);

        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        (new NowoAuthKitExtension())->load([['user_class' => self::USER] + $config], $container);

        return $container;
    }
}
