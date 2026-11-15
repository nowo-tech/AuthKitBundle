<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Form;

use Nowo\AuthKitBundle\Form\OtpInputTypeResolver;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

use function in_array;
use function is_array;
use function is_string;
use function max;
use function trim;

/**
 * Second-factor code form (1.34): the authenticator-app code, optionally a recovery code (`recovery`) and the
 * "trust this device" checkbox (`trust_device`). CSRF-protected through FormKit; the codes are checked by
 * {@see MfaManager::check()} (one of them must be filled).
 *
 * 1.51: with `otp_input` (the profile's `otp_input` config) asking for it and nowo-tech/otp-input-bundle installed,
 * the authenticator code renders with OtpType (one box per digit, numeric, pasting the whole code works). The
 * submitted value is the same raw string as the text field's: OtpType's normaliser is removed, so validation,
 * rate limiting and the constant-time check see exactly what they saw before. The recovery code stays a text field.
 *
 * @phpstan-import-type OtpInputConfig from OtpInputTypeResolver
 */
#[FormKitConfig('auth_kit')]
final class MfaCodeFormType extends AbstractType
{
    use FormOptionsTrait;

    /** Block prefix of the OTP code field: `nowo_auth_kit_mfa_otp_widget` (form/mfa_otp_widget.html.twig). */
    public const OTP_BLOCK_PREFIX = 'nowo_auth_kit_mfa_otp';

    /** Stateless (shared in worker mode): the OTP choice comes from the `otp_input` option of each form. */
    public function __construct(
        private readonly OtpInputTypeResolver $otpInputTypeResolver = new OtpInputTypeResolver(),
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $digits = max(6, (int) $options['digits']);
        /** @var OtpInputConfig|null $otpInput */
        $otpInput = $options['otp_input'];
        $wanted   = is_array($otpInput) && $this->otpInputTypeResolver->wantsMfaCode($otpInput);
        $otpType  = $this->otpInputTypeResolver->resolveMfaCodeType($wanted);
        $code     = [
            'label'       => 'mfa.field.code',
            'help'        => 'mfa.field.code_help',
            'placeholder' => false,
            'required'    => !$options['recovery'],
            'attr'        => [
                'autocomplete' => 'one-time-code',
                'inputmode'    => 'numeric',
                'pattern'      => '[0-9]*',
                'maxlength'    => $digits,
                'autofocus'    => 'autofocus',
            ],
            'constraints' => [new Length(max: $digits, maxMessage: 'mfa.error.code_format')],
        ];
        if ($otpType !== null) {
            $this->addWithDefaults($builder, 'code', $otpType, [
                ...$code,
                'attr'         => ['autocomplete' => 'one-time-code'],
                'block_prefix' => self::OTP_BLOCK_PREFIX,
                'length'       => $digits,
                'numeric_only' => true,
                'uppercase'    => false,
                'autofocus'    => true,
            ]);
            // Same submitted value as the text field (no digit filter, no truncation): the server checks stay as they were.
            $builder->get('code')->resetModelTransformers();
        } else {
            $this->addText($builder, 'code', $code);
        }
        if ($options['recovery']) {
            $this->addText($builder, 'recovery_code', [
                'label'       => 'mfa.field.recovery_code',
                'help'        => 'mfa.field.recovery_code_help',
                'placeholder' => false,
                'required'    => false,
                'attr'        => ['autocomplete' => 'off', 'spellcheck' => 'false', 'maxlength' => 16],
                'constraints' => [new Length(max: 16, maxMessage: 'mfa.error.code_format')],
            ]);
        }
        if ($options['trust_device']) {
            $this->addCheckbox($builder, 'trust_device', [
                'label'                       => 'mfa.field.trust_device',
                'help'                        => 'mfa.field.trust_device_help',
                'help_translation_parameters' => ['%days%' => $options['trust_device_days']],
                'required'                    => false,
            ]);
        }
    }

    /**
     * 1.51: the OTP code field is named by its visible label (the digit group points at it) and says whether the form
     * submits itself once every box is filled (`otp_input.mfa_code_autosubmit`).
     */
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $code = $view->children['code'] ?? null;
        if ($code === null || !in_array(self::OTP_BLOCK_PREFIX, $code->vars['block_prefixes'], true)) {
            return;
        }
        /** @var OtpInputConfig|null $otpInput */
        $otpInput                         = $options['otp_input'];
        $code->vars['label_attr']['id']   = $code->vars['id'] . '_label';
        $code->vars['mfa_otp_autosubmit'] = is_array($otpInput) && $this->otpInputTypeResolver->wantsMfaCodeAutosubmit($otpInput);
    }

    /**
     * The code to check: the recovery code when one was typed, else the authenticator code (trimmed).
     *
     * @param FormInterface<mixed> $form
     */
    public static function submittedCode(FormInterface $form): string
    {
        $recovery = $form->has('recovery_code') ? $form->get('recovery_code')->getData() : null;
        if (is_string($recovery) && trim($recovery) !== '') {
            return trim($recovery);
        }
        $code = $form->get('code')->getData();

        return is_string($code) ? trim($code) : '';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
            'digits'             => 6,
            'recovery'           => false,
            'trust_device'       => false,
            'trust_device_days'  => 30,
            'csrf_token_id'      => 'nowo_auth_kit_mfa',
            'otp_input'          => null,
        ]);
        $resolver->setAllowedTypes('otp_input', ['null', 'array']);
        $resolver->setAllowedTypes('digits', 'int');
        $resolver->setAllowedTypes('recovery', 'bool');
        $resolver->setAllowedTypes('trust_device', 'bool');
        $resolver->setAllowedTypes('trust_device_days', 'int');
    }
}
