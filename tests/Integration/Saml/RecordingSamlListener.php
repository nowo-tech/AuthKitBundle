<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Saml;

use Nowo\AuthKitBundle\Saml\Event\SamlResponseRejectedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records the SAML refusals of the kernel tests.
 */
final class RecordingSamlListener implements EventSubscriberInterface
{
    /** @var list<SamlResponseRejectedEvent> */
    public array $rejected = [];

    public static function getSubscribedEvents(): array
    {
        return [SamlResponseRejectedEvent::class => 'onRejected'];
    }

    public function onRejected(SamlResponseRejectedEvent $event): void
    {
        $this->rejected[] = $event;
    }
}
