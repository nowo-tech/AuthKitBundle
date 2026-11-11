<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security\LoginTarget;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Throwable;

use function in_array;
use function is_array;
use function is_string;

/**
 * Keeps a background call from replacing the post-login target (1.44). When a signed-out tab's poll (fetch, XHR,
 * EventSource…) hits a protected URL, the firewall saves that URL as `_security.<firewall>.target_path`, over the page
 * the user was sent to the login from. This listener remembers the saved target before the firewall's exception
 * listener (priority 1) runs and puts it back on the response.
 *
 * The previous value lives in a request attribute only (worker safe: nothing kept on the service).
 */
final readonly class BackgroundTargetPathPreserver
{
    public const ATTRIBUTE = '_nowo_auth_kit_previous_target_path';

    /**
     * @param list<string> $firewalls
     */
    public function __construct(
        private Security $security,
        private array $firewalls,
    ) {
    }

    /**
     * `kernel.exception`, priority 3: before the session-expired responder (2) and the firewall (1).
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasSession() || NavigationKind::isTargetPathCandidate($request)) {
            return;
        }
        if (!self::isSecurityException($event->getThrowable())) {
            return;
        }
        $firewall = $this->security->getFirewallConfig($request)?->getName();
        if (!is_string($firewall) || !in_array($firewall, $this->firewalls, true)) {
            return;
        }
        // Without a previous session there is nothing to keep (and reading would start a session).
        $previous = $request->hasPreviousSession() ? $request->getSession()->get('_security.' . $firewall . '.target_path') : null;
        $request->attributes->set(self::ATTRIBUTE, ['firewall' => $firewall, 'target' => is_string($previous) ? $previous : null]);
    }

    /**
     * `kernel.response`: undo the target the firewall saved for this very background request.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request  = $event->getRequest();
        $snapshot = $request->attributes->get(self::ATTRIBUTE);
        if (!$event->isMainRequest() || !is_array($snapshot) || !is_string($snapshot['firewall'] ?? null)) {
            return;
        }
        $request->attributes->remove(self::ATTRIBUTE);
        $session = $request->getSession();
        $key     = '_security.' . $snapshot['firewall'] . '.target_path';
        if (!$session->isStarted() || $session->get($key) !== $request->getUri()) {
            return;
        }
        $previous = $snapshot['target'] ?? null;
        if (is_string($previous) && $previous !== '') {
            $session->set($key, $previous);

            return;
        }
        $session->remove($key);
    }

    public static function isSecurityException(?Throwable $exception): bool
    {
        while ($exception instanceof Throwable) {
            if ($exception instanceof AuthenticationException || $exception instanceof AccessDeniedException) {
                return true;
            }
            $exception = $exception->getPrevious();
        }

        return false;
    }
}
