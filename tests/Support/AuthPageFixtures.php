<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support;

use Nowo\AuthKitBundle\Config\FieldConfigNormalizer;
use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\AuthKitBundle\Form\LoginFormType;
use Nowo\AuthKitBundle\Form\MagicLoginConfirmType;
use Nowo\AuthKitBundle\Form\MagicLoginRequestFormType;
use Nowo\AuthKitBundle\Form\PasswordFieldConstraintResolver;
use Nowo\AuthKitBundle\Form\PasswordFieldTypeResolver;
use Nowo\AuthKitBundle\Form\PasswordRepeatedFieldBuilder;
use Nowo\AuthKitBundle\Form\QrLoginApproveType;
use Nowo\AuthKitBundle\Form\QrLoginRetryType;
use Nowo\AuthKitBundle\Form\RegistrationFormType;
use Nowo\AuthKitBundle\Form\ResetPasswordCodeFormType;
use Nowo\AuthKitBundle\Form\ResetPasswordFormType;
use Nowo\AuthKitBundle\Form\ResetPasswordRequestFormType;
use Nowo\AuthKitBundle\Form\SlideToConfirmTypeResolver;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\Link;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validation;

/**
 * Realistic template contexts of the auth pages (the same `page` view model + 1.x variables the controllers pass),
 * for the page contract test and template tests.
 */
final class AuthPageFixtures
{
    public const FORM_THEMES = [Configuration::UI_KIT_FORM_THEME];

    /**
     * @return array<string, mixed>
     */
    public static function login(bool $withError = false, bool $withProviders = false): array
    {
        $overrides = ['remember_me' => ['enabled' => true], 'login_fields' => FieldConfigNormalizer::normalizeLoginFields(['identifier', 'password', 'remember_me'], 'email')];
        $profile   = self::profile($overrides);
        $form      = self::formFactory($overrides)->create(LoginFormType::class, null, ['action' => '/login', 'profile' => 'default']);
        $form->get('_username')->setData('ada@example.com');
        $error = $withError ? new BadCredentialsException() : null;
        $pages = new AuthPageFactory();
        $view  = $form->createView();

        $page = $pages->create('login', $profile, $view, [
            $pages->link('login.magic_login_link', '/magic-login'),
            $pages->link('login.qr_login_link', '/login/qr/start'),
            $pages->link('login.forgot_password_link', '/reset-password'),
            $pages->link('login.register_link', '/register'),
        ], $pages->authenticationError($error));

        return ['form_themes' => self::FORM_THEMES] + $pages->context('login', $page, $profile, [
            'login_form'             => $view,
            'error'                  => $error,
            'registration_allowed'   => true,
            'password_reset_enabled' => true,
            'magic_login_enabled'    => true,
            'qr_login_enabled'       => true,
            'social_login_enabled'   => $withProviders,
            'social_links'           => $withProviders ? [new Link(new Label('social.continue_with', NowoAuthKitBundle::TRANSLATION_DOMAIN, ['%provider%' => 'GitHub']), '/connect/github')] : [],
            'sso_login_enabled'      => $withProviders,
            'sso_links'              => $withProviders ? [new Link(new Label('sso.continue_with', NowoAuthKitBundle::TRANSLATION_DOMAIN, ['%provider%' => 'Okta']), '/connect/okta')] : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function register(?string $slideMode = null): array
    {
        $overrides = ['registration_fields' => FieldConfigNormalizer::normalizeRegistrationFields(['email', 'password', ['name' => 'terms', 'type' => 'checkbox', 'mapped' => false]])];
        $profile   = self::profile($overrides);
        $view      = self::formFactory($overrides)->create(RegistrationFormType::class, null, ['profile' => 'default'])->createView();
        $pages     = new AuthPageFactory();
        $page      = $pages->create('register', $profile, $view, [$pages->link('register.login_link', '/login')]);

        return ['form_themes' => self::FORM_THEMES] + $pages->context('register', $page, $profile, [
            'registration_form'     => $view,
            'slide_to_confirm_mode' => $slideMode,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function resetRequest(string $delivery = 'link'): array
    {
        $overrides = ['password_reset' => ['mode' => 'enabled', 'delivery' => $delivery]];
        $profile   = self::profile($overrides);
        $view      = self::formFactory($overrides)->create(ResetPasswordRequestFormType::class, null, ['profile' => 'default'])->createView();
        $pages     = new AuthPageFactory();
        $links     = $delivery !== 'link' ? [$pages->link('reset.request.enter_code', '/reset-password/code')] : [];
        $page      = $pages->create('reset_request', $profile, $view, [...$links, $pages->link('reset.request.back_to_login', '/login')]);

        return ['form_themes' => self::FORM_THEMES] + $pages->context('reset_request', $page, $profile, ['reset_request_form' => $view]);
    }

    /**
     * New password page; with $mismatch the repeated password carries the mismatch error (submitted form).
     *
     * @return array<string, mixed>
     */
    public static function resetPassword(bool $mismatch = false): array
    {
        $overrides = [];
        $profile   = self::profile($overrides);
        $form      = self::formFactory($overrides)->create(ResetPasswordFormType::class, null, ['csrf_protection' => false]);
        if ($mismatch) {
            $form->submit(['password' => ['first' => 'correct horse battery', 'second' => 'staple']]);
        }
        $view  = $form->createView();
        $pages = new AuthPageFactory();
        $page  = $pages->create('reset_password', $profile, $view, [$pages->link('reset.password.back_to_login', '/login')]);

        return ['form_themes' => self::FORM_THEMES] + $pages->context('reset_password', $page, $profile, ['reset_password_form' => $view]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function resetPasswordCode(bool $invalidCode = false): array
    {
        $overrides = ['password_reset' => ['mode' => 'enabled', 'delivery' => 'code']];
        $profile   = self::profile($overrides);
        $form      = self::formFactory($overrides)->create(ResetPasswordCodeFormType::class, null, ['profile' => 'default']);
        if ($invalidCode) {
            $form->get('code')->addError(new FormError('The code is invalid or has expired.', 'reset.code.flash_invalid'));
        }
        $view  = $form->createView();
        $pages = new AuthPageFactory();
        $page  = $pages->create('reset_password_code', $profile, $view, [
            $pages->link('reset.code.request_new', '/reset-password'),
            $pages->link('reset.code.back_to_login', '/login'),
        ]);

        return ['form_themes' => self::FORM_THEMES] + $pages->context('reset_password_code', $page, $profile, ['reset_password_code_form' => $view]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function magicLoginRequest(): array
    {
        $overrides = ['magic_login' => ['mode' => 'enabled']];
        $profile   = self::profile($overrides);
        $view      = self::formFactory($overrides)->create(MagicLoginRequestFormType::class, null, ['profile' => 'default'])->createView();
        $pages     = new AuthPageFactory();
        $page      = $pages->create('magic_login_request', $profile, $view, [$pages->link('magic_login.request.back_to_login', '/login')]);

        return ['form_themes' => self::FORM_THEMES] + $pages->context('magic_login_request', $page, $profile, ['magic_login_form' => $view]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function magicLoginConfirm(): array
    {
        $overrides = ['magic_login' => ['mode' => 'enabled', 'confirm_interstitial' => true]];
        $profile   = self::profile($overrides);
        $params    = ['user' => 'ada@example.com', 'expires' => '1760000000', 'hash' => 'c2lnbmVk'];
        $view      = self::formFactory($overrides)->createNamed('', MagicLoginConfirmType::class, $params, ['action' => '/magic-login/confirm'])->createView();
        $pages     = new AuthPageFactory();
        $page      = $pages->create('magic_login_confirm', $profile, $view, [$pages->link('magic_login.confirm.back_to_login', '/login')], attributes: ['data-turbo' => 'false']);

        return ['form_themes' => self::FORM_THEMES] + $pages->context('magic_login_confirm', $page, $profile, [
            'magic_login_confirm_form' => $view,
            'params'                   => $params,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function qrLoginShow(?string $stimulusController = null, bool $withImage = true): array
    {
        $overrides = ['qr_login' => ['mode' => 'enabled'], 'web_ui' => ['qr_login' => ['stimulus_controller' => $stimulusController]]];
        $profile   = self::profile($overrides);
        $view      = self::formFactory($overrides)->createNamed('', QrLoginRetryType::class, null, ['action' => '/login/qr/start'])->createView();
        $pages     = new AuthPageFactory();
        $id        = '0b1c2d3e-4f50-4a61-8b72-93a4b5c6d7e8';
        $page      = $pages->create('qr_login_show', $profile, $view, [$pages->link('qr_login.show.back_to_login', '/login')], attributes: $pages->stimulusAttributes($stimulusController, [
            'status-url'    => '/login/qr/' . $id . '/status',
            'complete-url'  => '/login/qr/' . $id . '/complete',
            'start-url'     => '/login/qr/start',
            'poll-interval' => 1500,
            'expires-at'    => 1760000090,
        ]));

        return ['form_themes' => self::FORM_THEMES] + $pages->context('qr_login_show', $page, $profile, [
            'status_url'             => '/login/qr/' . $id . '/status',
            'complete_url'           => '/login/qr/' . $id . '/complete',
            'start_url'              => '/login/qr/start',
            'qr_stimulus_controller' => $stimulusController,
            'challenge_id'           => $id,
            'public_code'            => 'K7Q-4MZ',
            'qr_data_uri'            => $withImage ? 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' : null,
            'qr_payload'             => 'https://app.example.com/login/qr/' . $id . '/approve?t=token',
            'expires_at'             => 1760000090,
            'expires_in'             => 83,
            'poll_interval_ms'       => 1500,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function qrLoginApprove(bool $slide = false): array
    {
        $profile  = self::profile(['qr_login' => ['mode' => 'enabled']]);
        $resolver = new SlideToConfirmTypeResolver(static fn (): bool => false);
        $view     = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addExtension(new CsrfExtension(self::csrfTokenManager()))
            ->addType(FormKitTestSupport::withMerger(new QrLoginApproveType($resolver)))
            ->getFormFactory()
            ->create(QrLoginApproveType::class, ['t' => 'approve-token'], ['slide_profile' => $slide ? 'danger' : null])
            ->createView();
        $pages = new AuthPageFactory();
        $page  = $pages->create('qr_login_approve', $profile, $view);

        return ['form_themes' => self::FORM_THEMES] + $pages->context('qr_login_approve', $page, $profile, [
            'challenge_id'    => '0b1c2d3e-4f50-4a61-8b72-93a4b5c6d7e8',
            'desktop_label'   => 'Firefox · Linux',
            'phone_hint'      => '+34 *** ** 78',
            'approve_token'   => 'approve-token',
            'deny_route'      => 'nowo_auth_kit_qr_login_deny',
            'deny_url'        => '/login/qr/0b1c2d3e-4f50-4a61-8b72-93a4b5c6d7e8/deny?t=approve-token',
            'approve_form'    => $view,
            'approve_details' => [
                new DetailItem(AuthPageFactory::label('qr_login.approve.device_label'), 'Firefox · Linux'),
                new DetailItem(AuthPageFactory::label('qr_login.approve.phone_label'), '+34 *** ** 78'),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function profile(array $overrides = []): ProfileSettings
    {
        return self::registry($overrides)->getDefault();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function registry(array $overrides = []): ProfileRegistry
    {
        return ProfileRegistryFactory::single(TestUser::class, $overrides);
    }

    public static function csrfTokenManager(): CsrfTokenManagerInterface
    {
        return new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'csrf-' . $tokenId);
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return $this->getToken($tokenId);
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return $token->getValue() === 'csrf-' . $token->getId();
            }
        };
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function formFactory(array $overrides): FormFactoryInterface
    {
        $registry      = self::registry($overrides);
        $typeResolver  = new PasswordFieldTypeResolver(toggleTypeExists: static fn (): bool => false);
        $repeated      = new PasswordRepeatedFieldBuilder($typeResolver, new PasswordFieldConstraintResolver($typeResolver));
        $slideResolver = new SlideToConfirmTypeResolver(static fn (): bool => false);

        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addExtension(new CsrfExtension(self::csrfTokenManager()))
            ->addType(FormKitTestSupport::withMerger(new LoginFormType($registry, $typeResolver)))
            ->addType(FormKitTestSupport::withMerger(new RegistrationFormType($registry, $repeated, $slideResolver)))
            ->addType(FormKitTestSupport::withMerger(new ResetPasswordRequestFormType($registry)))
            ->addType(FormKitTestSupport::withMerger(new ResetPasswordFormType($repeated)))
            ->addType(FormKitTestSupport::withMerger(new ResetPasswordCodeFormType($registry, $repeated)))
            ->addType(FormKitTestSupport::withMerger(new MagicLoginRequestFormType($registry)))
            ->addType(FormKitTestSupport::withMerger(new MagicLoginConfirmType()))
            ->getFormFactory();
    }
}
