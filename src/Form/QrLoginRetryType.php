<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Field-less GET form of the QR challenge page: submitting it starts a new challenge (the no-JS "generate a new
 * code" retry). It backs the UiKit auth scaffold form, whose fields block renders the challenge (QR, code, status,
 * countdown). No CSRF token: a GET to the start route only creates a challenge bound to this browser.
 */
final class QrLoginRetryType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'          => 'GET',
            'csrf_protection' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'qr_login_retry';
    }
}
