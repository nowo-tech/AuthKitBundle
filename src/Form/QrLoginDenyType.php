<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Field-less POST form of the QR approve page that denies the challenge (REQ-TWIG-005: rendered with form_start /
 * form_end instead of a raw <form>). No CSRF token: the deny route is authorised by the per-challenge approve token
 * carried in the action URL (`t`) plus the authenticated phone session, exactly as before the form type existed.
 */
#[FormKitConfig('auth_kit')]
final class QrLoginDenyType extends AbstractType
{
    use FormOptionsTrait;

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'          => 'POST',
            'csrf_protection' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'qr_login_deny';
    }
}
