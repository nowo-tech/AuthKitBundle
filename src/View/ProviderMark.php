<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\View;

use InvalidArgumentException;

use function sprintf;
use function trim;

/**
 * Brand mark of a social / SSO provider, rendered next to the button label (REQ-UI-003 page contract).
 *
 * The mark is an image asset, never an icon-font name or a recoloured glyph: the official Google "G" is a fixed
 * multi-colour mark that may not be recoloured, resized out of ratio or replaced by a monochrome version, and the
 * same holds for the other built-in providers. Rendering it as `<img>` makes a recolour impossible (host CSS cannot
 * reach inside an image), and `object-fit: contain` inside a square box keeps the aspect ratio.
 *
 * Plain data, no services and no request state: the login page resolves it per render (worker safe, REQ-RUNTIME-002).
 */
final readonly class ProviderMark
{
    /**
     * @param string $path asset path of the mark, relative to its package
     * @param string|null $package asset package holding the mark; null = the host's default package
     * @param bool $onWhite render the mark on a white backdrop with clear space around it (Google requires its "G"
     *                      on a white background, whatever the colour of the button)
     */
    public function __construct(
        public string $path,
        public ?string $package = null,
        public bool $onWhite = false,
    ) {
        if (trim($path) === '') {
            throw new InvalidArgumentException('A ProviderMark needs a non-empty asset path.');
        }
        if ($package !== null && trim($package) === '') {
            throw new InvalidArgumentException(sprintf('The asset package of the "%s" provider mark cannot be empty; use null for the default package.', $path));
        }
    }
}
