<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Ports;

/** Calls recorded by the rule ports (grants), compared between the 1.45 copy and 1.46. */
final class PortLog
{
    /** @var list<string> */
    public array $entries = [];
}
