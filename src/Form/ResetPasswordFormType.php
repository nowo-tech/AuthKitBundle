<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form to set a new password after following a reset link.
 */
final class ResetPasswordFormType extends AbstractType
{
    public function __construct(
        private readonly PasswordFieldTypeResolver $passwordFieldTypeResolver,
        private readonly PasswordFieldConstraintResolver $passwordFieldConstraintResolver,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', RepeatedType::class, [
            'type'          => $this->passwordFieldTypeResolver->resolveForNewPassword(),
            'first_options' => array_merge([
                'label' => 'reset.password.field.password',
                'attr'  => ['autocomplete' => 'new-password'],
            ], $this->passwordFieldTypeResolver->newPasswordFieldOptions()),
            'second_options' => [
                'label' => 'reset.password.field.password_confirm',
                'attr'  => ['autocomplete' => 'new-password'],
            ],
            'invalid_message' => 'reset.password.mismatch',
            'constraints'     => $this->passwordFieldConstraintResolver->newPasswordConstraints(
                'reset.password.required',
                'reset.password.min_length',
            ),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
        ]);
    }
}
