<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\EqualTo;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Builds password + confirmation fields for registration and reset flows.
 *
 * When password strength is enabled, only the primary field uses PasswordStrengthType;
 * the confirmation field uses the basic toggle/Symfony password type and validates match only.
 */
final class PasswordRepeatedFieldBuilder
{
    public function __construct(
        private readonly PasswordFieldTypeResolver $passwordFieldTypeResolver,
        private readonly PasswordFieldConstraintResolver $passwordFieldConstraintResolver,
    ) {
    }

    public function add(
        FormBuilderInterface $builder,
        string $name,
        string $firstLabel,
        string $secondLabel,
        string $mismatchMessage,
        string $requiredMessage,
        string $minLengthMessage,
    ): void {
        if ($this->passwordFieldTypeResolver->usesPasswordStrengthForNewPassword()) {
            $this->addWithStrengthPrimaryField(
                $builder,
                $name,
                $firstLabel,
                $secondLabel,
                $mismatchMessage,
                $requiredMessage,
                $minLengthMessage,
            );

            return;
        }

        $builder->add($name, RepeatedType::class, [
            'type'          => $this->passwordFieldTypeResolver->resolveForNewPassword(),
            'first_options' => array_merge([
                'label' => $firstLabel,
                'attr'  => ['autocomplete' => 'new-password'],
            ], $this->passwordFieldTypeResolver->newPasswordFieldOptions()),
            'second_options' => [
                'label' => $secondLabel,
                'attr'  => ['autocomplete' => 'new-password'],
            ],
            'invalid_message' => $mismatchMessage,
            'constraints'     => $this->passwordFieldConstraintResolver->newPasswordConstraints(
                $requiredMessage,
                $minLengthMessage,
            ),
        ]);
    }

    private function addWithStrengthPrimaryField(
        FormBuilderInterface $builder,
        string $name,
        string $firstLabel,
        string $secondLabel,
        string $mismatchMessage,
        string $requiredMessage,
        string $minLengthMessage,
    ): void {
        $builder->add($name, $this->passwordFieldTypeResolver->resolveForNewPassword(), array_merge([
            'label'       => $firstLabel,
            'attr'        => ['autocomplete' => 'new-password'],
            'constraints' => $this->passwordFieldConstraintResolver->newPasswordConstraints(
                $requiredMessage,
                $minLengthMessage,
            ),
        ], $this->passwordFieldTypeResolver->newPasswordFieldOptions()));

        $builder->add($name . '_confirm', $this->passwordFieldTypeResolver->resolve(), [
            'mapped'      => false,
            'label'       => $secondLabel,
            'attr'        => ['autocomplete' => 'new-password'],
            'constraints' => [
                new NotBlank(message: $requiredMessage),
                new EqualTo(
                    propertyPath: 'parent.all[' . $name . '].data',
                    message: $mismatchMessage,
                ),
            ],
        ]);
    }
}
