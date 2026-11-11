<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security\LoginTarget;

use Nowo\AuthKitBundle\Security\LoginTarget\NavigationKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class NavigationKindTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: array<string, string>, 2: bool, 3: bool}>
     */
    public static function requests(): iterable
    {
        // method, headers, expectsHtml, isTargetPathCandidate
        yield 'top-level navigation' => ['GET', ['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document', 'Accept' => 'text/html'], true, true];
        yield 'navigation without dest' => ['GET', ['Sec-Fetch-Mode' => 'navigate'], true, true];
        yield 'frame navigation' => ['GET', ['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'iframe'], true, false];
        yield 'fetch' => ['GET', ['Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty', 'Accept' => '*/*'], false, false];
        yield 'same-origin fetch asking html' => ['GET', ['Sec-Fetch-Mode' => 'same-origin', 'Accept' => 'text/html'], false, false];
        yield 'image' => ['GET', ['Sec-Fetch-Mode' => 'no-cors', 'Sec-Fetch-Dest' => 'image'], false, false];
        yield 'empty fetch mode header' => ['GET', ['Sec-Fetch-Mode' => '  ', 'Accept' => 'text/html'], true, true];
        yield 'xhr' => ['GET', ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'], false, false];
        yield 'no metadata, json' => ['GET', ['Accept' => 'application/json'], false, false];
        yield 'no metadata, problem json' => ['GET', ['Accept' => 'application/problem+json'], false, false];
        yield 'no metadata, html and json' => ['GET', ['Accept' => 'text/html, application/json'], true, true];
        yield 'no metadata, xhtml' => ['GET', ['Accept' => 'application/xhtml+xml'], true, true];
        yield 'no metadata, anything' => ['GET', ['Accept' => '*/*'], true, false];
        yield 'no metadata, no accept' => ['GET', [], true, true];
        yield 'post navigation' => ['POST', ['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'], true, false];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('requests')]
    public function testKinds(string $method, array $headers, bool $expectsHtml, bool $candidate): void
    {
        $request = Request::create('http://example.com/x', $method);
        $request->headers->remove('Accept');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        self::assertSame($expectsHtml, NavigationKind::expectsHtml($request));
        self::assertSame($candidate, NavigationKind::isTargetPathCandidate($request));
    }
}
