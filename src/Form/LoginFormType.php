<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Security\AuthKitFormLoginParameters;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function is_string;

/**
 * Dynamic login form aligned with Symfony form_login field names.
 */
#[FormKitConfig('auth_kit')]
final class LoginFormType extends AbstractType
{
    use FormOptionsTrait;

    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        private readonly PasswordFieldTypeResolver $passwordFieldTypeResolver,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $profile = $this->resolveProfile($options);

        foreach ($profile->loginFields as $field) {
            /** @var class-string<FormTypeInterface> $type */
            $type = match ($field['type']) {
                'email'    => EmailType::class,
                'password' => $this->passwordFieldTypeResolver->resolve(),
                'checkbox' => CheckboxType::class,
                default    => TextType::class,
            };

            $this->addWithDefaults($builder, $field['name'], $type, [
                'label'       => 'login.field.' . ($field['security_name'] === '_username' ? 'identifier' : ltrim($field['name'], '_')),
                'required'    => $field['required'],
                'help'        => false,
                'placeholder' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
            'csrf_field_name'    => '_csrf_token',
            'csrf_token_id'      => 'authenticate',
            'profile'            => null,
        ]);
        $resolver->setAllowedTypes('profile', ['null', 'string']);
    }

    public function getBlockPrefix(): string
    {
        return AuthKitFormLoginParameters::BLOCK_PREFIX;
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
