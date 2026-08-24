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
use Symfony\Component\Validator\Constraints\NotBlank;

use function is_string;

/**
 * Phone-side QR login approval. The slide field is the submit when SlideToConfirm is available.
 */
#[FormKitConfig('auth_kit')]
final class QrLoginApproveType extends AbstractType
{
    use FormOptionsTrait;

    public const CSRF_TOKEN_ID = 'qr_login_approve';

    public function __construct(
        private readonly SlideToConfirmTypeResolver $slideToConfirmTypeResolver = new SlideToConfirmTypeResolver(),
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addWithDefaults($builder, 't', HiddenType::class, [
            'label'       => false,
            'required'    => true,
            'help'        => false,
            'placeholder' => false,
            'constraints' => [new NotBlank()],
        ]);

        $swipeType = $this->slideToConfirmTypeResolver->resolveSwipeType();
        $profile   = $options['slide_profile'];
        if ($swipeType !== null && is_string($profile) && $profile !== '') {
            $this->addWithDefaults($builder, 'confirm', $swipeType, [
                'label'              => false,
                'mapped'             => false,
                'required'           => true,
                'help'               => false,
                'placeholder'        => false,
                'profile'            => $profile,
                'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
                'text'               => 'qr_login.approve.slide',
                'confirmed_text'     => 'qr_login.approve.confirmed',
                'hint'               => 'qr_login.approve.slide_hint',
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
            'slide_profile'      => 'danger',
        ]);
        $resolver->setAllowedTypes('slide_profile', ['string', 'null']);
    }
}
