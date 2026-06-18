<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Form to set a new password after following a reset link.
 */
final class ResetPasswordFormType extends AbstractType
{
    public function __construct(
        private readonly PasswordFieldTypeResolver $passwordFieldTypeResolver,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', RepeatedType::class, [
            'type'          => $this->passwordFieldTypeResolver->resolve(),
            'first_options' => [
                'label' => 'reset.password.field.password',
                'attr'  => ['autocomplete' => 'new-password'],
            ],
            'second_options' => [
                'label' => 'reset.password.field.password_confirm',
                'attr'  => ['autocomplete' => 'new-password'],
            ],
            'invalid_message' => 'reset.password.mismatch',
            'constraints'     => [
                new NotBlank(message: 'reset.password.required'),
                new Length(min: 6, minMessage: 'reset.password.min_length'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
        ]);
    }
}
