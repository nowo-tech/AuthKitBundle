<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Saml;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Throwable;

/**
 * Throws from inside the sign-in of the SAML kernel tests (as a concurrent request's lock conflict or a failing host
 * listener would), so the unit of work that linked or created the account is rolled back.
 */
final class SignInFailureListener implements EventSubscriberInterface
{
    public ?Throwable $throw = null;

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLogin'];
    }

    public function onLogin(LoginSuccessEvent $event): void
    {
        if ($this->throw instanceof Throwable) {
            throw $this->throw;
        }
    }
}
