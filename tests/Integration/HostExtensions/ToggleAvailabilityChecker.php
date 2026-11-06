<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use Nowo\AuthKitBundle\Availability\FeatureAvailabilityCheckerInterface;
use RuntimeException;

/**
 * Test checker: `answer` is set by the test (the kernel does not reboot between requests); null makes it throw.
 */
final class ToggleAvailabilityChecker implements FeatureAvailabilityCheckerInterface
{
    public ?bool $answer = true;

    public function isAvailable(string $profile): bool
    {
        return $this->answer ?? throw new RuntimeException('checker down');
    }
}
