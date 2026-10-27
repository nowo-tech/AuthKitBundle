<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form\Extension;

use Nowo\AuthKitBundle\Form\MagicLoginRequestFormType;
use Nowo\AuthKitBundle\Form\RegistrationFormType;
use Nowo\AuthKitBundle\Form\ResetPasswordCodeFormType;
use Nowo\AuthKitBundle\Form\ResetPasswordFormType;
use Nowo\AuthKitBundle\Form\ResetPasswordRequestFormType;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\ClearableErrorsInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;

/**
 * REQ-I18N-003: translates AuthKit constraint messages in the bundle domain (`NowoAuthKitBundle`).
 *
 * The Symfony validator translates every violation in the global `framework.validation.translation_domain`
 * (`validators`), so AuthKit keys such as `register.password.mismatch` come out of validation untranslated
 * (the bundle ships no `validators` catalogue). Once the root form is validated, this extension rebuilds
 * those errors from their message template in the bundle domain:
 *
 * - a key the application defines in its own `validators` catalogue keeps the validator's translation
 *   (BC with overrides written for AuthKit <= 1.30);
 * - a key known to the bundle domain is translated there (same parameters and plural count);
 * - any other error (Symfony's own constraint messages, other bundles) is left untouched.
 */
final class ConstraintMessageTranslationExtension extends AbstractTypeExtension
{
    /** Runs after the form ValidationListener (POST_SUBMIT, priority 0). */
    public const POST_SUBMIT_PRIORITY = -64;

    private const VALIDATORS_DOMAIN = 'validators';

    public function __construct(
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [
            RegistrationFormType::class,
            ResetPasswordFormType::class,
            ResetPasswordRequestFormType::class,
            ResetPasswordCodeFormType::class,
            MagicLoginRequestFormType::class,
        ];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$this->translator instanceof TranslatorInterface) {
            return;
        }

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->onPostSubmit(...), self::POST_SUBMIT_PRIORITY);
    }

    public function onPostSubmit(FormEvent $event): void
    {
        $form = $event->getForm();

        // Validation runs on the root form only, after every child was submitted.
        if (!$form->isRoot()) {
            return;
        }

        $domain = $form->getConfig()->getOption('translation_domain');

        $this->retranslate($form, is_string($domain) && $domain !== '' ? $domain : NowoAuthKitBundle::TRANSLATION_DOMAIN);
    }

    private function retranslate(FormInterface $form, string $domain): void
    {
        if ($form instanceof ClearableErrorsInterface) {
            $errors  = [];
            $changed = false;

            /** @var FormError $error getErrors() without $deep yields the form's own errors only */
            foreach ($form->getErrors() as $error) {
                $translated = $this->translateError($error, $domain);
                $changed    = $changed || $translated !== $error;
                $errors[]   = $translated;
            }

            if ($changed) {
                $form->clearErrors();
                foreach ($errors as $error) {
                    $form->addError($error);
                }
            }
        }

        foreach ($form as $child) {
            $this->retranslate($child, $domain);
        }
    }

    private function translateError(FormError $error, string $domain): FormError
    {
        $template = $error->getMessageTemplate();

        if ($template === '' || !$this->isBundleKey($template, $domain)) {
            return $error;
        }

        $parameters = $error->getMessageParameters();
        $plural     = $error->getMessagePluralization();
        if ($plural !== null) {
            $parameters['%count%'] = $plural;
        }

        /** @var TranslatorInterface $translator buildForm() registers the listener only with a translator */
        $translator = $this->translator;
        $message    = $translator->trans($template, $parameters, $domain);

        if ($message === $error->getMessage()) {
            return $error;
        }

        $rebuilt = new FormError($message, $template, $error->getMessageParameters(), $plural, $error->getCause());
        $origin  = $error->getOrigin();
        if ($origin instanceof FormInterface) {
            $rebuilt->setOrigin($origin);
        }

        return $rebuilt;
    }

    /**
     * Without a catalogue-aware translator the key is translated in the bundle domain unconditionally
     * (trans() returns the key itself when the domain does not define it).
     */
    private function isBundleKey(string $template, string $domain): bool
    {
        if (!$this->translator instanceof TranslatorBagInterface) {
            return true;
        }

        $catalogue = $this->translator->getCatalogue();

        return !$catalogue->has($template, self::VALIDATORS_DOMAIN) && $catalogue->has($template, $domain);
    }
}
