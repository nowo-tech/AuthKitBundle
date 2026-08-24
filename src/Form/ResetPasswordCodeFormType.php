<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

use function is_int;
use function is_string;

/**
 * Form to complete reset with identifier + OTP/code + new password.
 */
#[FormKitConfig('auth_kit')]
final class ResetPasswordCodeFormType extends AbstractType
{
    use FormOptionsTrait;

    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        private readonly PasswordRepeatedFieldBuilder $passwordRepeatedFieldBuilder,
        private readonly OtpInputTypeResolver $otpInputTypeResolver = new OtpInputTypeResolver(),
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $profile       = $this->resolveProfile($options);
        $codeLengthRaw = $profile->passwordReset['code_length'];
        $codeLength    = is_int($codeLengthRaw) && $codeLengthRaw >= 1 ? $codeLengthRaw : 6;
        $charset       = is_string($profile->passwordReset['code_charset'] ?? null)
            ? $profile->passwordReset['code_charset']
            : 'numeric';
        $identifierType = $profile->userIdentifierField === 'email' ? EmailType::class : TextType::class;

        $this->addWithDefaults($builder, 'identifier', $identifierType, [
            'label'       => 'reset.code.field.identifier',
            'help'        => false,
            'placeholder' => false,
            'constraints' => [new NotBlank(message: 'reset.code.identifier_required')],
        ]);

        $otpType         = $this->otpInputTypeResolver->resolvePasswordResetCodeType($profile->otpInput);
        $codeConstraints = [
            new NotBlank(message: 'reset.code.code_required'),
            new Length(exactly: $codeLength, exactMessage: 'reset.code.code_length'),
        ];
        if ($otpType !== null) {
            $this->addWithDefaults($builder, 'code', $otpType, [
                'label'              => 'reset.code.field.code',
                'help'               => false,
                'placeholder'        => false,
                'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
                'attr'               => ['autocomplete' => 'one-time-code'],
                'constraints'        => $codeConstraints,
                ...$this->otpInputTypeResolver->fieldOptions($codeLength, $charset),
            ]);
        } else {
            $this->addText($builder, 'code', [
                'label'       => 'reset.code.field.code',
                'help'        => false,
                'placeholder' => false,
                'attr'        => [
                    'autocomplete' => 'one-time-code',
                    'inputmode'    => $charset === 'numeric' ? 'numeric' : 'text',
                ],
                'constraints' => $codeConstraints,
            ]);
        }

        $this->passwordRepeatedFieldBuilder->add(
            $builder,
            'password',
            'reset.password.field.password',
            'reset.password.field.password_confirm',
            'reset.password.mismatch',
            'reset.password.required',
            'reset.password.min_length',
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
            'profile'            => null,
        ]);
        $resolver->setAllowedTypes('profile', ['null', 'string']);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveProfile(array $options): ProfileSettings
    {
        $profileName = $options['profile'];

        return is_string($profileName) && $profileName !== ''
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();
    }
}
