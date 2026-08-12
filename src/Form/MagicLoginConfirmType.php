<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Confirm interstitial for magic login (signed login_link params + Form CSRF).
 *
 * Empty block prefix so {@code user} / {@code expires} / {@code hash} stay top-level
 * for {@see LoginLinkHandlerInterface::consumeLoginLink()}.
 */
#[FormKitConfig('auth_kit')]
final class MagicLoginConfirmType extends AbstractType
{
    use FormOptionsTrait;

    public const CSRF_TOKEN_ID = 'magic_login_confirm';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['user', 'expires', 'hash'] as $name) {
            $this->addWithDefaults($builder, $name, HiddenType::class, [
                'label'       => false,
                'required'    => true,
                'help'        => false,
                'placeholder' => false,
                'constraints' => [new NotBlank()],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
            'csrf_protection'    => true,
            'csrf_field_name'    => '_csrf_token',
            'csrf_token_id'      => self::CSRF_TOKEN_ID,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
