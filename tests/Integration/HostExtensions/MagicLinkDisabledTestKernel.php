<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

final class MagicLinkDisabledTestKernel extends MagicLinkTestKernel
{
    protected const MODE = 'disabled';
}
