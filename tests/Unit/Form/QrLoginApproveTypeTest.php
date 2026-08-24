<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Form;

use Nowo\AuthKitBundle\Form\QrLoginApproveType;
use Nowo\AuthKitBundle\Form\SlideToConfirmTypeResolver;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\Tests\Support\FormKitTestSupport;
use Nowo\SlideToConfirmBundle\DependencyInjection\Configuration;
use Nowo\SlideToConfirmBundle\Form\Type\SlideToConfirmType;
use Nowo\SlideToConfirmBundle\Form\Type\SwipeToSubmitType;
use Nowo\SlideToConfirmBundle\Profile\SlideToConfirmProfileRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

use function class_exists;
use function get_class;

final class QrLoginApproveTypeTest extends TestCase
{
    public function testContainsHiddenTokenWhenSlideUnavailable(): void
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(FormKitTestSupport::withMerger(new QrLoginApproveType(
                new SlideToConfirmTypeResolver(static fn (string $class): bool => false),
            )))
            ->getFormFactory();

        $form = $factory->create(QrLoginApproveType::class, ['t' => 'secret']);

        self::assertTrue($form->has('t'));
        self::assertFalse($form->has('confirm'));
        self::assertSame(HiddenType::class, get_class($form->get('t')->getConfig()->getType()->getInnerType()));
        self::assertSame(NowoAuthKitBundle::TRANSLATION_DOMAIN, $form->getConfig()->getOption('translation_domain'));
        self::assertSame(QrLoginApproveType::CSRF_TOKEN_ID, $form->getConfig()->getOption('csrf_token_id'));
        self::assertSame('danger', $form->getConfig()->getOption('slide_profile'));
    }

    public function testAddsSwipeFieldWhenSlideAvailable(): void
    {
        if (!class_exists(SwipeToSubmitType::class)
            && !class_exists(SlideToConfirmType::class)
        ) {
            self::markTestSkipped('nowo-tech/slide-to-confirm-bundle is not installed.');
        }

        $registry = new SlideToConfirmProfileRegistry(
            'danger',
            Configuration::builtinProfiles(),
        );
        $slideType = new SlideToConfirmType($registry, 'NowoSlideToConfirmBundle');
        $swipeType = class_exists(SwipeToSubmitType::class)
            ? new SwipeToSubmitType($registry, 'NowoSlideToConfirmBundle')
            : $slideType;

        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType($slideType)
            ->addType($swipeType)
            ->addType(FormKitTestSupport::withMerger(new QrLoginApproveType(
                new SlideToConfirmTypeResolver(static fn (string $class): bool => true),
            )))
            ->getFormFactory();

        $form = $factory->create(QrLoginApproveType::class, ['t' => 'secret'], [
            'csrf_protection' => false,
            'slide_profile'   => 'danger',
        ]);

        self::assertTrue($form->has('confirm'));
    }
}
