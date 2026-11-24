# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/auth-kit-bundle`  
**Last audited**: 2026-11-24 (2.2.0)  
**Units**: **468** (`399` PHP under `src/` + `69` Resources)

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
| `DependencyInjection/Compiler/CsrfProtectionPass.php` | production | FR-SEC-005 | Mapped |
| `DependencyInjection/Compiler/LoginLinkHandlerLocatorPass.php` | production | FR-SEC-005 | Mapped |
| `DependencyInjection/Compiler/LoginThrottleRequiredPass.php` | production | FR-SEC-004 | Mapped |
| `DependencyInjection/Compiler/TwigPathsPass.php` | production | FR-CFG-001 | Mapped |
| `DependencyInjection/Configuration.php` | production | FR-CFG-001 | Mapped |
| `DependencyInjection/NowoAuthKitExtension.php` | production | FR-CFG-002, FR-INVH-007 | Mapped |
| `NowoAuthKitBundle.php` | production | FR-BUNDLE-001 | Mapped |

## Device intelligence (optional)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `DeviceIntelligence/DeviceIntelligenceContext.php` | production | FR-DIINTEL-001 | Mapped |
| `DeviceIntelligence/NewDeviceLoginNotificationContext.php` | production | FR-DIINTEL-001 | Mapped |
| `DeviceIntelligence/NewDeviceLoginNotifierInterface.php` | production | FR-DIINTEL-001 | Mapped |
| `DeviceIntelligence/NullNewDeviceLoginNotifier.php` | production | FR-DIINTEL-001 | Mapped |
| `EventSubscriber/NewDeviceLoginSubscriber.php` | production | FR-DIINTEL-001 | Mapped |
| `EventSubscriber/LocalizedLogoutRedirectSubscriber.php` | production | FR-LOCALE-001 | Mapped |
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
| `MagicLogin/AdminMagicLinkIssuedEvent.php` | production | FR-MAGIC-002 | Mapped |
| `MagicLogin/AdminMagicLinkIssuer.php` | production | FR-MAGIC-002 | Mapped |
| `MagicLogin/AdminMagicLinkRefusedException.php` | production | FR-MAGIC-002 | Mapped |
| `MagicLogin/LoggingMagicLoginNotifier.php` | production | FR-MAGIC-001 | Mapped |
| `MagicLogin/MagicLinkFactory.php` | production | FR-MAGIC-001, FR-MAGIC-002 | Mapped |
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
| `Port/Internal/QrLoginStepUpInterface.php` | production (2.0: internal adapter of the removed 1.x port) | FR-QR-001, FR-PORT-001 | Mapped |
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
| `PasswordReset/PasswordResetCheckEmail.php` | production | FR-RESET-004 | Mapped |
| `PasswordReset/PasswordResetCompleter.php` | production | FR-RESET-001 | Mapped |
| `PasswordReset/PasswordResetGate.php` | production | FR-RESET-001 | Mapped |
| `PasswordReset/PasswordResetNotificationContext.php` | production | FR-RESET-002 | Mapped |
| `PasswordReset/PasswordResetNotifierInterface.php` | production | FR-RESET-002 | Mapped |
| `PasswordReset/PasswordResetRequestHandler.php` | production | FR-RESET-001 | Mapped |
| `PasswordReset/PasswordResetRequestOutcome.php` | production | FR-RESET-004 | Mapped |
| `PasswordReset/PasswordResetRequestedEvent.php` | production | FR-RESET-002 | Mapped |
| `PasswordReset/PasswordResetThrottledException.php` | production | FR-RESET-004 | Mapped |
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
| `Form/Extension/ConstraintMessageTranslationExtension.php` | production | FR-FORM-005, FR-I18N-002 | Mapped |
| `Form/LoginFormType.php` | production | FR-FORM-001 | Mapped |
| `Form/LogoutFormType.php` | production | FR-FORM-004 | Mapped |
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
| `Routing/AuthKitRouteLoader.php` | production | FR-ROUT-001, FR-INVH-007 | Mapped |
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
| `Security/AuthKitAttemptLimiter.php` | production | FR-SEC-003, FR-INVF-003 | Mapped |
| `Security/AuthKitFormLoginParameters.php` | production | FR-SEC-001 | Mapped |
| `Security/LoginLinkHandlerLocator.php` | production | FR-SEC-005 | Mapped |
| `Security/UserIdentifierMatcher.php` | production | FR-SEC-005 | Mapped |
| `Doctrine/PasswordResetTokenInvalidationListener.php` | production | FR-SEC-005, FR-RESET-001 | Mapped |
| `Form/FormCsrf.php` | production | FR-SEC-005 | Mapped |
| `Form/IdentifierConstraints.php` | production | FR-SEC-005, FR-FORM-001 | Mapped |
| `Security/RegistrationGate.php` | production | FR-SEC-001 | Mapped |
| `Security/UserRegistrar.php` | production | FR-SEC-001, FR-INVH-001 | Mapped |
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

## Invitations ([spec 003](../003-invitations/spec.md), v1.32; host API [spec 004](../004-invitations-host-api/spec.md), v1.33)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Command/PurgeInvitationsCommand.php` | production | FR-INV-008 | Mapped |
| `Controller/InvitationAcceptController.php` | production | FR-INV-005, FR-INV-009, FR-INVH-002, FR-INVH-003, FR-INVF-005, FR-INVF-006 | Mapped |
| `Controller/InvitationAdminController.php` | production | FR-INV-004, FR-INV-009 | Mapped |
| `DependencyInjection/Compiler/InvitationsPass.php` | production | FR-INV-007 | Mapped |
| `DependencyInjection/InvitationsConfiguration.php` | production | FR-INV-004, FR-INV-009, FR-INVH-001, FR-INVH-005, FR-INVH-007, FR-INVF-002, FR-INVF-005 | Mapped |
| `Entity/Invitation.php` | production | FR-INV-001 | Mapped |
| `Enum/InvitationStatus.php` | production | FR-INV-001 | Mapped |
| `Form/InvitationFilterType.php` | production | FR-INV-004 | Mapped |
| `Form/InvitationFormType.php` | production | FR-INV-004 | Mapped |
| `Invitation/Event/InvitationAcceptedEvent.php` | production | FR-INV-003, FR-INV-010, FR-INVF-001 | Mapped |
| `Invitation/Event/InvitationAcceptingEvent.php` | production | FR-INVH-003, FR-INVF-001 | Mapped |
| `Invitation/Event/InvitationCreatedEvent.php` | production | FR-INV-003, FR-INV-010, FR-INVH-004 | Mapped |
| `Invitation/Event/InvitationEvent.php` | production | FR-INV-003, FR-INV-010 | Mapped |
| `Invitation/Event/InvitationLinkRejectedEvent.php` | production | FR-INVH-002 | Mapped |
| `Invitation/Event/InvitationOpeningEvent.php` | production | FR-INVF-006 | Mapped |
| `Invitation/Event/InvitationResentEvent.php` | production | FR-INV-003, FR-INV-010 | Mapped |
| `Invitation/Event/InvitationRevokedEvent.php` | production | FR-INV-003, FR-INV-010 | Mapped |
| `Invitation/Event/InvitationsPurgedEvent.php` | production | FR-INV-008 | Mapped |
| `Invitation/InvitationAcceptance.php` | production | FR-INVH-003, FR-INVH-006 | Mapped |
| `Invitation/InvitationAcceptor.php` | production | FR-INV-002, FR-INV-005, FR-INVH-002, FR-INVH-003, FR-INVH-006, FR-INVF-001, FR-INVF-004, FR-INVF-005, FR-INVF-006 | Mapped |
| `Port/Internal/InvitationGrantInterface.php` | production (2.0: internal adapter of the removed 1.x port) | FR-INV-005, FR-PORT-001 | Mapped |
| `Invitation/InvitationGrantTag.php` | production ([spec 004](../004-invitations-host-api/spec.md)) | FR-INVH-011 | Mapped |
| `Invitation/InvitationMail.php` | production | FR-INV-007 | Mapped |
| `Invitation/InvitationMailerInterface.php` | production | FR-INV-007 | Mapped |
| `Invitation/InvitationManager.php` | production | FR-INV-003, FR-INVH-004, FR-INVH-005, FR-INVH-008, FR-INVF-002 | Mapped |
| `Invitation/InvitationOutcome.php` | production | FR-INV-003 | Mapped |
| `Invitation/InvitationPayloadMerger.php` | production | FR-INVH-005 | Mapped |
| `Invitation/InvitationRefusal.php` | production | FR-INVH-003 | Mapped |
| `Invitation/InvitationRefusedException.php` | production | FR-INV-005, FR-INVH-003 | Mapped |
| `Invitation/InvitationRequest.php` | production | FR-INV-003, FR-INVH-004 | Mapped |
| `Invitation/InvitationSnapshot.php` | production | FR-INV-010 | Mapped |
| `Invitation/InvitationTokens.php` | production | FR-INV-002 | Mapped |
| `Invitation/MailerInvitationMailer.php` | production | FR-INV-007 | Mapped |
| `Invitation/NullInvitationMailer.php` | production | FR-INV-007 | Mapped |
| `Port/Internal/InvitationActionAuthorizerInterface.php` | production (2.0: internal adapter of the removed 1.x port) | FR-INVH-010, FR-PORT-001 | Mapped |
| `Invitation/Security/InvitationActionNames.php` | production | FR-INVH-011 | Mapped |
| `Port/Internal/InvitationsAccessCheckerInterface.php` | production (2.0: internal adapter of the removed 1.x port) | FR-INV-004, FR-PORT-001 | Mapped |
| `Invitation/Security/InvitationsAccessGuard.php` | production | FR-INV-004 | Mapped |
| `Invitation/Security/RoleInvitationsAccessChecker.php` | production | FR-INV-004 | Mapped |
| `Invitation/Ui/InvitationAdminPages.php` | production | FR-INV-004 | Mapped |
| `Invitation/Ui/InvitationRow.php` | production | FR-INV-004, FR-INV-010 | Mapped |
| `Repository/InvitationRepository.php` | production | FR-INV-002, FR-INV-004, FR-INV-008, FR-INVH-005 | Mapped |
| `Resources/views/email/invitation.html.twig` | production | FR-INV-007 | Mapped |
| `Resources/views/email/invitation.txt.twig` | production | FR-INV-007 | Mapped |
| `Resources/views/invitations/_cells.html.twig` | production | FR-INV-004 | Mapped |
| `Resources/views/invitations/index.html.twig` | production | FR-INV-004 | Mapped |
| `Resources/views/invitations/link.html.twig` | production | FR-INV-004, FR-INV-010 | Mapped |
| `Resources/views/invitations/new.html.twig` | production | FR-INV-004 | Mapped |
| `Resources/views/security/invitation_accept.html.twig` | production | FR-INV-005 | Mapped |
| `Resources/views/security/invitation_invalid.html.twig` | production | FR-INV-005 | Mapped |

## Feature availability (v1.37)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Availability/FeatureAvailability.php` | production | FR-AVAIL-001 | Mapped |
| `Availability/FeatureAvailabilityCheckerInterface.php` | production | FR-AVAIL-001 | Mapped |
| `DependencyInjection/Compiler/AvailabilityCheckersPass.php` | production | FR-AVAIL-001 | Mapped |

## Framework-free ports (v1.46)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `DependencyInjection/Compiler/AuthKitPortsPass.php` | production | FR-PORT-003 | Mapped |
| `DependencyInjection/Compiler/RemovedPortsPass.php` | production | FR-PORT-003 | Mapped |
| `Internal/HiddenValues.php` | production | FR-PORT-002 | Mapped |
| `Port/AccountPresentationGate.php` | production | FR-PORT-001 | Mapped |
| `Port/AuthRequestContexts.php` | production | FR-PORT-001 | Mapped |
| `Port/AuthUserRefs.php` | production | FR-PORT-001 | Mapped |
| `Port/EnrolmentPolicyGate.php` | production | FR-PORT-001 | Mapped |
| `Port/InvitationActionPolicyGate.php` | production | FR-PORT-001 | Mapped |
| `Port/InvitationGrantHandlerGate.php` | production | FR-PORT-001 | Mapped |
| `Port/InvitationsAccessPolicyGate.php` | production | FR-PORT-001 | Mapped |
| `Port/MfaRequirementPolicyGate.php` | production | FR-PORT-001 | Mapped |
| `Port/Ports.php` | production | FR-PORT-001 | Mapped |
| `Port/QrLoginStepUpGate.php` | production | FR-PORT-001 | Mapped |
| `Port/SocialCallbackStepGate.php` | production | FR-PORT-001 | Mapped |
| `Port/UserCreatorGate.php` | production | FR-PORT-001 | Mapped |
| `Port/UserDirectoryGate.php` | production | FR-PORT-001 | Mapped |
| `Port/UserStoreGate.php` | production | FR-PORT-001 | Mapped |
| `ValueObject/AuthRequestContext.php` | production | FR-PORT-002 | Mapped |
| `ValueObject/AuthUserRef.php` | production | FR-PORT-002 | Mapped |
| `ValueObject/EnrolmentCandidate.php` | production | FR-PORT-002 | Mapped |
| `ValueObject/QrLoginChallengeRef.php` | production | FR-PORT-002 | Mapped |
| `ValueObject/SocialCallbackResult.php` | production | FR-PORT-002 | Mapped |

## Private entity manager (v1.46)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `DependencyInjection/Compiler/EntityManagerOptionPass.php` | production | FR-PEM-003 | Mapped |
| `DependencyInjection/Compiler/PrivateEntityManagerPass.php` | production | FR-PEM-003 | Mapped |
| `Doctrine/AuthKitEntityManager.php` | production | FR-PEM-001 | Mapped |
| `Doctrine/AuthKitManagerRegistry.php` | production | FR-PEM-001 | Mapped |
| `Doctrine/PrivateEntityManagerConnection.php` | production | FR-PEM-002 | Mapped |
| `Doctrine/PrivateEntityManagerDriver.php` | production | FR-PEM-002 | Mapped |
| `Doctrine/PrivateEntityManagerMiddleware.php` | production | FR-PEM-002 | Mapped |
| `Doctrine/PrivateEntityManagerSubscriber.php` | production | FR-PEM-002 | Mapped |
| `Doctrine/AuthKitEntities.php` | production | FR-PEM-005 | Mapped |
| `Doctrine/AuthKitEntityNotFoundException.php` | production | FR-PEM-005 | Mapped |
| `Doctrine/AuthKitEntityNotManagedException.php` | production | FR-PEM-005 | Mapped |

## Multi-factor authentication (v1.34)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `DependencyInjection/Compiler/MfaLockRequiredPass.php` | production | FR-MFA-006 | Mapped |
| `DependencyInjection/MfaConfiguration.php` | production | FR-MFA-001 | Mapped |
| `Entity/MfaCredential.php` | production | FR-MFA-002 | Mapped |
| `Entity/MfaRecoveryCode.php` | production | FR-MFA-002 | Mapped |
| `Entity/MfaTrustedDevice.php` | production | FR-MFA-002 | Mapped |
| `Mfa/Controller/MfaAccountController.php` | production | FR-MFA-003 | Mapped |
| `Mfa/Controller/MfaAdminController.php` | production | FR-MFA-003 | Mapped |
| `Mfa/Controller/MfaChallengeController.php` | production | FR-MFA-003 | Mapped |
| `Mfa/EventSubscriber/MfaAuthenticationSubscriber.php` | production | FR-MFA-005 | Mapped |
| `Mfa/EventSubscriber/MfaContainmentSubscriber.php` | production | FR-MFA-005 | Mapped |
| `Mfa/EventSubscriber/MfaPasswordChangeListener.php` | production | FR-MFA-005 | Mapped |
| `Mfa/EventSubscriber/MfaSessionsSubscriber.php` | production | FR-MFA-005 | Mapped |
| `Mfa/Form/MfaCodeFormType.php` | production | FR-MFA-001 | Mapped |
| `Mfa/MfaCheck.php` | production | FR-MFA-001 | Mapped |
| `Mfa/MfaEnrolment.php` | production | FR-MFA-001 | Mapped |
| `Mfa/MfaFirstFactor.php` | production | FR-MFA-001 | Mapped |
| `Mfa/MfaLoginCompleter.php` | production | FR-MFA-001 | Mapped |
| `Mfa/MfaManager.php` | production | FR-MFA-001 | Mapped |
| `Mfa/MfaOwner.php` | production | FR-MFA-001 | Mapped |
| `Mfa/MfaOwnerResolver.php` | production | FR-MFA-001 | Mapped |
| `Port/Internal/MfaPolicyInterface.php` | production (2.0: internal adapter of the removed 1.x port) | FR-MFA-001, FR-PORT-001 | Mapped |
| `Mfa/MfaRequirement.php` | production | FR-MFA-001 | Mapped |
| `Mfa/MfaRequirementPolicyInterface.php` | production | FR-MFA-001 | Mapped |
| `Mfa/MfaStatus.php` | production | FR-MFA-001 | Mapped |
| `Mfa/MfaStore.php` | production | FR-MFA-001 | Mapped |
| `Mfa/MfaTrustedDevices.php` | production | FR-MFA-001 | Mapped |
| `Mfa/MfaUnavailableException.php` | production | FR-MFA-001 | Mapped |
| `Mfa/RecoveryCodes.php` | production | FR-MFA-001 | Mapped |
| `Mfa/Security/MfaPendingToken.php` | production | FR-MFA-001 | Mapped |
| `Mfa/Security/MfaVoter.php` | production | FR-MFA-001 | Mapped |
| `Mfa/Totp/Base32.php` | production | FR-MFA-007 | Mapped |
| `Mfa/Totp/Totp.php` | production | FR-MFA-007 | Mapped |
| `Mfa/Twig/MfaExtension.php` | production | FR-MFA-001 | Mapped |
| `Resources/config/mfa.yaml` | production | FR-MFA-001 | Mapped |
| `Resources/public/js/nowo-auth-kit-otp.js` | production | FR-MFA-008 | Mapped |
| `Resources/views/mfa/_account.html.twig` | production | FR-MFA-008 | Mapped |
| `Resources/views/mfa/_code_form.html.twig` | production | FR-MFA-008 | Mapped |
| `Resources/views/mfa/_codes.html.twig` | production | FR-MFA-008 | Mapped |
| `Resources/views/mfa/_codes_actions_kinds.html.twig` | production | FR-MFA-008 | Mapped |
| `Resources/views/mfa/_enrolment.html.twig` | production | FR-MFA-008 | Mapped |
| `Resources/views/mfa/account.html.twig` | production | FR-MFA-008 | Mapped |
| `Resources/views/mfa/account_codes.html.twig` | production | FR-MFA-008 | Mapped |
| `Resources/views/mfa/account_codes_grid.html.twig` | production | FR-MFA-008 | Mapped |
| `Resources/views/mfa/admin_reset.html.twig` | production | FR-MFA-008 | Mapped |
| `Resources/views/mfa/challenge.html.twig` | production | FR-MFA-008 | Mapped |
| `Resources/views/mfa/recovery_codes.html.twig` | production | FR-MFA-008 | Mapped |
| `Resources/views/mfa/setup.html.twig` | production | FR-MFA-008 | Mapped |

## Multi-account switcher (v1.34)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `DependencyInjection/Compiler/MultiAccountPass.php` | production | FR-MULTI-006 | Mapped |
| `DependencyInjection/MultiAccountConfiguration.php` | production | FR-MULTI-001 | Mapped |
| `Entity/AccountVaultEntry.php` | production | FR-MULTI-002 | Mapped |
| `MultiAccount/AccountPresentation.php` | production | FR-MULTI-001 | Mapped |
| `MultiAccount/AccountPresentationProviderInterface.php` | production | FR-MULTI-001 | Mapped |
| `Port/Internal/AccountPresenterInterface.php` | production (2.0: internal adapter of the removed 1.x port) | FR-MULTI-001, FR-PORT-001 | Mapped |
| `MultiAccount/AccountRemovalReason.php` | production | FR-MULTI-001 | Mapped |
| `MultiAccount/AccountState.php` | production | FR-MULTI-001 | Mapped |
| `MultiAccount/AccountVault.php` | production | FR-MULTI-001 | Mapped |
| `MultiAccount/AccountVaultStore.php` | production | FR-MULTI-001 | Mapped |
| `MultiAccount/Command/PurgeAccountVaultCommand.php` | production | FR-MULTI-005 | Mapped |
| `MultiAccount/Controller/AccountsController.php` | production | FR-MULTI-003 | Mapped |
| `MultiAccount/DefaultEnrolmentGate.php` | production | FR-MULTI-001 | Mapped |
| `Port/Internal/EnrolmentGateInterface.php` | production (2.0: internal adapter of the removed 1.x port) | FR-MULTI-001, FR-PORT-001 | Mapped |
| `MultiAccount/EnrolmentPolicyInterface.php` | production | FR-MULTI-001 | Mapped |
| `MultiAccount/EventSubscriber/MultiAccountSubscriber.php` | production | FR-MULTI-003 | Mapped |
| `MultiAccount/IdentifierAccountPresenter.php` | production | FR-MULTI-001 | Mapped |
| `MultiAccount/SessionHopper.php` | production | FR-MULTI-001 | Mapped |
| `MultiAccount/SwitchOutcome.php` | production | FR-MULTI-001 | Mapped |
| `MultiAccount/Twig/AccountRow.php` | production | FR-MULTI-007 | Mapped |
| `MultiAccount/Twig/AccountSwitcherView.php` | production | FR-MULTI-007 | Mapped |
| `MultiAccount/Twig/MultiAccountExtension.php` | production | FR-MULTI-007 | Mapped |
| `MultiAccount/VaultCookie.php` | production | FR-MULTI-001 | Mapped |
| `MultiAccount/VaultCrypto.php` | production | FR-MULTI-001 | Mapped |
| `MultiAccount/VaultEntry.php` | production | FR-MULTI-001 | Mapped |
| `Resources/config/multi_account.yaml` | production | FR-MULTI-001 | Mapped |
| `Resources/views/accounts/_switcher.html.twig` | production | FR-MULTI-007 | Mapped |
| `Resources/views/security/accounts_chooser.html.twig` | production | FR-MULTI-007 | Mapped |

## Login target and programmatic login

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `DependencyInjection/Compiler/ProgrammaticLoginAuthenticatorPass.php` | production | FR-LOGINT-003 | Mapped |
| `Security/LoginTarget/BackgroundTargetPathPreserver.php` | production | FR-LOGINT-001 | Mapped |
| `Security/LoginTarget/LoginTarget.php` | production | FR-LOGINT-001 | Mapped |
| `Security/LoginTarget/LoginTargetCacheWarmer.php` | production | FR-LOGINT-001 | Mapped |
| `Security/LoginTarget/LoginTargetFallbackProviderInterface.php` | production | FR-LOGINT-001 | Mapped |
| `Security/LoginTarget/LoginTargetGuard.php` | production | FR-LOGINT-001 | Mapped |
| `Security/LoginTarget/LoginTargetPolicy.php` | production | FR-LOGINT-001 | Mapped |
| `Security/LoginTarget/LoginTargetVoterInterface.php` | production | FR-LOGINT-001 | Mapped |
| `Security/LoginTarget/NavigationKind.php` | production | FR-LOGINT-001 | Mapped |
| `Security/ProgrammaticLoginAuthenticator.php` | production | FR-LOGINT-003 | Mapped |
| `Security/SessionExpired/SessionExpiredResponder.php` | production | FR-LOGINT-002 | Mapped |

## Transactions, locks and savepoints (v1.43-1.48)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Doctrine/AuthKitRows.php` | production | FR-TX-002 | Mapped |
| `Doctrine/AuthKitSavepoint.php` | production | FR-TX-002 | Mapped |
| `Doctrine/UnitOfWorkBoundaries.php` | production | FR-TX-003 | Mapped |
| `DependencyInjection/Compiler/ConflictResponsePass.php` | production | FR-TX-005 | Mapped |
| `EventSubscriber/StorageFailureSubscriber.php` | production | FR-TX-004 | Mapped |
| `Transaction/AccountLock.php` | production | FR-TX-001 | Mapped |
| `Transaction/AuthKitNamedLock.php` | production | FR-TX-001 | Mapped |
| `Transaction/AuthKitTransactionRunner.php` | production | FR-TX-001 | Mapped |

## Exact identifiers and collation (v1.47-1.49)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Doctrine/AuthKitExactKeyColumns.php` | production (REQ-SEC-006 declaration of the identity / access-key columns) | FR-EXACT-002 | Mapped |
| `Doctrine/IdentityCollationListener.php` | production | FR-EXACT-002 | Mapped |
| `Doctrine/PasswordResetCollationListener.php` | production | FR-EXACT-002 | Mapped |
| `Security/ExactIdentifier.php` | production | FR-EXACT-001 | Mapped |

## Password reset table storage (v1.46)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Doctrine/PasswordResetTableInvalidationListener.php` | production | FR-RESET-006 | Mapped |
| `PasswordReset/Entity/PasswordResetRequest.php` | production | FR-RESET-005 | Mapped |
| `PasswordReset/PasswordResetCompletedEvent.php` | production | FR-RESET-005 | Mapped |
| `PasswordReset/PasswordResetCredentialClaimInterface.php` | production (2.2: claim of the reset credential, REQ-RUNTIME-003) | FR-RESET-005 | Mapped |
| `PasswordReset/PasswordResetCredentials.php` | production | FR-RESET-005 | Mapped |
| `PasswordReset/PasswordResetLinkUsedException.php` | production (2.2: a lost claim answers as an invalid link) | FR-RESET-005 | Mapped |
| `PasswordReset/PasswordResetRequestRow.php` | production | FR-RESET-005 | Mapped |
| `PasswordReset/ProfilePasswordResetTokenManager.php` | production | FR-RESET-005 | Mapped |
| `PasswordReset/TablePasswordResetTokenManager.php` | production | FR-RESET-005 | Mapped |
| `Repository/PasswordResetRequestRepository.php` | production | FR-RESET-005 | Mapped |

## Social callback steps (v1.37, v1.46)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Enum/SocialCallbackFailure.php` | production | FR-SOCSTEP-001 | Mapped |
| `SocialLogin/FirstUserLockRequired.php` | production | FR-SOCSTEP-001 | Mapped |
| `SocialLogin/ProviderMarkResolver.php` | production | FR-SOCSTEP-001 | Mapped |
| `SocialLogin/SocialCallbackContext.php` | production | FR-SOCSTEP-001 | Mapped |
| `Port/Internal/SocialCallbackHandlerInterface.php` | production (2.0: internal adapter of the removed 1.x port) | FR-SOCSTEP-001, FR-PORT-001 | Mapped |
| `SocialLogin/SocialCallbackStepInterface.php` | production | FR-SOCSTEP-001 | Mapped |
| `SocialLogin/SocialHandlerSignInRollback.php` | production | FR-SOCSTEP-001 | Mapped |

## Identifier normalisation (v1.49)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Identity/ExactIdentifierNormalizer.php` | production | FR-IDNORM-001 | Mapped |
| `Identity/LoginIdentifierNormalizationListener.php` | production | FR-IDNORM-001 | Mapped |
| `Identity/LowercaseAsciiEmailIdentifierNormalizer.php` | production | FR-IDNORM-001 | Mapped |
| `Identity/NormalizingUserLookup.php` | production | FR-IDNORM-001 | Mapped |
| `Identity/UserIdentifierNormalizerInterface.php` | production | FR-IDNORM-001 | Mapped |

## Invitation host SPI (v1.46)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Invitation/InvitationGrantHandlerInterface.php` | production | FR-INV-011 | Mapped |
| `Invitation/Security/InvitationActionPolicyInterface.php` | production | FR-INV-011 | Mapped |
| `Invitation/Security/InvitationsAccessPolicyInterface.php` | production | FR-INV-011 | Mapped |

## Magic login subsystem extras

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `MagicLogin/MagicLoginDisabledListener.php` | production | FR-MAGIC-003 | Mapped |

## Multi-factor authentication events (v1.34)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Mfa/Event/MfaChallengeFailedEvent.php` | production | FR-MFA-004 | Mapped |
| `Mfa/Event/MfaChallengePassedEvent.php` | production | FR-MFA-004 | Mapped |
| `Mfa/Event/MfaDisabledEvent.php` | production | FR-MFA-004 | Mapped |
| `Mfa/Event/MfaEnabledEvent.php` | production | FR-MFA-004 | Mapped |
| `Mfa/Event/MfaEvent.php` | production | FR-MFA-004 | Mapped |
| `Mfa/Event/MfaLockedOutEvent.php` | production | FR-MFA-004 | Mapped |
| `Mfa/Event/MfaLoginCompletedEvent.php` | production | FR-MFA-004 | Mapped |
| `Mfa/Event/MfaRecoveryCodeUsedEvent.php` | production | FR-MFA-004 | Mapped |
| `Mfa/Event/MfaRecoveryCodesRegeneratedEvent.php` | production | FR-MFA-004 | Mapped |
| `Mfa/Event/MfaResetByAdminEvent.php` | production | FR-MFA-004 | Mapped |
| `Mfa/Event/MfaTrustedDeviceAddedEvent.php` | production | FR-MFA-004 | Mapped |
| `Mfa/Event/MfaTrustedDevicesForgottenEvent.php` | production | FR-MFA-004 | Mapped |

## GDPR ports and UserKit sections (v1.36-1.45)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Mfa/PersonalData/MfaPersonalDataEraser.php` | production | FR-GDPR-001 | Mapped |
| `Mfa/PersonalData/MfaPersonalDataExportProvider.php` | production | FR-GDPR-001 | Mapped |
| `MultiAccount/PersonalData/AccountSwitcherPersonalDataEraser.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/DefaultInvitationPersonalDataSubjectResolver.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/DoctrineInvitationPersonalData.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/DoctrineSocialAccountPersonalDataExporter.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/DormantAccountSwitcherPersonalDataEraser.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/DormantMfaPersonalDataEraser.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/ExactRows.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/InvitationErasureResult.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/InvitationPersonalDataErasedEvent.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/InvitationPersonalDataEraserInterface.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/InvitationPersonalDataExporterInterface.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/InvitationPersonalDataSubject.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/InvitationPersonalDataSubjectResolverInterface.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/InvitationUserKitEraser.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/InvitationUserKitExportProvider.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/SocialAccountPersonalDataEraser.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/SocialAccountPersonalDataExporterInterface.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/SocialAccountPersonalDataSubject.php` | production | FR-GDPR-001 | Mapped |
| `PersonalData/SocialAccountUserKitExportProvider.php` | production | FR-GDPR-001 | Mapped |

## Multi-account switcher events (v1.34)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `MultiAccount/Event/AccountAddedEvent.php` | production | FR-MULTI-004 | Mapped |
| `MultiAccount/Event/AccountEvent.php` | production | FR-MULTI-004 | Mapped |
| `MultiAccount/Event/AccountRemovedEvent.php` | production | FR-MULTI-004 | Mapped |
| `MultiAccount/Event/AccountSwitchedEvent.php` | production | FR-MULTI-004 | Mapped |
| `MultiAccount/Event/AccountSwitchingEvent.php` | production | FR-MULTI-004 | Mapped |

## QR login subsystem extras

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `QrLogin/QrLoginStepUpPolicyInterface.php` | production | FR-QR-004 | Mapped |

## XML mapping (DDD hosts)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Resources/config/doctrine-xml-password-reset/PasswordResetRequest.orm.xml` | production | FR-ORM-002 | Mapped |
| `Resources/config/doctrine-xml/AccountVaultEntry.orm.xml` | production | FR-ORM-002 | Mapped |
| `Resources/config/doctrine-xml/Invitation.orm.xml` | production | FR-ORM-002 | Mapped |
| `Resources/config/doctrine-xml/MfaCredential.orm.xml` | production | FR-ORM-002 | Mapped |
| `Resources/config/doctrine-xml/MfaRecoveryCode.orm.xml` | production | FR-ORM-002 | Mapped |
| `Resources/config/doctrine-xml/MfaTrustedDevice.orm.xml` | production | FR-ORM-002 | Mapped |
| `Resources/config/doctrine-xml/QrLoginChallenge.orm.xml` | production | FR-ORM-002 | Mapped |
| `Resources/config/doctrine-xml/SocialLoginAccount.orm.xml` | production | FR-ORM-002 | Mapped |
| `Resources/config/doctrine-xml/SocialLoginCredential.orm.xml` | production | FR-ORM-002 | Mapped |

## UiKit page contract

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Resources/public/img/social/github.svg` | production | FR-UI-002 | Mapped |
| `Resources/public/img/social/google.svg` | production | FR-UI-002 | Mapped |
| `Resources/public/img/social/microsoft.svg` | production | FR-UI-002 | Mapped |
| `View/ProviderLink.php` | production | FR-UI-002 | Mapped |
| `View/ProviderMark.php` | production | FR-UI-002 | Mapped |

## User ports (v1.41, v1.46)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `User/CredentialsChangedListener.php` | production | FR-USER-001 | Mapped |
| `User/Doctrine/DoctrineUserLookup.php` | production | FR-USER-001 | Mapped |
| `User/Doctrine/DoctrineUserWriter.php` | production | FR-USER-001 | Mapped |
| `User/DuplicateUserException.php` | production | FR-USER-001 | Mapped |
| `User/Event/CredentialsChangedEvent.php` | production | FR-USER-001 | Mapped |
| `User/Event/UserRegisteredEvent.php` | production | FR-USER-001 | Mapped |
| `User/NewUser.php` | production | FR-USER-001 | Mapped |
| `User/PropertyAccessUserFactory.php` | production | FR-USER-001 | Mapped |
| `User/UserCreationSource.php` | production | FR-USER-001 | Mapped |
| `User/UserCreatorInterface.php` | production | FR-USER-001 | Mapped |
| `User/UserDirectoryInterface.php` | production | FR-USER-001 | Mapped |
| `Port/Internal/UserFactoryInterface.php` | production (2.0: internal adapter of the removed 1.x port) | FR-USER-001, FR-PORT-001 | Mapped |
| `User/UserIds.php` | production | FR-USER-001 | Mapped |
| `Port/Internal/UserLookupInterface.php` | production (2.0: internal adapter of the removed 1.x port) | FR-USER-001, FR-PORT-001 | Mapped |
| `User/UserStoreInterface.php` | production | FR-USER-001 | Mapped |
| `Port/Internal/UserWriterInterface.php` | production (2.0: internal adapter of the removed 1.x port) | FR-USER-001, FR-PORT-001 | Mapped |

## Summary

| Metric | Count |
| --- | ---: |
| PHP under `src/` | 402 |
| Resources (config/translations/views/public) | 69 |
| **Total production sources** | **471** |
| Audit command | `git ls-files src \| wc -l` → 471 |

## Coverage gate

- PHPUnit: `make test-coverage-100` (100% lines on `src/`, justified exclusions only).
- Spec Kit: this inventory MUST stay in lockstep with `find src -type f` (PHP + Resources).
- Non-goals without `src/` units yet: WebAuthn (`docs/WEBAUTHN.md` design-only).

