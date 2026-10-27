<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Symfony\Component\Form\FormInterface;

/**
 * SEC-004 P2: whether a form is really CSRF-protected. The AuthKit form types default `csrf_protection` to true,
 * but without Symfony's form CSRF extension (no `csrf_token_manager` option) nothing validates the token: callers
 * refuse to act instead of failing open.
 */
final class FormCsrf
{
    public static function isProtected(FormInterface $form): bool
    {
        $config = $form->getConfig();

        return $config->hasOption('csrf_token_manager') && $config->getOption('csrf_protection', false) === true;
    }
}
