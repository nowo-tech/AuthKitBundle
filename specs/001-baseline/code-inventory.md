# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/auth-kit-bundle`  
**Last audited**: 2026-10-20  
**Units**: **183** (`143` PHP under `src/` + `40` Resources)

Every production file under `src/` is listed exactly once. Status **Mapped** means a FR in `spec.md` owns it.

## CLI

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Command/ConfigureSecurityCommand.php` | production | FR-CLI-001 | Mapped |
| `Command/CreateAdminCommand.php` | production | FR-CLI-002 | Mapped |

## Admin provisioning

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Admin/AdminUserCreatedEvent.php` | production | FR-ADMIN-001 | Mapped |
| `Admin/AdminUserCreatingEvent.php` | production | FR-ADMIN-001 | Mapped |
| `Admin/AdminUserCreator.php` | production | FR-ADMIN-001 | Mapped |

## Recipient locale

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Locale/PreferredLocaleAwareInterface.php` | production | FR-RLOCALE-001 | Mapped |
| `Locale/PreferredLocaleAwareRecipientLocaleResolver.php` | production | FR-RLOCALE-001 | Mapped |
| `Locale/RecipientLocale.php` | production | FR-RLOCALE-001, FR-RLOCALE-002 | Mapped |
| `Locale/RecipientLocaleResolverInterface.php` | production | FR-RLOCALE-001 | Mapped |

## Flash messages

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Flash/AuthKitFlashMessenger.php` | production | FR-FLASH-001 | Mapped |
| `Twig/AuthKitFlashExtension.php` | production | FR-FLASH-002 | Mapped |

## Bundle & DI

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Config/FieldConfigNormalizer.php` | production | FR-CFG-002 | Mapped |
| `Config/RememberMeConfigResolver.php` | production | FR-CFG-002 | Mapped |
| `DependencyInjection/Compiler/LoginThrottleRequiredPass.php` | production | FR-SEC-004 | Mapped |
| `DependencyInjection/Compiler/TwigPathsPass.php` | production | FR-CFG-001 | Mapped |
| `DependencyInjection/Configuration.php` | production | FR-CFG-001 | Mapped |
| `DependencyInjection/NowoAuthKitExtension.php` | production | FR-CFG-002 | Mapped |
| `NowoAuthKitBundle.php` | production | FR-BUNDLE-001 | Mapped |

## Device intelligence (optional)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `DeviceIntelligence/DeviceIntelligenceContext.php` | production | FR-DIINTEL-001 | Mapped |
| `DeviceIntelligence/NewDeviceLoginNotificationContext.php` | production | FR-DIINTEL-001 | Mapped |
| `DeviceIntelligence/NewDeviceLoginNotifierInterface.php` | production | FR-DIINTEL-001 | Mapped |
| `DeviceIntelligence/NullNewDeviceLoginNotifier.php` | production | FR-DIINTEL-001 | Mapped |
| `EventSubscriber/NewDeviceLoginSubscriber.php` | production | FR-DIINTEL-001 | Mapped |
| `EventSubscriber/UnlocalizedDefaultLocaleSubscriber.php` | production | FR-LOCALE-001 | Mapped |

## HTTP controllers

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Controller/LoginController.php` | production | FR-CTRL-001 | Mapped |
| `Controller/LogoutController.php` | production | FR-CTRL-001 | Mapped |
| `Controller/RegisterController.php` | production | FR-CTRL-001 | Mapped |
| `Controller/ResetPasswordCodeController.php` | production | FR-CTRL-002 | Mapped |
| `Controller/ResetPasswordController.php` | production | FR-CTRL-002 | Mapped |
| `Controller/ResetPasswordRequestController.php` | production | FR-CTRL-002 | Mapped |

## Magic login

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Controller/MagicLoginCheckController.php` | production | FR-MAGIC-001 | Mapped |
| `Controller/MagicLoginConfirmController.php` | production | FR-MAGIC-001 | Mapped |
| `Controller/MagicLoginRequestController.php` | production | FR-MAGIC-001 | Mapped |
| `Enum/MagicLoginMode.php` | production | FR-MAGIC-001 | Mapped |
| `Form/MagicLoginConfirmType.php` | production | FR-MAGIC-001 | Mapped |
| `Form/MagicLoginRequestFormType.php` | production | FR-MAGIC-001 | Mapped |
| `MagicLogin/LoggingMagicLoginNotifier.php` | production | FR-MAGIC-001 | Mapped |
| `MagicLogin/MagicLoginGate.php` | production | FR-MAGIC-001 | Mapped |
| `MagicLogin/MagicLoginNotificationContext.php` | production | FR-MAGIC-001 | Mapped |
| `MagicLogin/MagicLoginNotifierInterface.php` | production | FR-MAGIC-001 | Mapped |
| `MagicLogin/MagicLoginRequestHandler.php` | production | FR-MAGIC-001 | Mapped |
| `MagicLogin/MagicLoginRequestedEvent.php` | production | FR-MAGIC-001 | Mapped |
| `MagicLogin/MagicLoginUserResolver.php` | production | FR-MAGIC-001 | Mapped |
| `MagicLogin/NullMagicLoginNotifier.php` | production | FR-MAGIC-001 | Mapped |

## QR login

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Controller/QrLoginApproveController.php` | production | FR-QR-001 | Mapped |
| `Controller/QrLoginCompleteController.php` | production | FR-QR-001 | Mapped |
| `Controller/QrLoginDenyController.php` | production | FR-QR-001 | Mapped |
| `Controller/QrLoginShowController.php` | production | FR-QR-001 | Mapped |
| `Controller/QrLoginStartController.php` | production | FR-QR-001 | Mapped |
| `Controller/QrLoginStatusController.php` | production | FR-QR-001 | Mapped |
| `DependencyInjection/Compiler/QrCodeGeneratorPass.php` | production | FR-QR-001 | Mapped |
| `Entity/QrLoginChallenge.php` | production | FR-QR-001 | Mapped |
| `Enum/QrLoginApproveMode.php` | production | FR-QR-001 | Mapped |
| `Enum/QrLoginChallengeStatus.php` | production | FR-QR-001 | Mapped |
| `Enum/QrLoginDesktopBinding.php` | production | FR-QR-001 | Mapped |
| `Enum/QrLoginMode.php` | production | FR-QR-001 | Mapped |
| `QrLogin/DeviceIntelligenceQrLoginStepUp.php` | production | FR-DIINTEL-001 | Mapped |
| `QrLogin/EndroidQrCodeGenerator.php` | production | FR-QR-001 | Mapped |
| `QrLogin/Event/QrLoginApprovedEvent.php` | production | FR-QR-002 | Mapped |
| `QrLogin/Event/QrLoginChallengeCreatedEvent.php` | production | FR-QR-002 | Mapped |
| `QrLogin/Event/QrLoginCompletedEvent.php` | production | FR-QR-002 | Mapped |
| `QrLogin/Event/QrLoginDeniedEvent.php` | production | FR-QR-002 | Mapped |
| `QrLogin/NullQrCodeGenerator.php` | production | FR-QR-001 | Mapped |
| `QrLogin/NullQrLoginStepUp.php` | production | FR-QR-001 | Mapped |
| `QrLogin/QrCodeGeneratorInterface.php` | production | FR-QR-001 | Mapped |
| `QrLogin/QrLoginChallengeConflictException.php` | production | FR-QR-001 | Mapped |
| `QrLogin/QrLoginChallengeManager.php` | production | FR-QR-001 | Mapped |
| `QrLogin/QrLoginGate.php` | production | FR-QR-001 | Mapped |
| `QrLogin/QrLoginRateLimiter.php` | production | FR-QR-001 | Mapped |
| `QrLogin/QrLoginStepUpInterface.php` | production | FR-QR-001 | Mapped |
| `QrLogin/QrLoginUserResolver.php` | production | FR-QR-001 | Mapped |
| `Repository/QrLoginChallengeRepository.php` | production | FR-QR-001 | Mapped |
| `Form/QrLoginRetryType.php` | production | FR-QR-001 | Mapped |
| `Form/QrLoginDenyType.php` | production | FR-QR-001 | Mapped |
| `Resources/public/js/nowo-auth-kit-qr-login.js` | production | FR-QR-003 | Mapped |

## Social / enterprise SSO

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Controller/SocialLoginCheckController.php` | production | FR-SOCIAL-001 | Mapped |
| `Controller/SocialLoginStartController.php` | production | FR-SOCIAL-001 | Mapped |
| `Entity/SocialLoginAccount.php` | production | FR-SOCIAL-001 | Mapped |
| `Entity/SocialLoginCredential.php` | production | FR-SOCIAL-001 | Mapped |
| `Enum/SocialLoginMode.php` | production | FR-SOCIAL-001 | Mapped |
| `Repository/SocialLoginAccountRepository.php` | production | FR-SOCIAL-001 | Mapped |
| `Repository/SocialLoginCredentialRepository.php` | production | FR-SOCIAL-001 | Mapped |
| `SocialLogin/OAuth2Client.php` | production | FR-SOCIAL-001 | Mapped |
| `SocialLogin/OAuthEndpointUrlValidator.php` | production | FR-SOCIAL-001 | Mapped |
| `SocialLogin/ProviderEndpointCatalog.php` | production | FR-SOCIAL-001 | Mapped |
| `SocialLogin/SocialAccountLinker.php` | production | FR-SOCIAL-001 | Mapped |
| `SocialLogin/SocialLoginGate.php` | production | FR-SOCIAL-001 | Mapped |
| `SocialLogin/SocialLoginStateStore.php` | production | FR-SOCIAL-001 | Mapped |
| `SocialLogin/SocialUserProfile.php` | production | FR-SOCIAL-001 | Mapped |
| `SocialLogin/Pkce.php` | production | FR-SOCIAL-001 | Mapped |

## Profiles & locale

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Controller/UnlocalizedLocaleRedirectController.php` | production | FR-LOCALE-001 | Mapped |
| `Enum/LocaleInPathMode.php` | production | FR-LOCALE-001 | Mapped |
| `Enum/UnlocalizedLocaleMode.php` | production | FR-LOCALE-001 | Mapped |
| `Profile/ProfileRegistry.php` | production | FR-PROFILE-001 | Mapped |
| `Profile/ProfileSettings.php` | production | FR-PROFILE-001 | Mapped |
| `Profile/RequestProfileResolver.php` | production | FR-PROFILE-001 | Mapped |
| `Profile/UnknownProfileException.php` | production | FR-PROFILE-001 | Mapped |

## Embed

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Embed/AuthEmbedContext.php` | production | FR-EMBED-001 | Mapped |
| `Embed/AuthEmbedContextFactory.php` | production | FR-EMBED-001 | Mapped |
| `Embed/AuthEmbedOptions.php` | production | FR-EMBED-002 | Mapped |
| `Enum/AuthEmbedMode.php` | production | FR-EMBED-001 | Mapped |

## Password reset

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Enum/PasswordResetDeliveryMode.php` | production | FR-RESET-003 | Mapped |
| `Enum/PasswordResetMode.php` | production | FR-RESET-003 | Mapped |
| `PasswordReset/LoggingPasswordResetNotifier.php` | production | FR-RESET-002 | Mapped |
| `PasswordReset/NullPasswordResetNotifier.php` | production | FR-RESET-002 | Mapped |
| `PasswordReset/PasswordResetCompleter.php` | production | FR-RESET-001 | Mapped |
| `PasswordReset/PasswordResetGate.php` | production | FR-RESET-001 | Mapped |
| `PasswordReset/PasswordResetNotificationContext.php` | production | FR-RESET-002 | Mapped |
| `PasswordReset/PasswordResetNotifierInterface.php` | production | FR-RESET-002 | Mapped |
| `PasswordReset/PasswordResetRequestHandler.php` | production | FR-RESET-001 | Mapped |
| `PasswordReset/PasswordResetRequestedEvent.php` | production | FR-RESET-002 | Mapped |
| `PasswordReset/PasswordResetTokenManager.php` | production | FR-RESET-001 | Mapped |
| `PasswordReset/PasswordResetTokenManagerInterface.php` | production | FR-RESET-001 | Mapped |
| `PasswordReset/PasswordResetTokenResult.php` | production | FR-RESET-001 | Mapped |
| `PasswordReset/PasswordResetUserResolver.php` | production | FR-RESET-001 | Mapped |

## Enums

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Enum/RegistrationMode.php` | production | FR-ENUM-001 | Mapped |

## Forms & validation

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Form/LoginFormType.php` | production | FR-FORM-001 | Mapped |
| `Form/PasswordFieldConstraintResolver.php` | production | FR-FORM-002 | Mapped |
| `Form/PasswordFieldTypeResolver.php` | production | FR-FORM-002 | Mapped |
| `Form/PasswordRepeatedFieldBuilder.php` | production | FR-FORM-002 | Mapped |
| `Form/QrLoginApproveType.php` | production | FR-QR-001 | Mapped |
| `Form/RegistrationFormType.php` | production | FR-FORM-001 | Mapped |
| `Form/ResetPasswordCodeFormType.php` | production | FR-FORM-001, FR-OTP-001 | Mapped |
| `Form/ResetPasswordFormType.php` | production | FR-FORM-001 | Mapped |
| `Form/ResetPasswordRequestFormType.php` | production | FR-FORM-001 | Mapped |
| `Form/OtpInputTypeResolver.php` | production | FR-OTP-001 | Mapped |
| `Form/SlideToConfirmTypeResolver.php` | production | FR-SLIDE-001 | Mapped |

## Outbound mail gate

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Mailer/AlwaysOutboundMailReadyChecker.php` | production | FR-MAIL-001 | Mapped |
| `Mailer/OutboundMailReadyCheckerInterface.php` | production | FR-MAIL-001 | Mapped |

## Routing & Twig

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Routing/AuthKitRouteLoader.php` | production | FR-ROUT-001 | Mapped |
| `Routing/AuthKitRouteLocaleParameters.php` | production | FR-ROUT-001 | Mapped |
| `Routing/AbsoluteUrlGenerator.php` | production | FR-ROUT-002 | Mapped |
| `Routing/AuthKitUrlGenerator.php` | production | FR-ROUT-001 | Mapped |
| `Twig/AuthEmbedExtension.php` | production | FR-TWIG-001 | Mapped |
| `Twig/AuthKitRoutingExtension.php` | production | FR-TWIG-001 | Mapped |
| `Twig/AuthKitUiExtension.php` | production | FR-TWIG-002, FR-OTP-001 | Mapped |
| `Routing/PublicUrlGenerator.php` | production | FR-ROUT-002 | Mapped |

## Security integration

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Security/AuthKitAttemptLimiter.php` | production | FR-SEC-003 | Mapped |
| `Security/AuthKitFormLoginParameters.php` | production | FR-SEC-001 | Mapped |
| `Security/RegistrationGate.php` | production | FR-SEC-001 | Mapped |
| `Security/UserRegistrar.php` | production | FR-SEC-001 | Mapped |
| `Doctrine/EntityManagerRecovery.php` | production | FR-SEC-001 | Mapped |
| `Doctrine/TablePrefixListener.php` | production | FR-ORM-001 | Mapped |

## Symfony config

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Resources/config/routing.yaml` | production | FR-DI-001 | Mapped |
| `Resources/config/services.yaml` | production | FR-DI-001 | Mapped |

## Assets

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Resources/public/css/nowo-auth-kit.css` | production | FR-ASSET-001 | Mapped |

## i18n

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Resources/translations/NowoAuthKitBundle.de.yaml` | production | FR-I18N-001 | Mapped |
| `Resources/translations/NowoAuthKitBundle.en.yaml` | production | FR-I18N-001 | Mapped |
| `Resources/translations/NowoAuthKitBundle.es.yaml` | production | FR-I18N-001 | Mapped |
| `Resources/translations/NowoAuthKitBundle.fr.yaml` | production | FR-I18N-001 | Mapped |
| `Resources/translations/NowoAuthKitBundle.it.yaml` | production | FR-I18N-001 | Mapped |
| `Resources/translations/NowoAuthKitBundle.nl.yaml` | production | FR-I18N-001 | Mapped |
| `Resources/translations/NowoAuthKitBundle.pt.yaml` | production | FR-I18N-001 | Mapped |
| `Resources/translations/validators.de.yaml` | production | FR-I18N-002 | Mapped |
| `Resources/translations/validators.en.yaml` | production | FR-I18N-002 | Mapped |
| `Resources/translations/validators.es.yaml` | production | FR-I18N-002 | Mapped |
| `Resources/translations/validators.fr.yaml` | production | FR-I18N-002 | Mapped |
| `Resources/translations/validators.it.yaml` | production | FR-I18N-002 | Mapped |
| `Resources/translations/validators.nl.yaml` | production | FR-I18N-002 | Mapped |
| `Resources/translations/validators.pt.yaml` | production | FR-I18N-002 | Mapped |

## Twig templates

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Resources/views/_device_intelligence_assets.html.twig` | production | FR-DIINTEL-001 | Mapped |
| `Resources/views/_otp_input_assets.html.twig` | production | FR-OTP-001 | Mapped |
| `Resources/views/_registration_submit.html.twig` | production | FR-SLIDE-001 | Mapped |
| `Resources/views/_slide_to_confirm_assets.html.twig` | production | FR-SLIDE-001 | Mapped |
| `Resources/views/embed/_authenticated.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/embed/_login_panel.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/embed/_register_panel.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/embed/dropdown.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/layout.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/security/login.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/security/magic_login_confirm.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/security/magic_login_request.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/security/qr_login_approve.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/security/qr_login_show.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/security/register.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/security/reset_password.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/security/reset_password_code.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/security/reset_request.html.twig` | production | FR-TWIG-001 | Mapped |

## UiKit page contract

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `View/AuthPageFactory.php` | production | FR-UI-001 | Mapped |
| `Resources/views/security/_auth_page.html.twig` | production | FR-UI-001 | Mapped |
| `Resources/views/_auth_kit_styles.html.twig` | production | FR-UI-001, FR-ASSET-001 | Mapped |
| `Resources/views/_auth_kit_scripts.html.twig` | production | FR-UI-001, FR-TWIG-003 | Mapped |
| `Resources/views/form/password_toggle_widget.html.twig` | production | FR-UI-001 | Mapped |

## Summary

| Metric | Count |
| --- | ---: |
| PHP under `src/` | 143 |
| Resources (config/translations/views/public) | 40 |
| **Total production sources** | **183** |
| Audit command | `git ls-files src \| wc -l` → 183 |

## Coverage gate

- PHPUnit: `make test-coverage-100` (100% lines on `src/`, justified exclusions only).
- Spec Kit: this inventory MUST stay in lockstep with `find src -type f` (PHP + Resources).
- Non-goals without `src/` units yet: WebAuthn (`docs/WEBAUTHN.md` design-only).

