<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use LogicException;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Form to complete reset with identifier + OTP/code + new password.
 */
final class ResetPasswordCodeFormType extends AbstractType
{
    public function __construct(
        #[Autowire(param: 'nowo_auth_kit.user_identifier_field')]
        private readonly string $userIdentifierField,
        private readonly PasswordFieldTypeResolver $passwordFieldTypeResolver,
        #[Autowire(param: 'nowo_auth_kit.password_reset.code_length')]
        private readonly int $codeLength,
    ) {
        if ($this->codeLength < 1) {
            throw new LogicException('password_reset.code_length must be at least 1.');
        }
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $identifierType = $this->userIdentifierField === 'email' ? EmailType::class : TextType::class;

        $builder
            ->add('identifier', $identifierType, [
                'label'       => 'reset.code.field.identifier',
                'constraints' => [new NotBlank(message: 'reset.code.identifier_required')],
            ])
            ->add('code', TextType::class, [
                'label'       => 'reset.code.field.code',
                'attr'        => ['autocomplete' => 'one-time-code', 'inputmode' => 'numeric'],
                'constraints' => [
                    new NotBlank(message: 'reset.code.code_required'),
                    new Length(exactly: $this->codeLength, exactMessage: 'reset.code.code_length'),
                ],
            ])
            ->add('password', RepeatedType::class, [
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
