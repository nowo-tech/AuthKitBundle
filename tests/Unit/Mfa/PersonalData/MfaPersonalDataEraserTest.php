<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Mfa\PersonalData;

use Nowo\AuthKitBundle\Mfa\PersonalData\MfaPersonalDataEraser;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use ReflectionClass;
use stdClass;

use function array_column;
use function property_exists;
use function str_repeat;

final class MfaPersonalDataEraserTest extends MfaPersonalDataTestCase
{
    public function testKeyAndPriority(): void
    {
        $eraser = new MfaPersonalDataEraser($this->manager());

        self::assertSame('auth_kit_mfa', $eraser->getKey());
        self::assertSame(200, MfaPersonalDataEraser::PRIORITY);
    }

    public function testSupportsUsersOfAnMfaProfileOrWithRowsLeftInAnotherProfile(): void
    {
        $this->add('legacy', 'kept@example.com');

        self::assertFalse((new MfaPersonalDataEraser($this->manager()))->supports(new stdClass()));
        self::assertTrue((new MfaPersonalDataEraser($this->manager()))->supports(new TestUser('any@example.com')));

        $withoutMfa = new MfaPersonalDataEraser($this->manager(mfa: false));
        self::assertFalse($withoutMfa->supports(new TestUser('nobody@example.com')));
        self::assertTrue($withoutMfa->supports(new TestUser('kept@example.com')), 'MFA turned off after enrolment.');
    }

    public function testEraseDeletesTheUsersRowsInEveryProfileAndNobodyElses(): void
    {
        $current = $this->add('default', 'member@example.com', enabled: true);
        $legacy  = $this->add('legacy', 'member@example.com');
        $other   = $this->add('default', 'other@example.com', enabled: true);

        (new MfaPersonalDataEraser($this->manager()))->erase(new ErasureRequest(new TestUser('member@example.com')));

        $ids = array_column(array_column($this->statements, 'params'), 'id');
        self::assertCount(6, $this->statements, 'Codes, devices and credential of each of the two credentials.');
        self::assertSame([$current->getId(), $current->getId(), $current->getId(), $legacy->getId(), $legacy->getId(), $legacy->getId()], $ids);
        self::assertNotContains($other->getId(), $ids);
    }

    public function testEraseIgnoresUnsupportedUsersAndUsersWithoutMfa(): void
    {
        $this->add('default', 'other@example.com', enabled: true);
        $eraser = new MfaPersonalDataEraser($this->manager());

        $eraser->erase(new ErasureRequest(new stdClass()));
        $eraser->erase(new ErasureRequest(new TestUser('member@example.com')));
        // A user without a usable owner reference (identifier longer than an owner id) cannot have MFA rows.
        $eraser->erase(new ErasureRequest(new TestUser(str_repeat('a', 200) . '@example.com')));

        self::assertSame([], $this->statements);
        self::assertFalse((new MfaPersonalDataEraser($this->manager(mfa: false)))->supports(new TestUser(str_repeat('a', 200) . '@example.com')));
    }

    public function testEraseFindsAnIdentifierKeyedOwnerByTheOriginalIdentifier(): void
    {
        // FH-L1: a host identity eraser above priority 200 anonymised the e-mail (the owner id of a non-Doctrine user)
        // first; UserKit >= 1.12 carries the identifier captured when the erasure started.
        if (!property_exists(ErasureRequest::class, 'originalIdentifier')) {
            self::markTestSkipped('UserKit < 1.12 does not carry the original identifier.');
        }
        $credential = $this->add('default', 'member@example.com', enabled: true);
        $eraser     = new MfaPersonalDataEraser($this->manager());
        $request    = (new ReflectionClass(ErasureRequest::class))->newInstanceArgs([new TestUser('erased-1@invalid.local'), null, [], 'member@example.com']);

        $eraser->erase($request);

        self::assertCount(3, $this->statements);
        self::assertSame([$credential->getId(), $credential->getId(), $credential->getId()], array_column(array_column($this->statements, 'params'), 'id'));
    }
}
