<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Admin;

use Nowo\AuthKitBundle\Admin\AdminUserCreatedEvent;
use Nowo\AuthKitBundle\Admin\AdminUserCreatingEvent;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;

/**
 * 1.54.0: the deprecated `getUser()` of the administrator creation events (1.46.0, removed in 2.0) triggers a runtime
 * notice and still returns the user; `userRef()` triggers none.
 */
final class AdminUserEventsDeprecationTest extends TestCase
{
    #[IgnoreDeprecations]
    public function testCreatingGetUserIsDeprecatedAndUnchanged(): void
    {
        $user = new TestUser('owner@example.com');
        $this->expectUserDeprecationMessage('Since nowo-tech/auth-kit-bundle 1.54: "' . AdminUserCreatingEvent::class . '::getUser()" is deprecated since 1.46.0 and removed in 2.0: use userRef() (see UPGRADING.md "To 1.46.0").');

        self::assertSame($user, (new AdminUserCreatingEvent($user, 'default'))->getUser());
    }

    #[IgnoreDeprecations]
    public function testCreatedGetUserIsDeprecatedAndUnchanged(): void
    {
        $user = new TestUser('owner@example.com');
        $this->expectUserDeprecationMessage('Since nowo-tech/auth-kit-bundle 1.54: "' . AdminUserCreatedEvent::class . '::getUser()" is deprecated since 1.46.0 and removed in 2.0: use userRef() (see UPGRADING.md "To 1.46.0").');

        self::assertSame($user, (new AdminUserCreatedEvent($user, 'default', ['ROLE_ADMIN']))->getUser());
    }

    public function testUserRefTriggersNoNotice(): void
    {
        $user = new TestUser('owner@example.com');

        self::assertSame('owner@example.com', (new AdminUserCreatingEvent($user, 'default'))->userRef()->identifier);
        self::assertSame($user, (new AdminUserCreatedEvent($user, 'default', []))->userRef()->user());
    }
}
