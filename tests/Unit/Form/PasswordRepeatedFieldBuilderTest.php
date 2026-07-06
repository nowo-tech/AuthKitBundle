<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Form;

use Nowo\AuthKitBundle\Form\PasswordFieldConstraintResolver;
use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;
use Nowo\AuthKitBundle\Form\PasswordRepeatedFieldBuilder;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\PasswordStrengthBundle\Form\PasswordStrengthType;
use Nowo\PasswordToggleBundle\Form\Type\PasswordType as TogglePasswordType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Constraints\EqualTo;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

use function get_class;

final class PasswordRepeatedFieldBuilderTest extends TestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        $this->factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new TogglePasswordType())
            ->getFormFactory();
    }

    public function testUsesRepeatedTypeWhenPasswordStrengthIsDisabled(): void
    {
        $builder = new PasswordRepeatedFieldBuilder(
            new PasswordFieldTypeResolver(),
            new PasswordFieldConstraintResolver(new PasswordFieldTypeResolver()),
        );

        $formBuilder = $this->factory->createBuilder(FormType::class);
        $builder->add(
            $formBuilder,
            'password',
            'register.field.password',
            'register.field.password_confirm',
            'register.password.mismatch',
            'register.password.required',
            'register.password.min_length',
        );

        $form = $formBuilder->getForm();

        self::assertTrue($form->has('password'));
        self::assertFalse($form->has('password_confirm'));
        self::assertSame(RepeatedType::class, get_class($form->get('password')->getConfig()->getType()->getInnerType()));
        self::assertSame(TogglePasswordType::class, $form->get('password')->getConfig()->getOption('type'));
    }

    public function testUsesStrengthTypeOnlyOnPrimaryFieldWhenEnabled(): void
    {
        $typeResolver = new PasswordFieldTypeResolver(
            passwordStrength: ['enabled' => true, 'level' => 'medium', 'policy_mode' => 'level'],
            strengthTypeExists: static fn (string $class): bool => $class === PasswordStrengthType::class,
        );
        $builder = new PasswordRepeatedFieldBuilder(
            $typeResolver,
            new PasswordFieldConstraintResolver($typeResolver),
        );

        $formBuilder = $this->factory->createBuilder(FormType::class);
        $builder->add(
            $formBuilder,
            'password',
            'register.field.password',
            'register.field.password_confirm',
            'register.password.mismatch',
            'register.password.required',
            'register.password.min_length',
        );

        self::assertTrue($formBuilder->has('password'));
        self::assertTrue($formBuilder->has('password_confirm'));
        self::assertTrue($typeResolver->usesPasswordStrengthForNewPassword());
        self::assertSame(PasswordStrengthType::class, $typeResolver->resolveForNewPassword());
        self::assertSame(TogglePasswordType::class, $typeResolver->resolve());

        /** @var list<object> $confirmConstraints */
        $confirmConstraints = $formBuilder->get('password_confirm')->getOptions()['constraints'];
        self::assertCount(2, $confirmConstraints);
        self::assertInstanceOf(NotBlank::class, $confirmConstraints[0]);
        self::assertInstanceOf(EqualTo::class, $confirmConstraints[1]);
        self::assertSame('parent.all[password].data', $confirmConstraints[1]->propertyPath);
    }

    public function testPasswordFieldsUseParentTranslationDomain(): void
    {
        $builder = new PasswordRepeatedFieldBuilder(
            new PasswordFieldTypeResolver(),
            new PasswordFieldConstraintResolver(new PasswordFieldTypeResolver()),
        );

        $formBuilder = $this->factory->createBuilder(FormType::class, null, [
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
        ]);
        $builder->add(
            $formBuilder,
            'password',
            'register.field.password',
            'register.field.password_confirm',
            'register.password.mismatch',
            'register.password.required',
            'register.password.min_length',
        );

        $options = $formBuilder->get('password')->getOptions();
        self::assertSame(NowoAuthKitBundle::TRANSLATION_DOMAIN, $options['first_options']['translation_domain']);
        self::assertSame(NowoAuthKitBundle::TRANSLATION_DOMAIN, $options['second_options']['translation_domain']);
    }

    public function testPasswordConfirmUsesParentTranslationDomainWhenStrengthEnabled(): void
    {
        $typeResolver = new PasswordFieldTypeResolver(
            passwordStrength: ['enabled' => true, 'level' => 'medium', 'policy_mode' => 'level'],
            strengthTypeExists: static fn (string $class): bool => $class === PasswordStrengthType::class,
        );
        $builder = new PasswordRepeatedFieldBuilder(
            $typeResolver,
            new PasswordFieldConstraintResolver($typeResolver),
        );

        $formBuilder = $this->factory->createBuilder(FormType::class, null, [
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
        ]);
        $builder->add(
            $formBuilder,
            'password',
            'register.field.password',
            'register.field.password_confirm',
            'register.password.mismatch',
            'register.password.required',
            'register.password.min_length',
        );

        self::assertSame(
            NowoAuthKitBundle::TRANSLATION_DOMAIN,
            $formBuilder->get('password_confirm')->getOptions()['translation_domain'],
        );
    }
}
