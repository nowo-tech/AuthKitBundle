<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form to set a new password after following a reset link.
 */
#[FormKitConfig('auth_kit')]
final class ResetPasswordFormType extends AbstractType
{
    use FormOptionsTrait;

    public function __construct(
        private readonly PasswordRepeatedFieldBuilder $passwordRepeatedFieldBuilder,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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
        ]);
    }
}
