<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support;

use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;

use function call_user_func;

/**
 * Injects a minimal FormOptionsMerger (profile {@code auth_kit}) into Form Kit form types under test.
 */
final class FormKitTestSupport
{
    public static function merger(): FormOptionsMerger
    {
        $profile = [
            'translation_domain' => 'NowoAuthKitBundle',
            'defaults'           => [
                'attr'     => ['class' => 'nowo-ui-input form-control'],
                'row_attr' => ['class' => 'mb-2'],
            ],
            'field_types' => [],
        ];

        return new FormOptionsMerger(
            [
                'auth_kit' => $profile,
                'default'  => $profile,
            ],
            'auth_kit',
            new ConstraintDefinitionFactory(),
        );
    }

    /**
     * @template T of object
     *
     * @param T $formType
     *
     * @return T
     */
    public static function withMerger(object $formType): object
    {
        if (!method_exists($formType, 'setFormOptionsMerger')) {
            return $formType;
        }

        call_user_func([$formType, 'setFormOptionsMerger'], self::merger());

        return $formType;
    }
}
