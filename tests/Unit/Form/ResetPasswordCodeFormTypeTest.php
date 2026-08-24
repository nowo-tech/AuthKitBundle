<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Form;

use Nowo\AuthKitBundle\Form\OtpInputTypeResolver;
use Nowo\AuthKitBundle\Form\ResetPasswordCodeFormType;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\FormKitTestSupport;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\PasswordFieldResolvers;
use Nowo\OtpInputBundle\Form\OtpType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

use function class_exists;
use function get_class;

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

    public function testCodeFieldStaysTextTypeWhenOtpInputUnavailable(): void
    {
        $resolver = new OtpInputTypeResolver(static fn (string $class): bool => false);
        $factory  = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(FormKitTestSupport::withMerger(new ResetPasswordCodeFormType(
                ProfileRegistryFactory::single(TestUser::class, [
                    'otp_input' => ['enabled' => true, 'password_reset_code' => true],
                ]),
                PasswordFieldResolvers::repeatedFieldBuilder(),
                $resolver,
            )))
            ->getFormFactory();

        $field = $factory->create(ResetPasswordCodeFormType::class)->get('code');

        self::assertSame(TextType::class, get_class($field->getConfig()->getType()->getInnerType()));
        self::assertSame('numeric', $field->getConfig()->getOption('attr')['inputmode']);
    }

    public function testCodeFieldInputModeIsTextForAlphanumericCharset(): void
    {
        $resolver = new OtpInputTypeResolver(static fn (string $class): bool => false);
        $factory  = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(FormKitTestSupport::withMerger(new ResetPasswordCodeFormType(
                ProfileRegistryFactory::single(TestUser::class, [
                    'otp_input'      => ['enabled' => true, 'password_reset_code' => true],
                    'password_reset' => ['code_charset' => 'alphanumeric'],
                ]),
                PasswordFieldResolvers::repeatedFieldBuilder(),
                $resolver,
            )))
            ->getFormFactory();

        $field = $factory->create(ResetPasswordCodeFormType::class)->get('code');

        self::assertSame('text', $field->getConfig()->getOption('attr')['inputmode']);
    }

    public function testCodeFieldFallsBackToNumericWhenCharsetIsNotAString(): void
    {
        $resolver = new OtpInputTypeResolver(static fn (string $class): bool => false);
        $factory  = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(FormKitTestSupport::withMerger(new ResetPasswordCodeFormType(
                ProfileRegistryFactory::single(TestUser::class, [
                    'password_reset' => ['code_charset' => null],
                ]),
                PasswordFieldResolvers::repeatedFieldBuilder(),
                $resolver,
            )))
            ->getFormFactory();

        $field = $factory->create(ResetPasswordCodeFormType::class)->get('code');

        self::assertSame('numeric', $field->getConfig()->getOption('attr')['inputmode']);
    }

    public function testCodeLengthFallsBackWhenNotAPositiveInt(): void
    {
        $resolver = new OtpInputTypeResolver(static fn (string $class): bool => false);
        $factory  = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(FormKitTestSupport::withMerger(new ResetPasswordCodeFormType(
                ProfileRegistryFactory::single(TestUser::class, [
                    'password_reset' => ['code_length' => 0],
                ]),
                PasswordFieldResolvers::repeatedFieldBuilder(),
                $resolver,
            )))
            ->getFormFactory();

        $form = $factory->create(ResetPasswordCodeFormType::class);
        $form->submit([
            'identifier' => 'user@example.com',
            'code'       => '123456',
            'password'   => ['first' => 'Secret1!', 'second' => 'Secret1!'],
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertTrue($form->get('code')->isValid());
    }

    public function testBuildsWithNamedProfile(): void
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(FormKitTestSupport::withMerger(new ResetPasswordCodeFormType(
                ProfileRegistryFactory::single(TestUser::class),
                PasswordFieldResolvers::repeatedFieldBuilder(),
            )))
            ->getFormFactory();

        $form = $factory->create(ResetPasswordCodeFormType::class, null, ['profile' => 'default']);

        self::assertTrue($form->has('code'));
    }

    public function testCodeFieldUsesOtpTypeWhenAvailable(): void
    {
        if (!class_exists(OtpType::class)) {
            self::markTestSkipped('nowo-tech/otp-input-bundle is not installed.');
        }

        $resolver = new OtpInputTypeResolver(static fn (string $class): bool => true);
        $factory  = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new OtpType())
            ->addType(FormKitTestSupport::withMerger(new ResetPasswordCodeFormType(
                ProfileRegistryFactory::single(TestUser::class, [
                    'otp_input'      => ['enabled' => true, 'password_reset_code' => true],
                    'password_reset' => ['code_length' => 6, 'code_charset' => 'numeric'],
                ]),
                PasswordFieldResolvers::repeatedFieldBuilder(),
                $resolver,
            )))
            ->getFormFactory();

        $field = $factory->create(ResetPasswordCodeFormType::class)->get('code');

        self::assertSame(OtpType::class, get_class($field->getConfig()->getType()->getInnerType()));
        self::assertSame(6, $field->getConfig()->getOption('length'));
        self::assertTrue($field->getConfig()->getOption('numeric_only'));
        self::assertFalse($field->getConfig()->getOption('uppercase'));
    }

    public function testCodeFieldUsesAlphanumericOtpOptions(): void
    {
        if (!class_exists(OtpType::class)) {
            self::markTestSkipped('nowo-tech/otp-input-bundle is not installed.');
        }

        $resolver = new OtpInputTypeResolver(static fn (string $class): bool => true);
        $factory  = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new OtpType())
            ->addType(FormKitTestSupport::withMerger(new ResetPasswordCodeFormType(
                ProfileRegistryFactory::single(TestUser::class, [
                    'otp_input'      => ['enabled' => true, 'password_reset_code' => true],
                    'password_reset' => ['code_length' => 8, 'code_charset' => 'alphanumeric'],
                ]),
                PasswordFieldResolvers::repeatedFieldBuilder(),
                $resolver,
            )))
            ->getFormFactory();

        $field = $factory->create(ResetPasswordCodeFormType::class)->get('code');

        self::assertSame(8, $field->getConfig()->getOption('length'));
        self::assertFalse($field->getConfig()->getOption('numeric_only'));
        self::assertTrue($field->getConfig()->getOption('uppercase'));
    }
}
