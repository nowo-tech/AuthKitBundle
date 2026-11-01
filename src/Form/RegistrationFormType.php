<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function is_string;

/**
 * Dynamic registration form built from bundle configuration.
 */
#[FormKitConfig('auth_kit')]
final class RegistrationFormType extends AbstractType
{
    use FormOptionsTrait;

    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        private readonly PasswordRepeatedFieldBuilder $passwordRepeatedFieldBuilder,
        private readonly SlideToConfirmTypeResolver $slideToConfirmTypeResolver = new SlideToConfirmTypeResolver(),
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $profile = $this->resolveProfile($options);
        // Invitation acceptance (1.32): the invited e-mail field is read-only when invitations.lock_email is true
        // (a disabled field keeps the invitation's value whatever is submitted).
        $lockedField = $options['invitation_lock_email'] === true ? $profile->invitationEmailField() : null;

        foreach ($profile->registrationFields as $field) {
            if ($field['type'] === 'password') {
                // @igor-ignore - Request-scoped Twig/Form wiring; not a shared worker singleton.
                $this->passwordRepeatedFieldBuilder->add(
                    $builder,
                    $field['name'],
                    'register.field.' . $field['name'],
                    'register.field.' . $field['name'] . '_confirm',
                    'register.password.mismatch',
                    'register.password.required',
                    'register.password.min_length',
                );
                continue;
            }

            $slideProfile = $this->slideToConfirmTypeResolver->resolveRegistrationProfile(
                $field,
                $profile->slideToConfirm,
            );
            $slideType = $this->slideToConfirmTypeResolver->resolveSlideType();
            if ($slideProfile !== null && $slideType !== null) {
                $this->addWithDefaults($builder, $field['name'], $slideType, [
                    'label'              => false,
                    'mapped'             => (bool) ($field['mapped'] ?? false),
                    'required'           => $field['required'],
                    'help'               => false,
                    'placeholder'        => false,
                    'profile'            => $slideProfile,
                    'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
                    'text'               => 'register.slide.unlock',
                    'confirmed_text'     => 'register.slide.unlocked',
                    'hint'               => 'register.slide.hint',
                ]);
                continue;
            }

            $type = match ($field['type']) {
                'email'    => EmailType::class,
                'checkbox' => CheckboxType::class,
                default    => TextType::class,
            };

            $this->addWithDefaults($builder, $field['name'], $type, [
                'label'       => 'register.field.' . $field['name'],
                'required'    => $field['required'],
                'mapped'      => (bool) ($field['mapped'] ?? true),
                'help'        => false,
                'placeholder' => false,
                'disabled'    => $lockedField !== null && $field['name'] === $lockedField,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain'       => NowoAuthKitBundle::TRANSLATION_DOMAIN,
            'profile'                  => null,
            'invitation_lock_email'    => false,
            'invitation_csrf_token_id' => null,
        ]);
        $resolver->setAllowedTypes('profile', ['null', 'string']);
        $resolver->setAllowedTypes('invitation_lock_email', 'bool');
        $resolver->setAllowedTypes('invitation_csrf_token_id', ['null', 'string']);
        // Invitation acceptance (1.32): the CSRF token id binds the invitation (set only with the form CSRF extension).
        if ($resolver->isDefined('csrf_token_id')) {
            $resolver->setNormalizer('csrf_token_id', static fn (Options $options, mixed $value): mixed => $options['invitation_csrf_token_id'] ?? $value);
        }
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
