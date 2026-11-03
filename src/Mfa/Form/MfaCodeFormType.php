<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Form;

use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

use function is_string;
use function max;
use function trim;

/**
 * Second-factor code form (1.34): the authenticator-app code, optionally a recovery code (`recovery`) and the
 * "trust this device" checkbox (`trust_device`). CSRF-protected through FormKit; the codes are checked by
 * {@see MfaManager::check()} (one of them must be filled).
 */
#[FormKitConfig('auth_kit')]
final class MfaCodeFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $digits = max(6, (int) $options['digits']);
        $this->addText($builder, 'code', [
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
        ]);
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
        ]);
        $resolver->setAllowedTypes('digits', 'int');
        $resolver->setAllowedTypes('recovery', 'bool');
        $resolver->setAllowedTypes('trust_device', 'bool');
        $resolver->setAllowedTypes('trust_device_days', 'int');
    }
}
