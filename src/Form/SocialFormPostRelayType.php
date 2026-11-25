<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\SocialLogin\SocialFormPostRelay;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The relay of a `response_mode=form_post` callback (2.3.0, Sign in with Apple): the provider's fields as hidden
 * inputs, posted again from the application's own origin so the (SameSite=Lax) session cookie travels with them.
 *
 * Empty block prefix so the fields stay top-level (`code`, `state`, …) as the provider sent them. No CSRF token: the
 * relay page is rendered without a session on purpose, and the OAuth `state` checked on the relayed request is the
 * CSRF protection of the callback.
 */
#[FormKitConfig('auth_kit')]
final class SocialFormPostRelayType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Only the fields the provider sent: an empty `error` would turn a successful callback into a denial.
        /** @var list<string> $fields */
        $fields = $options['relayed_fields'];
        foreach ([...$fields, SocialFormPostRelay::MARKER] as $name) {
            $this->addWithDefaults($builder, $name, HiddenType::class, [
                'label'       => false,
                'required'    => false,
                'help'        => false,
                'placeholder' => false,
            ]);
        }
        $this->addWithDefaults($builder, 'continue', SubmitType::class, ['label' => 'social.form_post.continue']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
            'csrf_protection'    => false,
            'relayed_fields'     => SocialFormPostRelay::FIELDS,
        ]);
        $resolver->setAllowedTypes('relayed_fields', 'string[]');
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
