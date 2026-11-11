<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PersonalData;

/** {@see GdprPortsTestKernel} with `nowo_auth_kit.personal_data.enabled: false`. */
final class GdprPortsDisabledTestKernel extends GdprPortsTestKernel
{
    protected const PERSONAL_DATA = false;
}
