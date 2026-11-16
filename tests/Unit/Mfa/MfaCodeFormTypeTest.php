<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Mfa;

use Nowo\AuthKitBundle\Form\OtpInputTypeResolver;
use Nowo\AuthKitBundle\Mfa\Form\MfaCodeFormType;
use Nowo\AuthKitBundle\Tests\Support\FormKitTestSupport;
use Nowo\OtpInputBundle\Form\OtpType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

/**
 * MfaCodeFormType (1.51): the authenticator code as a text field (default, OTP off or package missing) or on
 * OtpType (otp_input.mfa_code), with the same submitted value either way; the recovery code stays a text field.
 */
final class MfaCodeFormTypeTest extends TestCase
{
    private const ON = ['enabled' => true, 'password_reset_code' => true, 'mfa_code' => true, 'mfa_code_autosubmit' => false];

    public function testTheCodeIsATextFieldByDefault(): void
    {
        $form = $this->factory()->create(MfaCodeFormType::class, null, ['recovery' => true]);

        $code = $form->get('code');
        self::assertInstanceOf(TextType::class, $code->getConfig()->getType()->getInnerType());
        self::assertSame(['autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'pattern' => '[0-9]*', 'maxlength' => 6, 'autofocus' => 'autofocus'], $code->getConfig()->getOption('attr'));
        self::assertFalse($code->getConfig()->getRequired());
        $view = $form->createView();
        self::assertArrayNotHasKey('id', $view['code']->vars['label_attr']);
        self::assertArrayNotHasKey('mfa_otp_autosubmit', $view['code']->vars);
    }

    /**
     * @return iterable<string, array{0: array<string, bool>|null, 1: bool}>
     */
    public static function textModes(): iterable
    {
        yield 'no otp_input' => [null, true];
        yield 'otp_input disabled' => [['enabled' => false, 'password_reset_code' => true, 'mfa_code' => true, 'mfa_code_autosubmit' => true], true];
        yield 'mfa_code off' => [['enabled' => true, 'password_reset_code' => true, 'mfa_code' => false, 'mfa_code_autosubmit' => true], true];
        yield 'package missing' => [self::ON, false];
    }

    /**
     * @param array{enabled: bool, password_reset_code: bool, mfa_code?: bool, mfa_code_autosubmit?: bool}|null $otpInput
     */
    #[DataProvider('textModes')]
    public function testFallsBackToTheTextField(?array $otpInput, bool $packageInstalled): void
    {
        $form = $this->factory($packageInstalled)->create(MfaCodeFormType::class, null, ['otp_input' => $otpInput]);

        self::assertInstanceOf(TextType::class, $form->get('code')->getConfig()->getType()->getInnerType());
        self::assertSame('6', (string) $form->get('code')->getConfig()->getOption('attr')['maxlength']);
    }

    public function testTheCodeIsAnOtpFieldWhenAskedFor(): void
    {
        $form = $this->factory()->create(MfaCodeFormType::class, null, [
            'otp_input'      => ['mfa_code_autosubmit' => true] + self::ON,
            'otp_autosubmit' => true,
            'digits'         => 8,
            'recovery'       => true,
        ]);

        $code   = $form->get('code');
        $config = $code->getConfig();
        self::assertInstanceOf(OtpType::class, $config->getType()->getInnerType());
        self::assertSame(8, $config->getOption('length'));
        self::assertTrue($config->getOption('numeric_only'));
        self::assertFalse($config->getOption('uppercase'));
        self::assertNull($config->getOption('block_prefix'), '1.52: OtpInputBundle renders its own widget, no AuthKit theme.');
        self::assertContains(MfaCodeFormType::OTP_BLOCK_PREFIX, $form->createView()['code']->vars['block_prefixes']);
        self::assertSame(['class' => MfaCodeFormType::OTP_TEXT_CLASS], $config->getOption('attr'), 'No duplicate of the attributes OtpInput sets itself.');
        self::assertSame('nowo-otp-input__container ' . MfaCodeFormType::OTP_CONTAINER_CLASS, $config->getOption('container_class'));
        self::assertSame('NowoAuthKitBundle', $config->getOption('translation_domain'), 'The label and help keep the AuthKit domain.');
        self::assertSame([], $config->getModelTransformers(), 'OtpType normaliser removed: same submitted value as the text field.');
        self::assertFalse($config->getRequired(), 'The recovery code may be used instead.');
        self::assertInstanceOf(TextType::class, $form->get('recovery_code')->getConfig()->getType()->getInnerType());

        $view = $form->createView();
        self::assertTrue($view['code']->vars['mfa_otp_autosubmit']);
        self::assertSame('1', $view['code']->vars['attr']['data-nowo-auth-kit-otp-autosubmit']);
        self::assertSame(8, $view['code']->vars['otp_length']);
        self::assertSame(['%digits%' => 8], $config->getOption('help_translation_parameters'));
        self::assertTrue($config->getOption('autofocus'));
    }

    /**
     * Audit QV-L2 / QV-L3: the account page forms (no `otp_autosubmit`, `code_autofocus: false`) never submit
     * themselves and take no focus, in both modes.
     */
    public function testAccountFormsNeitherAutosubmitNorAutofocus(): void
    {
        $otp = $this->factory()->create(MfaCodeFormType::class, null, [
            'otp_input'      => ['mfa_code_autosubmit' => true] + self::ON,
            'code_autofocus' => false,
        ]);
        self::assertFalse($otp->createView()['code']->vars['mfa_otp_autosubmit']);
        self::assertArrayNotHasKey('data-nowo-auth-kit-otp-autosubmit', $otp->createView()['code']->vars['attr']);
        self::assertFalse($otp->get('code')->getConfig()->getOption('autofocus'));

        $text = $this->factory()->create(MfaCodeFormType::class, null, ['code_autofocus' => false]);
        self::assertArrayNotHasKey('autofocus', $text->get('code')->getConfig()->getOption('attr'));
        self::assertSame(['%digits%' => 6], $text->get('code')->getConfig()->getOption('help_translation_parameters'));
    }

    public function testTheSetupCodeIsRequiredAndAutosubmitIsOffByDefault(): void
    {
        $form = $this->factory()->create(MfaCodeFormType::class, null, ['otp_input' => self::ON]);

        self::assertTrue($form->get('code')->getConfig()->getRequired());
        self::assertFalse($form->has('recovery_code'));
        self::assertFalse($form->createView()['code']->vars['mfa_otp_autosubmit']);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool, 2: string}>
     */
    public static function submissions(): iterable
    {
        yield 'six digits' => ['123456', true, '123456'];
        yield 'surrounding spaces are trimmed as before' => [' 123456 ', true, '123456'];
        yield 'seven digits are not cut to six' => ['1234567', false, '1234567'];
        yield 'letters are not filtered out' => ['12a456', true, '12a456'];
    }

    #[DataProvider('submissions')]
    public function testTheOtpFieldSubmitsTheSameValueAsTheTextField(string $submitted, bool $valid, string $code): void
    {
        foreach ([null, self::ON] as $otpInput) {
            $form = $this->factory()->create(MfaCodeFormType::class, null, ['otp_input' => $otpInput]);
            $form->submit(['code' => $submitted]);

            self::assertSame($valid, $form->isValid());
            self::assertSame($code, MfaCodeFormType::submittedCode($form));
        }
    }

    public function testTheRecoveryCodeWinsOverTheOtpCode(): void
    {
        $form = $this->factory()->create(MfaCodeFormType::class, null, ['otp_input' => self::ON, 'recovery' => true]);
        $form->submit(['code' => '', 'recovery_code' => ' ABCDE-12345 ']);

        self::assertTrue($form->isValid());
        self::assertSame('ABCDE-12345', MfaCodeFormType::submittedCode($form));
    }

    /**
     * Worker mode (kernel reset false): one form type instance serves both modes in turn without keeping state.
     */
    public function testOneSharedInstanceAlternatesModes(): void
    {
        $factory = $this->factory();
        foreach ([self::ON, null, self::ON, ['enabled' => false] + self::ON] as $round => $otpInput) {
            $code = $this->code($factory, $otpInput);
            self::assertInstanceOf($round % 2 === 0 ? OtpType::class : TextType::class, $code->getConfig()->getType()->getInnerType());
        }
    }

    /**
     * @param array{enabled: bool, password_reset_code: bool, mfa_code?: bool, mfa_code_autosubmit?: bool}|null $otpInput
     *
     * @return FormInterface<mixed>
     */
    private function code(FormFactoryInterface $factory, ?array $otpInput): FormInterface
    {
        return $factory->create(MfaCodeFormType::class, null, ['otp_input' => $otpInput])->get('code');
    }

    private function factory(bool $packageInstalled = true): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(FormKitTestSupport::withMerger(new MfaCodeFormType(new OtpInputTypeResolver(static fn (string $class): bool => $packageInstalled))))
            ->getFormFactory();
    }
}
