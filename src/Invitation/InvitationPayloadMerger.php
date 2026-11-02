<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use Nowo\AuthKitBundle\DependencyInjection\InvitationsConfiguration;

use function array_is_list;
use function array_key_exists;
use function array_replace;
use function in_array;
use function is_array;

/**
 * How a re-invite merges its payload into the open invitation (`invitations.payload_merge`, 1.33): `replace` (top-level
 * keys replaced, the 1.32 behaviour) or `deep` (maps merged recursively, lists unioned in order, scalars replaced).
 */
final class InvitationPayloadMerger
{
    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $incoming
     *
     * @return array<string, mixed>
     */
    public static function merge(string $strategy, array $current, array $incoming): array
    {
        if ($strategy !== InvitationsConfiguration::PAYLOAD_MERGE_DEEP) {
            return array_replace($current, $incoming);
        }

        /** @var array<string, mixed> $merged */
        $merged = self::deep($current, $incoming);

        return $merged;
    }

    /**
     * @param array<array-key, mixed> $current
     * @param array<array-key, mixed> $incoming
     *
     * @return array<array-key, mixed>
     */
    private static function deep(array $current, array $incoming): array
    {
        if ($current !== [] && $incoming !== [] && array_is_list($current) && array_is_list($incoming)) {
            foreach ($incoming as $value) {
                if (!in_array($value, $current, true)) {
                    $current[] = $value;
                }
            }

            return $current;
        }
        foreach ($incoming as $key => $value) {
            $current[$key] = is_array($value) && array_key_exists($key, $current) && is_array($current[$key])
                ? self::deep($current[$key], $value)
                : $value;
        }

        return $current;
    }
}
