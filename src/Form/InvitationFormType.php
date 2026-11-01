<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

use function array_combine;

/**
 * Invite form of the administration (FormKit type, REQ-TWIG-005 rule 11): e-mail, the roles the inviter may grant
 * (`roles` option: only `invitations.roles` the inviter holds; a forged value fails the choice validation), the
 * validity (`ttl_choices`) and an optional personal message. CSRF token id bound to the profile
 * (`nowo_auth_kit_invitation_new/<profile>`).
 */
#[FormKitConfig('auth_kit')]
final class InvitationFormType extends AbstractType
{
    use FormOptionsTrait;

    public const CSRF_TOKEN_PREFIX = 'nowo_auth_kit_invitation_new';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addWithDefaults($builder, 'email', EmailType::class, [
            'label'       => 'invitation.form.email',
            'required'    => true,
            'help'        => false,
            'placeholder' => false,
            'attr'        => ['autocomplete' => 'off', 'maxlength' => Invitation::EMAIL_MAX_LENGTH],
            'constraints' => [
                new NotBlank(message: 'invitation.form.email_required'),
                new Email(message: 'invitation.form.email_invalid'),
                new Length(max: Invitation::EMAIL_MAX_LENGTH),
            ],
        ]);

        /** @var list<string> $roles */
        $roles = $options['roles'];
        if ($roles !== []) {
            $this->addWithDefaults($builder, 'roles', ChoiceType::class, [
                'label'                     => 'invitation.form.roles',
                'required'                  => false,
                'multiple'                  => true,
                'expanded'                  => true,
                'help'                      => false,
                'placeholder'               => false,
                'choices'                   => array_combine($roles, $roles),
                'choice_translation_domain' => false,
            ]);
        }

        /** @var list<int> $ttlChoices */
        $ttlChoices = $options['ttl_choices'];
        $this->addWithDefaults($builder, 'ttlDays', ChoiceType::class, [
            'label'                         => 'invitation.form.ttl_days',
            'required'                      => true,
            'help'                          => false,
            'placeholder'                   => false,
            'choices'                       => array_combine($ttlChoices, $ttlChoices),
            'choice_label'                  => static fn (int $days): string => 'invitation.ttl.days',
            'choice_translation_parameters' => static fn (int $days): array => ['%count%' => $days],
        ]);

        $this->addWithDefaults($builder, 'message', TextareaType::class, [
            'label'       => 'invitation.form.message',
            'required'    => false,
            'help'        => false,
            'placeholder' => false,
            'attr'        => ['maxlength' => Invitation::MESSAGE_MAX_LENGTH, 'rows' => 3],
            'constraints' => [new Length(max: Invitation::MESSAGE_MAX_LENGTH)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
            'csrf_protection'    => true,
            'roles'              => [],
            'ttl_choices'        => [7],
            'profile'            => 'default',
        ]);
        $resolver->setAllowedTypes('roles', 'string[]');
        $resolver->setAllowedTypes('ttl_choices', 'int[]');
        $resolver->setAllowedTypes('profile', 'string');
        $resolver->setDefault('csrf_token_id', static fn (Options $options): string => self::csrfTokenId((string) $options['profile']));
    }

    /** CSRF token id of the invite form of a profile (`/` cannot occur in a profile name segment). */
    public static function csrfTokenId(string $profile): string
    {
        return self::CSRF_TOKEN_PREFIX . '/' . $profile;
    }

    public function getBlockPrefix(): string
    {
        return 'invitation';
    }
}
