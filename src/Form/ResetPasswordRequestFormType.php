<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Form to request a password reset credential.
 */
final class ResetPasswordRequestFormType extends AbstractType
{
    public function __construct(
        #[Autowire(param: 'nowo_auth_kit.user_identifier_field')]
        private readonly string $userIdentifierField,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $type = $this->userIdentifierField === 'email' ? EmailType::class : TextType::class;

        $builder->add('identifier', $type, [
            'label'       => 'reset.request.field.identifier',
            'required'    => true,
            'constraints' => [new NotBlank(message: 'reset.request.identifier_required')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
        ]);
    }
}
