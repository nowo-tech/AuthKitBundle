<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\View;

use InvalidArgumentException;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\Link;

use function trim;

/**
 * One social / SSO provider button of the login page: the UiKit link (translatable label + href) plus the provider
 * key and its optional brand mark.
 *
 * `social_links` / `sso_links` carry these instead of plain {@see Link} since 1.48.0, so a host gets a brand-compliant
 * button without overriding the template. `label` and `href` keep the names a `Link` exposes, so a template that only
 * prints `_link.label` / `_link.href` is unaffected; {@see self::link()} hands back a real `Link` for a partial that
 * demands one.
 */
final readonly class ProviderLink
{
    public function __construct(
        public string $provider,
        public Label|string $label,
        public string $href,
        public ?ProviderMark $mark = null,
    ) {
        if (trim($provider) === '') {
            throw new InvalidArgumentException('A ProviderLink needs a non-empty provider key.');
        }
        if (trim($href) === '') {
            throw new InvalidArgumentException('A ProviderLink needs a non-empty href.');
        }
    }

    /**
     * The same link as a UiKit {@see Link} (without the mark), for hosts and partials typed against it.
     */
    public function link(): Link
    {
        return new Link($this->label, $this->href);
    }
}
