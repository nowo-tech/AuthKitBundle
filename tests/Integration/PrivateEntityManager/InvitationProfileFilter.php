<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Nowo\AuthKitBundle\Entity\Invitation;

/**
 * A host SQL filter (tenant style) on the invitations' profile, for the private entity manager filter tests.
 */
final class InvitationProfileFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->getName() !== Invitation::class || !$this->hasParameter('profile')) {
            return '';
        }

        return $targetTableAlias . '.profile = ' . $this->getParameter('profile');
    }
}
