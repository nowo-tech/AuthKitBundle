<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\DemoDeliveryInbox;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class DemoAuthExtension extends AbstractExtension
{
    public function __construct(
        private readonly DemoDeliveryInbox $inbox,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('demo_auth_deliveries', $this->inbox->all(...)),
        ];
    }
}
