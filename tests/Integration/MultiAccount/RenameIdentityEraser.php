<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\MultiAccount;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;

/**
 * A host identity eraser (priority 90, like Beacon's): the e-mail, which is the user identifier, becomes a placeholder
 * for every eraser that runs later. The test entity uses the e-mail as primary key, so it is detached instead of
 * written (a real host keeps the row with the placeholder).
 */
final readonly class RenameIdentityEraser implements PersonalDataEraserInterface
{
    public const PRIORITY = 90;

    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function getKey(): string
    {
        return 'identity';
    }

    public function supports(object $user): bool
    {
        return $user instanceof KernelUser;
    }

    public function erase(ErasureRequest $request): void
    {
        if ($request->user instanceof KernelUser) {
            $this->em->detach($request->user);
            $request->user->setEmail('erased-' . md5($request->user->getEmail()) . '@invalid.local');
        }
    }
}
