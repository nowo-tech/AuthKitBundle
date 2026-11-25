<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\Form;

use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\Webauthn\WebauthnCeremonies;
use Nowo\AuthKitBundle\Webauthn\WebauthnManager;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

use function is_string;

/**
 * The form a WebAuthn ceremony posts (2.4.0): the browser script puts the JSON of the credential into the hidden
 * `credential` field and submits the form, so every ceremony POST is an ordinary CSRF-protected FormKit form (no raw
 * `<form>`, no custom CSRF header). `label: true` adds the passkey name of a registration. Used by the sign-in
 * (`webauthn_login`), the second step (`webauthn_mfa`) and the account page (`webauthn_register`).
 */
#[FormKitConfig('auth_kit')]
final class WebauthnCredentialFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['label_field'] === true) {
            $this->addWithDefaults($builder, 'label', TextType::class, [
                'label'       => 'webauthn.account.label',
                'required'    => false,
                'help'        => false,
                'placeholder' => false,
                'constraints' => [new Length(max: WebauthnManager::LABEL_MAX_LENGTH)],
                'attr'        => ['maxlength' => WebauthnManager::LABEL_MAX_LENGTH, 'autocomplete' => 'off'],
            ]);
        }
        $builder->add('credential', HiddenType::class, [
            'required'    => false,
            'constraints' => [new Length(max: WebauthnCeremonies::MAX_RESPONSE_BYTES)],
            'attr'        => ['data-nowo-auth-kit-webauthn-credential' => '1'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
            'label_field'        => false,
        ]);
        $resolver->setAllowedTypes('label_field', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'webauthn_credential';
    }

    /**
     * The submitted credential JSON ('' when the script did not run).
     *
     * @param FormInterface<mixed> $form
     */
    public static function credential(FormInterface $form): string
    {
        $value = $form->get('credential')->getData();

        return is_string($value) ? $value : '';
    }

    /**
     * The submitted label ('' without the field or when empty).
     *
     * @param FormInterface<mixed> $form
     */
    public static function label(FormInterface $form): string
    {
        $value = $form->has('label') ? $form->get('label')->getData() : null;

        return is_string($value) ? $value : '';
    }
}
