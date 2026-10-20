<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Form;

use Nowo\AuthKitBundle\Form\QrLoginDenyType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;

final class QrLoginDenyTypeTest extends TestCase
{
    #[Test]
    public function itIsAFieldlessPostFormWithoutCsrf(): void
    {
        $form = Forms::createFormFactory()->createNamed('', QrLoginDenyType::class, null, ['action' => '/login/qr/abc/deny?t=x']);
        $view = $form->createView();

        self::assertSame('POST', $form->getConfig()->getMethod());
        self::assertFalse($form->getConfig()->getOption('csrf_protection'));
        self::assertSame('/login/qr/abc/deny?t=x', $view->vars['action']);
        self::assertCount(0, $view->children);
        self::assertSame('qr_login_deny', (new QrLoginDenyType())->getBlockPrefix());
    }
}
