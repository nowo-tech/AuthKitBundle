<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

use function array_combine;

/**
 * GET filters of the invitation list (FormKit type, REQ-TWIG-005 rules 6 and 11): e-mail search (`q`, bounded) and
 * status (`status`: open = pending + expired, the default). No CSRF (read-only); stable query names (empty block
 * prefix).
 */
#[FormKitConfig('auth_kit')]
final class InvitationFilterType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addWithDefaults($builder, 'q', SearchType::class, [
            'label'       => 'invitation.filter.q',
            'required'    => false,
            'help'        => false,
            'placeholder' => false,
            'attr'        => ['maxlength' => InvitationRepository::MAX_QUERY_LENGTH],
            'constraints' => [new Length(max: InvitationRepository::MAX_QUERY_LENGTH)],
        ]);
        $this->addWithDefaults($builder, 'status', ChoiceType::class, [
            'label'        => 'invitation.filter.status',
            'required'     => false,
            'help'         => false,
            'placeholder'  => false,
            'choices'      => array_combine(InvitationRepository::FILTERS, InvitationRepository::FILTERS),
            'choice_label' => static fn (string $status): string => 'invitation.filter.status_' . $status,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoAuthKitBundle::TRANSLATION_DOMAIN,
            'method'             => 'GET',
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
