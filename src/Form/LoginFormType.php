<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Dynamic login form aligned with Symfony form_login field names.
 */
final class LoginFormType extends AbstractType
{
    /**
     * @param list<array{name: string, type: string, property: ?string, hash: bool, required: bool, security_name: ?string}> $loginFields
     */
    public function __construct(
        private readonly array $loginFields,
        private readonly PasswordFieldTypeResolver $passwordFieldTypeResolver,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach ($this->loginFields as $field) {
            /** @var class-string<FormTypeInterface> $type */
            $type = match ($field['type']) {
                'email'    => EmailType::class,
                'password' => $this->passwordFieldTypeResolver->resolve(),
                'checkbox' => CheckboxType::class,
                default    => TextType::class,
            };

            $builder->add($field['name'], $type, [
                'label'    => 'login.field.' . ($field['security_name'] === '_username' ? 'identifier' : ltrim($field['name'], '_')),
                'required' => $field['required'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
            'csrf_field_name'    => '_csrf_token',
            'csrf_token_id'      => 'authenticate',
        ]);
    }
}
