<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Form\Extension;

use Nowo\AuthKitBundle\Form\Extension\ConstraintMessageTranslationExtension;
use Nowo\AuthKitBundle\Form\RegistrationFormType;
use Nowo\AuthKitBundle\Form\ResetPasswordFormType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function count;
use function iterator_to_array;

final class ConstraintMessageTranslationExtensionTest extends TestCase
{
    #[Test]
    public function itExtendsTheAuthKitFormsThatCarryConstraintMessages(): void
    {
        $types = iterator_to_array(ConstraintMessageTranslationExtension::getExtendedTypes(), false);

        self::assertContains(RegistrationFormType::class, $types);
        self::assertContains(ResetPasswordFormType::class, $types);
        self::assertCount(5, $types);
    }

    #[Test]
    public function itRegistersThePostSubmitListenerOnlyWithATranslator(): void
    {
        $factory = Forms::createFormFactory();

        $withTranslator = $factory->createBuilder();
        $before         = count($withTranslator->getEventDispatcher()->getListeners(FormEvents::POST_SUBMIT));
        (new ConstraintMessageTranslationExtension($this->translator()))->buildForm($withTranslator, []);
        $listeners = $withTranslator->getEventDispatcher()->getListeners(FormEvents::POST_SUBMIT);
        self::assertCount($before + 1, $listeners);
        $dispatcher = $withTranslator->getEventDispatcher();
        self::assertContains(
            ConstraintMessageTranslationExtension::POST_SUBMIT_PRIORITY,
            array_map(static fn (callable $listener): ?int => $dispatcher->getListenerPriority(FormEvents::POST_SUBMIT, $listener), $listeners),
        );

        $withoutTranslator = $factory->createBuilder();
        (new ConstraintMessageTranslationExtension())->buildForm($withoutTranslator, []);
        self::assertCount($before, $withoutTranslator->getEventDispatcher()->getListeners(FormEvents::POST_SUBMIT));
    }

    #[Test]
    public function itTranslatesBundleKeysInTheBundleDomainAndKeepsOtherErrors(): void
    {
        $form     = $this->form();
        $password = $form->get('password');
        $password->addError(new FormError('register.password.min_length', 'register.password.min_length', ['{{ limit }}' => '6'], 6));
        $password->addError(new FormError('This value should not be blank.', 'This value should not be blank.'));
        $password->addError(new FormError('', ''));
        $form->addError(new FormError('register.password.mismatch', 'register.password.mismatch'));

        $origin = $form->get('identifier');
        $error  = new FormError('register.password.required', 'register.password.required');
        $error->setOrigin($origin);
        $form->addError($error);

        (new ConstraintMessageTranslationExtension($this->translator()))->onPostSubmit(new FormEvent($form, null));

        self::assertSame(
            ['Your password should be at least 6 characters.', 'This value should not be blank.', ''],
            $this->messages($form->get('password')),
        );
        self::assertSame(['The password fields must match.', 'Please enter a password.'], $this->messages($form));

        $rebuilt = $form->getErrors()[0];
        self::assertInstanceOf(FormError::class, $rebuilt);
        self::assertSame('register.password.mismatch', $rebuilt->getMessageTemplate());
        self::assertSame($form, $rebuilt->getOrigin());
        $withOrigin = $form->getErrors()[1];
        self::assertInstanceOf(FormError::class, $withOrigin);
        self::assertSame($origin, $withOrigin->getOrigin());
        $plural = $form->get('password')->getErrors()[0];
        self::assertInstanceOf(FormError::class, $plural);
        self::assertSame(6, $plural->getMessagePluralization());
        self::assertSame(['{{ limit }}' => '6'], $plural->getMessageParameters());
    }

    #[Test]
    public function itKeepsTheApplicationValidatorsOverride(): void
    {
        $translator = $this->translator();
        $translator->addResource('array', ['register.password.mismatch' => 'Host override.'], 'en', 'validators');

        $form = $this->form();
        $form->addError(new FormError('Host override.', 'register.password.mismatch'));

        (new ConstraintMessageTranslationExtension($translator))->onPostSubmit(new FormEvent($form, null));

        self::assertSame(['Host override.'], $this->messages($form));
    }

    #[Test]
    public function itUsesTheFormTranslationDomain(): void
    {
        $translator = $this->translator();
        $translator->addResource('array', ['register.password.mismatch' => 'Custom domain.'], 'en', 'app');

        $form = $this->form(['translation_domain' => 'app']);
        $form->addError(new FormError('register.password.mismatch', 'register.password.mismatch'));

        (new ConstraintMessageTranslationExtension($translator))->onPostSubmit(new FormEvent($form, null));

        self::assertSame(['Custom domain.'], $this->messages($form));
    }

    #[Test]
    public function itIgnoresNonRootForms(): void
    {
        $form = $this->form();
        $form->get('password')->addError(new FormError('register.password.required', 'register.password.required'));

        (new ConstraintMessageTranslationExtension($this->translator()))->onPostSubmit(new FormEvent($form->get('password'), null));

        self::assertSame(['register.password.required'], $this->messages($form->get('password')));
    }

    #[Test]
    public function itTranslatesEveryKeyWithATranslatorWithoutCatalogue(): void
    {
        $translator = new class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id === 'register.password.required' ? '[' . $domain . '] ' . $id : $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        $form = $this->form();
        $form->addError(new FormError('register.password.required', 'register.password.required'));
        $form->addError(new FormError('Already translated.', 'Already translated.'));

        (new ConstraintMessageTranslationExtension($translator))->onPostSubmit(new FormEvent($form, null));

        self::assertSame(['[NowoAuthKitBundle] register.password.required', 'Already translated.'], $this->messages($form));
    }

    private function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'register.password.mismatch'   => 'The password fields must match.',
            'register.password.required'   => 'Please enter a password.',
            'register.password.min_length' => 'Your password should be at least {{ limit }} characters.',
        ], 'en', 'NowoAuthKitBundle');

        return $translator;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function form(array $options = []): FormInterface
    {
        return Forms::createFormFactory()->createBuilder(FormType::class, null, $options)
            ->add('identifier', TextType::class)
            ->add('password', TextType::class, ['error_bubbling' => false])
            ->getForm();
    }

    /**
     * @return list<string>
     */
    private function messages(FormInterface $form): array
    {
        $messages = [];
        foreach ($form->getErrors() as $error) {
            self::assertInstanceOf(FormError::class, $error);
            $messages[] = $error->getMessage();
        }

        return $messages;
    }
}
