<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Form;

use Nowo\AuthKitBundle\Config\FieldConfigNormalizer;
use Nowo\AuthKitBundle\Form\LoginFormType;
use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;
use Nowo\AuthKitBundle\Form\RegistrationFormType;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\Tests\Unit\Controller\FieldConfigNormalizerFields;
use Nowo\PasswordToggleBundle\Form\Type\PasswordType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType as SymfonyPasswordType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

use function get_class;

final class FormTypesTest extends TestCase
{
    private FormFactoryInterface $factory;

    private PasswordFieldTypeResolver $passwordFieldTypeResolver;

    protected function setUp(): void
    {
        $this->passwordFieldTypeResolver = new PasswordFieldTypeResolver();

        $this->factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new PasswordType())
            ->addType(new LoginFormType(FieldConfigNormalizerFields::login(), $this->passwordFieldTypeResolver))
            ->addType(new RegistrationFormType(FieldConfigNormalizerFields::registration(), $this->passwordFieldTypeResolver))
            ->getFormFactory();
    }

    public function testLoginFormContainsSecurityFieldNames(): void
    {
        $form = $this->factory->create(LoginFormType::class);

        self::assertTrue($form->has('_username'));
        self::assertTrue($form->has('_password'));
    }

    public function testLoginFormUsesTogglePasswordType(): void
    {
        $field = $this->factory->create(LoginFormType::class)->get('_password');

        self::assertSame(PasswordType::class, get_class($field->getConfig()->getType()->getInnerType()));
    }

    public function testRegistrationFormUsesTogglePasswordType(): void
    {
        $field = $this->factory->create(RegistrationFormType::class)->get('password');

        self::assertSame(PasswordType::class, $field->getConfig()->getOption('type'));
    }

    public function testLoginFormUsesSymfonyPasswordTypeWhenToggleBundleIsUnavailable(): void
    {
        $resolver = new PasswordFieldTypeResolver(SymfonyPasswordType::class);
        $factory  = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new LoginFormType(FieldConfigNormalizerFields::login(), $resolver))
            ->getFormFactory();

        $field = $factory->create(LoginFormType::class)->get('_password');

        self::assertSame(SymfonyPasswordType::class, get_class($field->getConfig()->getType()->getInnerType()));
    }

    public function testRegistrationFormUsesSymfonyPasswordTypeWhenToggleBundleIsUnavailable(): void
    {
        $resolver = new PasswordFieldTypeResolver(SymfonyPasswordType::class);
        $factory  = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new RegistrationFormType(FieldConfigNormalizerFields::registration(), $resolver))
            ->getFormFactory();

        $field = $factory->create(RegistrationFormType::class)->get('password');

        self::assertSame(SymfonyPasswordType::class, $field->getConfig()->getOption('type'));
    }

    public function testLoginFormUsesEmailTypeForIdentifier(): void
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new PasswordType())
            ->addType(new LoginFormType(FieldConfigNormalizer::normalizeLoginFields(
                ['identifier' => ['type' => 'email']],
                'email',
            ), $this->passwordFieldTypeResolver))
            ->getFormFactory();

        $field = $factory->create(LoginFormType::class)->get('_username');

        self::assertSame(EmailType::class, get_class($field->getConfig()->getType()->getInnerType()));
    }

    public function testLoginFormUsesCheckboxForRememberMe(): void
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new PasswordType())
            ->addType(new LoginFormType(FieldConfigNormalizer::normalizeLoginFields(
                ['identifier', 'password', 'remember_me'],
                'email',
            ), $this->passwordFieldTypeResolver))
            ->getFormFactory();

        $field = $factory->create(LoginFormType::class)->get('_remember_me');

        self::assertSame(CheckboxType::class, get_class($field->getConfig()->getType()->getInnerType()));
    }

    public function testLoginFormConfigureOptions(): void
    {
        $form = $this->factory->create(LoginFormType::class);

        self::assertSame(NowoAuthKitBundle::TRANSLATION_DOMAIN, $form->getConfig()->getOption('translation_domain'));
        self::assertSame('_csrf_token', $form->getConfig()->getOption('csrf_field_name'));
        self::assertSame('authenticate', $form->getConfig()->getOption('csrf_token_id'));
    }

    public function testRegistrationFormContainsEmailAndPassword(): void
    {
        $form = $this->factory->create(RegistrationFormType::class);

        self::assertTrue($form->has('email'));
        self::assertTrue($form->has('password'));
    }

    public function testRegistrationFormSupportsCheckboxField(): void
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new RegistrationFormType(FieldConfigNormalizer::normalizeRegistrationFields([
                'terms' => ['type' => 'checkbox'],
            ]), $this->passwordFieldTypeResolver))
            ->getFormFactory();

        $field = $factory->create(RegistrationFormType::class)->get('terms');

        self::assertSame(CheckboxType::class, get_class($field->getConfig()->getType()->getInnerType()));
    }

    public function testRegistrationFormSupportsTextField(): void
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new RegistrationFormType(FieldConfigNormalizer::normalizeRegistrationFields([
                'fullName',
            ]), $this->passwordFieldTypeResolver))
            ->getFormFactory();

        $field = $factory->create(RegistrationFormType::class)->get('fullName');

        self::assertSame(\Symfony\Component\Form\Extension\Core\Type\TextType::class, get_class($field->getConfig()->getType()->getInnerType()));
    }

    public function testRegistrationFormConfigureOptions(): void
    {
        $form = $this->factory->create(RegistrationFormType::class);

        self::assertSame(NowoAuthKitBundle::TRANSLATION_DOMAIN, $form->getConfig()->getOption('translation_domain'));
    }
}
