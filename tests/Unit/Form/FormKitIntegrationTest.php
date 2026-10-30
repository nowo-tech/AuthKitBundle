<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Form\FormTypeInterface;

use function class_exists;
use function class_uses;
use function dirname;
use function str_ends_with;
use function str_replace;
use function strlen;
use function substr;

/**
 * REQ-TWIG-005 rule 11: every page Form Type of the bundle is a FormKit Type bound to the bundle profile.
 */
final class FormKitIntegrationTest extends TestCase
{
    private const PROFILE = 'auth_kit';

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function pageFormTypes(): iterable
    {
        $src      = dirname(__DIR__, 3) . '/src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src . '/Form'));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Type.php')) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($src) + 1, -4);
            $class    = 'Nowo\\AuthKitBundle\\' . str_replace('/', '\\', $relative);
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(FormTypeInterface::class)) {
                continue;
            }

            yield $reflection->getShortName() => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('pageFormTypes')]
    public function itIsAFormKitTypeBoundToTheBundleProfile(string $class): void
    {
        $reflection = new ReflectionClass($class);
        if ($reflection->isSubclassOf(FormKitAbstractType::class)) {
            self::addToAssertionCount(1);

            return;
        }

        self::assertContains(FormOptionsTrait::class, class_uses($class), $class . ' must use FormOptionsTrait');
        $attributes = $reflection->getAttributes(FormKitConfig::class);
        self::assertCount(1, $attributes, $class . ' must carry #[FormKitConfig]');
        self::assertSame(self::PROFILE, $attributes[0]->newInstance()->name);
    }
}
