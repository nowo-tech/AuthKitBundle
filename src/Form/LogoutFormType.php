<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * POST sign-out of the embed widget when routes.logout.methods excludes GET (REQ-TWIG-005: rendered with form_start /
 * form_end instead of a raw <form>). Create it unnamed (FormFactory::createNamed('', …)) so the hidden field posts as
 * the firewall logout csrf_parameter; the token value comes from the "logout" CSRF token id (logout.enable_csrf).
 * The form's own CSRF protection is off: the Symfony LogoutListener validates that field.
 */
final class LogoutFormType extends AbstractType
{
    public const CSRF_PARAMETER = '_csrf_token';

    public const CSRF_TOKEN_ID = 'logout';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(self::CSRF_PARAMETER, HiddenType::class, [
            'mapped' => false,
            'data'   => $options['logout_csrf_token'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'            => 'POST',
            'csrf_protection'   => false,
            'logout_csrf_token' => '',
        ]);
        $resolver->setAllowedTypes('logout_csrf_token', 'string');
    }

    public function getBlockPrefix(): string
    {
        return 'auth_kit_logout';
    }
}
