<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Nowo\AuthKitBundle\View\ProviderMark;

use function is_array;
use function is_bool;
use function is_string;
use function strtolower;
use function trim;

/**
 * Resolves the brand mark of a provider button: the mark the bundle ships for a built-in provider, or the host's
 * `social_login.provider_marks` entry for that provider (which can also remove a mark).
 *
 * Pure resolution from the arguments, no state and no request data: safe under FrankenPHP worker mode with
 * `kernel.reset` off (REQ-RUNTIME-002).
 */
final class ProviderMarkResolver
{
    /** Asset package the bundle registers for its own public assets (`/bundles/nowoauthkit`). */
    public const ASSET_PACKAGE = 'nowo_auth_kit';

    /**
     * Marks shipped by the bundle, by provider key. The official artwork of each provider, byte for byte as the
     * provider publishes it (see docs/BRAND-MARKS.md for the source and the trademark position of each). Apple is
     * deliberately absent: Apple grants no permission to redistribute its logo, so a host that configures an `apple`
     * provider supplies the mark itself.
     *
     * @var array<string, string>
     */
    private const BUILT_IN = [
        'google'    => 'img/social/google.svg',
        'github'    => 'img/social/github.svg',
        'microsoft' => 'img/social/microsoft.svg',
    ];

    /** `on_white` value meaning "white backdrop in the light theme only" (2.4.0). */
    public const ON_WHITE_AUTO = 'auto';

    /**
     * Default `on_white` of the built-in marks (2.4.0): Google's guidelines draw the full-colour "G" straight on the dark
     * button (#131314) in the dark theme and on white in the light one, so `auto`; the others keep the white backdrop.
     *
     * @var array<string, bool|string>
     */
    private const BUILT_IN_ON_WHITE = [
        'google'    => self::ON_WHITE_AUTO,
        'github'    => true,
        'microsoft' => true,
    ];

    /**
     * @param array<string, mixed> $marks `social_login.provider_marks`, keyed by provider (case-insensitive)
     */
    public function resolve(string $provider, array $marks = []): ?ProviderMark
    {
        $key = strtolower(trim($provider));

        foreach ($marks as $candidate => $mark) {
            if (strtolower(trim((string) $candidate)) !== $key) {
                continue;
            }

            return $this->fromOverride(is_array($mark) ? $mark : [], $key);
        }

        $path = self::BUILT_IN[$key] ?? null;

        return $path === null ? null : self::mark($path, self::ASSET_PACKAGE, self::BUILT_IN_ON_WHITE[$key]);
    }

    /**
     * @param array<string, mixed> $mark
     */
    private function fromOverride(array $mark, string $provider): ?ProviderMark
    {
        $path = $mark['path'] ?? null;
        if (!is_string($path) || trim($path) === '') {
            // `false`, `null` or an empty path: this provider gets no mark, built-in or not.
            return null;
        }

        $package = $mark['package'] ?? null;
        $onWhite = $mark['on_white'] ?? null;

        return self::mark(
            $path,
            is_string($package) && trim($package) !== '' ? $package : null,
            is_bool($onWhite) || $onWhite === self::ON_WHITE_AUTO ? $onWhite : (self::BUILT_IN_ON_WHITE[$provider] ?? false),
        );
    }

    private static function mark(string $path, ?string $package, bool|string $onWhite): ProviderMark
    {
        $auto = $onWhite === self::ON_WHITE_AUTO;

        return new ProviderMark($path, $package, $auto || $onWhite === true, $auto);
    }
}
