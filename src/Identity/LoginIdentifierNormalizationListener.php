<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Identity;

use Nowo\AuthKitBundle\Security\AuthKitFormLoginParameters;
use Symfony\Component\HttpKernel\Event\RequestEvent;

use function is_array;
use function is_string;

/**
 * Password sign-in (1.49.0): the identifier posted by the AuthKit login form (`login_form[_username]`) is replaced by
 * its {@see UserIdentifierNormalizerInterface} form before the firewall runs (`kernel.request` priority 16, the firewall
 * listens at 8), so the host's user provider (`entity` provider: `findOneBy()`), Symfony's `login_throttling` and
 * LoginThrottleBundle all receive the normalised identifier. A refused value (null) and an unchanged value leave the
 * request untouched: the default normaliser never rewrites it. Only main POST requests carrying the form are touched.
 * Stateless (worker safe).
 *
 * @internal
 */
final readonly class LoginIdentifierNormalizationListener
{
    public const PRIORITY = 16;

    public function __construct(private UserIdentifierNormalizerInterface $normalizer)
    {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('POST')) {
            return;
        }

        $form = $request->request->all()[AuthKitFormLoginParameters::BLOCK_PREFIX] ?? null;
        if (!is_array($form) || !is_string($form['_username'] ?? null) || $form['_username'] === '') {
            return;
        }

        $normalized = $this->normalizer->normalize($form['_username']);
        if ($normalized === null || $normalized === $form['_username']) {
            return;
        }

        $form['_username'] = $normalized;
        $request->request->set(AuthKitFormLoginParameters::BLOCK_PREFIX, $form);
    }
}
