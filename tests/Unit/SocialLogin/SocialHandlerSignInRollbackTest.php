<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use Nowo\AuthKitBundle\SocialLogin\SocialHandlerSignInRollback;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The rollback without UserKit or the switcher (the kernel test covers them), and without a session.
 */
final class SocialHandlerSignInRollbackTest extends TestCase
{
    public function testWithoutASessionOnlyTheTokenComesBack(): void
    {
        [$rollback, $tokens, $before, $failed] = $this->fixtures();
        $request                               = new Request();

        self::assertSame([], $rollback->snapshot($request));
        $rollback->restore($request, $before, $failed, []);

        self::assertSame($before, $tokens->getToken());
    }

    public function testAttributesAreUndoneRemovalsStayAndTheIdMoves(): void
    {
        [$rollback, $tokens, $before, $failed] = $this->fixtures();
        $session                               = new Session(new MockArraySessionStorage());
        $session->start();
        $session->set('kept', 1);
        $session->set('changed', 'before');
        $session->set('removed', 'x');
        $request = new Request();
        $request->setSession($session);
        $snapshot = $rollback->snapshot($request);
        $id       = $session->getId();

        $session->set('changed', 'failed sign-in');
        $session->set('added', 'failed sign-in');
        $session->remove('removed');
        $rollback->restore($request, $before, $failed, $snapshot);

        self::assertSame(['kept' => 1, 'changed' => 'before'], $session->all());
        self::assertNotSame($id, $session->getId());
        self::assertSame($before, $tokens->getToken());
    }

    /**
     * @return array{SocialHandlerSignInRollback, TokenStorage, UsernamePasswordToken, UsernamePasswordToken}
     */
    private function fixtures(): array
    {
        $tokens = new TokenStorage();
        $before = new UsernamePasswordToken(new TestUser('before@example.com'), 'main', []);
        $failed = new UsernamePasswordToken(new TestUser('failed@example.com'), 'main', []);
        $tokens->setToken($failed);

        return [new SocialHandlerSignInRollback($tokens, ProfileRegistryFactory::single(TestUser::class)), $tokens, $before, $failed];
    }
}
