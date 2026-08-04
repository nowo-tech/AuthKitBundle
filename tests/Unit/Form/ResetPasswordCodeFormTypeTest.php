<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Form;

use Nowo\AuthKitBundle\Form\ResetPasswordCodeFormType;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\FormKitTestSupport;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\PasswordFieldResolvers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

final class ResetPasswordCodeFormTypeTest extends TestCase
{
    public function testBuildsIdentifierCodeAndPasswordFields(): void
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(FormKitTestSupport::withMerger(new ResetPasswordCodeFormType(
                ProfileRegistryFactory::single(TestUser::class),
                PasswordFieldResolvers::repeatedFieldBuilder(),
            )))
            ->getFormFactory();

        $form = $factory->create(ResetPasswordCodeFormType::class);

        self::assertTrue($form->has('identifier'));
        self::assertTrue($form->has('code'));
        self::assertTrue($form->has('password'));
    }
}
