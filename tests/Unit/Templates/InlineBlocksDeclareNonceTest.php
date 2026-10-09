<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function strlen;

use const PREG_SET_ORDER;

/**
 * CSP convention: every inline <script>/<style> rendered by the bundle templates carries the
 * request attribute `csp_nonce` (when set), and no template uses inline event handlers
 * (onclick, onsubmit, ...), which no nonce can allow. External scripts and JSON islands are exempt.
 */
final class InlineBlocksDeclareNonceTest extends TestCase
{
    public function testInlineScriptsAndStylesDeclareTheNonce(): void
    {
        $missing = [];
        foreach ($this->templates() as $path => $source) {
            preg_match_all('#<(script|style)\b([^>]*)>(.*?)</\1>#is', $source, $blocks, PREG_SET_ORDER);
            foreach ($blocks as $block) {
                [, $tag, $attributes, $body] = $block;
                if (str_contains($attributes, 'nonce') || trim($body) === '') {
                    continue;
                }
                if (strtolower($tag) === 'script' && preg_match('#\bsrc=|type=["\']?(application/(ld\+)?json|text/template|importmap)#i', $attributes) === 1) {
                    continue;
                }
                $missing[] = $path . ': <' . $tag . $attributes . '>';
            }
        }

        self::assertSame([], $missing, 'Inline blocks without a CSP nonce (blocked under a nonce-based CSP).');
    }

    public function testTemplatesDoNotUseInlineEventHandlers(): void
    {
        $found = [];
        foreach ($this->templates() as $path => $source) {
            if (preg_match('#<[a-z][^>]*\son[a-z]+\s*=\s*["\']#i', $source) === 1) {
                $found[] = $path;
            }
        }

        self::assertSame([], $found, 'Inline event handlers are blocked by a CSP without unsafe-inline / unsafe-hashes.');
    }

    /**
     * @return iterable<string, string> template path => source without Twig comments
     */
    private function templates(): iterable
    {
        $root  = dirname(__DIR__, 3) . '/src/Resources/views';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // Twig comments describe tags ("injects a <style>") without rendering them.
            yield substr($file->getPathname(), strlen($root) + 1) => (string) preg_replace('/\{#.*?#\}/s', '', $source);
        }
    }
}
