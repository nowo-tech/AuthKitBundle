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
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $profile = $this->resolveProfile($options);

        foreach ($profile->registrationFields as $field) {
            if ($field['type'] === 'password') {
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

            $type = match ($field['type']) {
                'email'    => EmailType::class,
                'checkbox' => CheckboxType::class,
                default    => TextType::class,
            };

            $this->addWithDefaults($builder, $field['name'], $type, [
                'label'    => 'register.field.' . $field['name'],
                'required' => $field['required'],
            ]);
        }
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
