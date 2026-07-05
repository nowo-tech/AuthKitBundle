<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Dynamic registration form built from bundle configuration.
 */
final class RegistrationFormType extends AbstractType
{
    /**
     * @param list<array{name: string, type: string, property: string, hash: bool, required: bool, security_name: null}> $registrationFields
     */
    public function __construct(
        #[Autowire(param: 'nowo_auth_kit.registration_fields')]
        private readonly array $registrationFields,
        private readonly PasswordRepeatedFieldBuilder $passwordRepeatedFieldBuilder,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach ($this->registrationFields as $field) {
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

            $builder->add($field['name'], $type, [
                'label'    => 'register.field.' . $field['name'],
                'required' => $field['required'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
        ]);
    }
}
