<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

use function is_string;

/**
 * Form to request a passwordless magic login link.
 */
#[FormKitConfig('auth_kit')]
final class MagicLoginRequestFormType extends AbstractType
{
    use FormOptionsTrait;

    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $profile = $this->resolveProfile($options);
        $type    = $profile->userIdentifierField === 'email' ? EmailType::class : TextType::class;

        $this->addWithDefaults($builder, 'identifier', $type, [
            'label'       => 'magic_login.request.field.identifier',
            'required'    => true,
            'help'        => false,
            'placeholder' => false,
            'constraints' => [new NotBlank(message: 'magic_login.request.identifier_required')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
            'profile'            => null,
        ]);
        $resolver->setAllowedTypes('profile', ['null', 'string']);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveProfile(array $options): ProfileSettings
    {
        $profileName = $options['profile'];

        return is_string($profileName) && $profileName !== ''
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();
    }
}
