<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\MultiAccount;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\MultiAccountPass;
use Nowo\AuthKitBundle\Entity\AccountVaultEntry;
use Nowo\AuthKitBundle\MultiAccount\AccountPresentation;
use Nowo\AuthKitBundle\MultiAccount\AccountVault;
use Nowo\AuthKitBundle\MultiAccount\AccountVaultStore;
use Nowo\AuthKitBundle\MultiAccount\DefaultEnrolmentGate;
use Nowo\AuthKitBundle\MultiAccount\VaultCookie;
use Nowo\AuthKitBundle\MultiAccount\VaultCrypto;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function base64_encode;
use function str_repeat;

final class MultiAccountUnitTest extends TestCase
{
    public function testCrypto(): void
    {
        $crypto = new VaultCrypto('secret');
        $token  = VaultCrypto::newToken();
        self::assertMatchesRegularExpression(VaultCrypto::TOKEN_PATTERN, $token);
        self::assertNotSame($crypto->hash($token), (new VaultCrypto('other'))->hash($token));
        $sealed = $crypto->seal($token, 'default|a', ['sid' => 'abc', 'rm' => null]);
        self::assertSame(['sid' => 'abc', 'rm' => null], $crypto->unseal($token, 'default|a', $sealed));
        self::assertNull($crypto->unseal(VaultCrypto::newToken(), 'default|a', $sealed), 'Another token opens nothing.');
        self::assertNull($crypto->unseal($token, 'default|b', $sealed), 'Bound to its entry.');
        self::assertNull($crypto->unseal($token, 'default|a', '!!!'));
        self::assertNull($crypto->unseal($token, 'default|a', base64_encode('short')));
        self::assertNull($crypto->unseal($token, 'default|a', base64_encode(str_repeat('x', 64))));
    }

    public function testCookieWriteSkipsProfilesWithoutAQueue(): void
    {
        $profiles = ProfileRegistryFactory::single(TestUser::class, ['multi_account' => ['enabled' => true, 'cookie' => ['name' => 'v']]]);
        $request  = Request::create('https://app.example.test/');
        $response = new Response();
        VaultCookie::write($request, $response, $profiles->all());
        self::assertSame([], $response->headers->getCookies());
        $request->attributes->set(VaultCookie::QUEUE_ATTRIBUTE, ['other' => ['x'], 'default' => 'not-a-list']);
        VaultCookie::write($request, $response, $profiles->all());
        self::assertSame([], $response->headers->getCookies());
        VaultCookie::queue($request, $profiles->getDefault(), []);
        VaultCookie::write($request, $response, $profiles->all());
        $cookie = $response->headers->getCookies()[0];
        self::assertSame('__Host-v', $cookie->getName());
        self::assertTrue($cookie->isCleared());
        self::assertSame('__Host-v_admin', VaultCookie::name($request, ProfileRegistryFactory::single(TestUser::class, ['multi_account' => ['cookie' => ['name' => 'v']]], 'admin')->getByName('admin')));
    }

    public function testCookieSecureOption(): void
    {
        $http   = Request::create('http://app.example.test/');
        $forced = ProfileRegistryFactory::single(TestUser::class, ['multi_account' => ['enabled' => true, 'cookie' => ['name' => 'v', 'secure' => true]]])->getDefault();
        self::assertTrue(VaultCookie::secure($http, $forced));
        self::assertSame('__Host-v', VaultCookie::name($http, $forced), 'Forced Secure behind a TLS proxy without trusted_proxies (DE-L6).');
        $off = ProfileRegistryFactory::single(TestUser::class, ['multi_account' => ['enabled' => true, 'cookie' => ['name' => 'v', 'secure' => false]]])->getDefault();
        self::assertFalse(VaultCookie::secure(Request::create('https://app.example.test/'), $off));
        self::assertSame('v', VaultCookie::name(Request::create('https://app.example.test/'), $off));
    }

    public function testPresentation(): void
    {
        $p = new AccountPresentation('Ana María López', 'ana@example.com');
        self::assertSame('AM', $p->initials());
        self::assertSame('a•••@example.com', $p->identifierFor('masked'));
        self::assertNull($p->identifierFor('none'));
        self::assertSame('ana@example.com', $p->identifierFor('full'));
        self::assertSame('Ana María López', $p->nameFor('masked'));
        self::assertSame('u•••', (new AccountPresentation('user', 'user'))->nameFor('none'));
    }

    public function testGateAndEntity(): void
    {
        $gate = new DefaultEnrolmentGate();
        self::assertTrue($gate->isComplete(new UsernamePasswordToken(new InMemoryUser('a', null), 'main')));
        self::assertFalse($gate->isComplete(new NullToken()));
        $now = new DateTimeImmutable();
        self::assertInstanceOf(AccountVaultEntry::class, new AccountVaultEntry('p', 'default', 'a', 'h', null, $now, null, null, null, $now, $now, $now));
    }

    public function testStore(): void
    {
        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn(null);
        $store = new AccountVaultStore($doctrine);
        self::assertSame([], $store->find('default', []));
        $store->delete([]);
        $this->expectException(LogicException::class);
        $store->purge(new DateTimeImmutable());
    }

    public function testThePassFailsClosed(): void
    {
        $container = new ContainerBuilder();
        (new MultiAccountPass())->process($container);
        self::assertFalse($container->hasDefinition(AccountVault::class), 'Nothing to do without the feature.');

        $container->register(AccountVault::class);
        $container->setParameter(MultiAccountPass::FIREWALLS_PARAMETER, ['main']);
        try {
            (new MultiAccountPass())->process($container);
            self::fail('UserKit is required.');
        } catch (LogicException $e) {
            self::assertStringContainsString('user-kit-bundle', $e->getMessage());
        }
        $container->register(UserSessionManager::class);
        try {
            (new MultiAccountPass())->process($container);
            self::fail('The firewall must exist.');
        } catch (LogicException $e) {
            self::assertStringContainsString('needs the firewall "main"', $e->getMessage());
        }
        $container->setDefinition('security.firewall.map.config.main', new Definition('x', ['main', 'c', null, true, true]));
        try {
            (new MultiAccountPass())->process($container);
            self::fail('Stateless firewalls are refused.');
        } catch (LogicException $e) {
            self::assertStringContainsString('stateless', $e->getMessage());
        }
        $container->setDefinition('security.firewall.map.config.main', new Definition('x', ['main', 'c', null, true, false, null, 'shared']));
        $container->register('rm.other')->addTag('security.remember_me_handler', ['firewall' => 'other']);
        $container->register('rm.custom')->addTag('security.remember_me_handler', ['firewall' => 'main']);
        (new MultiAccountPass())->process($container);
        $vault = $container->getDefinition(AccountVault::class);
        self::assertSame(['main' => 'shared'], $vault->getArgument('$contexts'));
        self::assertSame(['main' => null], $vault->getArgument('$rememberMe'), 'A custom handler without options gives no cookie to move.');

        $container->register('app.users');
        $container->setDefinition('security.firewall.map.config.main', new Definition('x', ['main', 'c', null, true, false, 'app.users', 'shared']));
        (new MultiAccountPass())->process($container);
        $locator = $container->getDefinition(AccountVault::class)->getArgument('$userProviders');
        self::assertInstanceOf(Reference::class, $locator, 'The firewall\'s own user provider (DE-I5).');
    }
}
