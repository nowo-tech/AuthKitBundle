<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LoginTarget;

use Nowo\AuthKitBundle\Security\LoginTarget\LoginTarget;
use Nowo\AuthKitBundle\Security\LoginTarget\LoginTargetVoterInterface;

/**
 * Host voter written against the port only (plain PHP, no HTTP or Symfony type): `/docs/api` is an HTML page although
 * its path has an `api` segment.
 */
final class DocsApiPageVoter implements LoginTargetVoterInterface
{
    public function vote(LoginTarget $target): ?bool
    {
        return $target->routeName === 'lt_docs_api' ? true : null;
    }
}
