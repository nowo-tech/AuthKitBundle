<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\Form;

use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\Webauthn\WebauthnManager;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Renames a passkey on the account page (2.4.0). CSRF-protected; the token id names the passkey.
 */
#[FormKitConfig('auth_kit')]
final class WebauthnRenameFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addWithDefaults($builder, 'label', TextType::class, [
            'label'       => 'webauthn.account.label',
            'required'    => true,
            'help'        => false,
            'placeholder' => false,
            'constraints' => [new NotBlank(), new Length(max: WebauthnManager::LABEL_MAX_LENGTH)],
            'attr'        => ['maxlength' => WebauthnManager::LABEL_MAX_LENGTH, 'autocomplete' => 'off'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'webauthn_rename';
    }
}
