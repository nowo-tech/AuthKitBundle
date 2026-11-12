<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Worker;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;

/** Answers the signed-in user's identifier (worker test). */
final readonly class WhoAmIController
{
    public function __construct(private Security $security)
    {
    }

    public function __invoke(): Response
    {
        return new Response($this->security->getUser()?->getUserIdentifier() ?? 'anonymous');
    }
}
