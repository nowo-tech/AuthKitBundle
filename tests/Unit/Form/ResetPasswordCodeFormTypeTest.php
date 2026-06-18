<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Form;

use LogicException;
use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;
use Nowo\AuthKitBundle\Form\ResetPasswordCodeFormType;
use PHPUnit\Framework\TestCase;

final class ResetPasswordCodeFormTypeTest extends TestCase
{
    public function testRejectsInvalidCodeLength(): void
    {
        $this->expectException(LogicException::class);

        new ResetPasswordCodeFormType('email', new PasswordFieldTypeResolver(), 0);
    }
}
