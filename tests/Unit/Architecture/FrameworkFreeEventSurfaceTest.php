<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Architecture;

use Nowo\AuthKitBundle\Invitation\InvitationAcceptance;
use Nowo\AuthKitBundle\MultiAccount\AccountState;
use Nowo\AuthKitBundle\MultiAccount\SwitchOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

use function array_merge;
use function class_exists;
use function count;
use function dirname;
use function in_array;
use function preg_match;
use function preg_quote;
use function sort;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * REQ-ARCH-001 rules 3 and 4 (1.46.0): the public surface of every event and result VO is framework-free, except the
 * members kept for BC, each marked `@deprecated` (removed in 2.0). A new framework-typed member fails this test.
 *
 * Construction is the bundle's side and is not scanned: constructors and static named constructors
 * (`SwitchOutcome::switched()`, `InvitationAcceptance::accepted()`, `UserRegisteredEvent::of()`) take the Symfony user
 * the bundle dispatches for; what a listener reads (properties, accessors) is.
 */
final class FrameworkFreeEventSurfaceTest extends TestCase
{
    private const ALLOWED_CLASSES = ['DateTimeImmutable', 'DateTimeInterface'];

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function surfaceClasses(): iterable
    {
        $src     = dirname(__DIR__, 3) . '/src';
        $classes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $file) {
            $path = (string) $file;
            if (!str_ends_with($path, 'Event.php')) {
                continue;
            }
            $class = 'Nowo\\AuthKitBundle\\' . str_replace('/', '\\', substr($path, strlen($src) + 1, -4));
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }
        $classes = array_merge($classes, [AccountState::class, SwitchOutcome::class, InvitationAcceptance::class]);
        sort($classes);

        foreach ($classes as $class) {
            yield $class => [$class];
        }
    }

    public function testTheScanFindsTheEvents(): void
    {
        self::assertGreaterThanOrEqual(40, count(iterator_to_array(self::surfaceClasses())));
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('surfaceClasses')]
    public function testOnlyDeprecatedMembersUseFrameworkTypes(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $offending  = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getDeclaringClass()->getName(), 'Nowo\\AuthKitBundle\\') || $method->isConstructor() || $method->isStatic() || $this->isDeprecated((string) $method->getDocComment())) {
                continue;
            }
            $types = [$method->getReturnType()];
            foreach ($method->getParameters() as $parameter) {
                $types[] = $parameter->getType();
            }
            foreach ($types as $type) {
                if ($this->isFrameworkType($type)) {
                    $offending[] = $method->getName() . '()';
                }
            }
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($this->isFrameworkType($property->getType()) && !$this->isDeprecatedProperty($property)) {
                $offending[] = '$' . $property->getName();
            }
        }

        self::assertSame([], $offending, $class . ': framework-typed public members without @deprecated');
    }

    private function isDeprecated(string $doc): bool
    {
        return preg_match('/@deprecated\b/', $doc) === 1;
    }

    /**
     * A property is deprecated by its own doc comment or by the `@param` of its (promoted) constructor parameter.
     */
    private function isDeprecatedProperty(ReflectionProperty $property): bool
    {
        if ($this->isDeprecated((string) $property->getDocComment())) {
            return true;
        }

        $name = preg_quote($property->getName(), '/');
        for ($class = $property->getDeclaringClass(); $class !== false; $class = $class->getParentClass()) {
            $constructor = $class->getConstructor();
            if ($constructor instanceof ReflectionMethod && preg_match('/@param\s+\S+\s+\$' . $name . '\b[^\n]*@deprecated/', (string) $constructor->getDocComment()) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isFrameworkType(?ReflectionType $type): bool
    {
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $inner) {
                if ($this->isFrameworkType($inner)) {
                    return true;
                }
            }

            return false;
        }
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin() || in_array($type->getName(), ['self', 'static'], true)) {
            return false;
        }

        $name = $type->getName();
        if (in_array($name, self::ALLOWED_CLASSES, true)) {
            return false;
        }

        // Bundle VOs, DTOs and enums are fine; bundle entities are not (rule 1).
        return !str_starts_with($name, 'Nowo\\AuthKitBundle\\') || str_starts_with($name, 'Nowo\\AuthKitBundle\\Entity\\');
    }
}
