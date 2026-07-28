# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/auth-kit-bundle`  
**Last audited**: 2026-07-28  

## Symfony config

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Resources/config/routing.yaml` | production | FR-DI-001 | Mapped |
| `Resources/config/services.yaml` | production | FR-DI-001 | Mapped |

## Bundle & DI

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Config/FieldConfigNormalizer.php` | production | FR-CFG-002 | Mapped |
| `Config/RememberMeConfigResolver.php` | production | FR-CFG-002 | Mapped |
| `DependencyInjection/Compiler/TwigPathsPass.php` | production | FR-CFG-001 | Mapped |
| `DependencyInjection/Configuration.php` | production | FR-CFG-001 | Mapped |
| `DependencyInjection/NowoAuthKitExtension.php` | production | FR-CFG-001 | Mapped |
| `NowoAuthKitBundle.php` | production | FR-BUNDLE-001 | Mapped |

## Enums

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Enum/AuthEmbedMode.php` | production | FR-ENUM-001 | Mapped |
| `Enum/LocaleInPathMode.php` | production | FR-LOCALE-001 | Mapped |
| `Enum/MagicLoginMode.php` | production | FR-MAGIC-001 | Mapped |
| `Enum/PasswordResetDeliveryMode.php` | production | FR-ENUM-001 | Mapped |
| `Enum/PasswordResetMode.php` | production | FR-ENUM-001 | Mapped |
| `Enum/RegistrationMode.php` | production | FR-ENUM-001 | Mapped |
| `Enum/UnlocalizedLocaleMode.php` | production | FR-LOCALE-001 | Mapped |

## Controllers

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Controller/LoginController.php` | production | FR-CTRL-001 | Mapped |
| `Controller/LogoutController.php` | production | FR-CTRL-001 | Mapped |
| `Controller/MagicLoginCheckController.php` | production | FR-MAGIC-001 | Mapped |
| `Controller/MagicLoginRequestController.php` | production | FR-MAGIC-001 | Mapped |
| `Controller/RegisterController.php` | production | FR-CTRL-001 | Mapped |
| `Controller/ResetPasswordCodeController.php` | production | FR-CTRL-002 | Mapped |
| `Controller/ResetPasswordController.php` | production | FR-CTRL-002 | Mapped |
| `Controller/ResetPasswordRequestController.php` | production | FR-CTRL-002 | Mapped |
| `Controller/UnlocalizedLocaleRedirectController.php` | production | FR-LOCALE-001 | Mapped |

## Forms

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Form/LoginFormType.php` | production | FR-FORM-001 | Mapped |
| `Form/MagicLoginRequestFormType.php` | production | FR-MAGIC-001 | Mapped |
| `Form/PasswordFieldConstraintResolver.php` | production | FR-FORM-001 | Mapped |
| `Form/PasswordFieldTypeResolver.php` | production | FR-FORM-001 | Mapped |
| `Form/PasswordRepeatedFieldBuilder.php` | production | FR-FORM-001 | Mapped |
| `Form/RegistrationFormType.php` | production | FR-FORM-001 | Mapped |
| `Form/ResetPasswordCodeFormType.php` | production | FR-FORM-001 | Mapped |
| `Form/ResetPasswordFormType.php` | production | FR-FORM-001 | Mapped |
| `Form/ResetPasswordRequestFormType.php` | production | FR-FORM-001 | Mapped |

## Password reset

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
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

## Magic login

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `MagicLogin/LoggingMagicLoginNotifier.php` | production | FR-MAGIC-001 | Mapped |
| `MagicLogin/MagicLoginGate.php` | production | FR-MAGIC-001 | Mapped |
| `MagicLogin/MagicLoginNotificationContext.php` | production | FR-MAGIC-001 | Mapped |
| `MagicLogin/MagicLoginNotifierInterface.php` | production | FR-MAGIC-001 | Mapped |
| `MagicLogin/MagicLoginRequestHandler.php` | production | FR-MAGIC-001 | Mapped |
| `MagicLogin/MagicLoginRequestedEvent.php` | production | FR-MAGIC-001 | Mapped |
| `MagicLogin/MagicLoginUserResolver.php` | production | FR-MAGIC-001 | Mapped |
| `MagicLogin/NullMagicLoginNotifier.php` | production | FR-MAGIC-001 | Mapped |

## Profiles

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Profile/ProfileRegistry.php` | production | FR-PROFILE-001 | Mapped |
| `Profile/ProfileSettings.php` | production | FR-PROFILE-001 | Mapped |
| `Profile/RequestProfileResolver.php` | production | FR-PROFILE-001 | Mapped |
| `Profile/UnknownProfileException.php` | production | FR-PROFILE-001 | Mapped |

## Security & registration

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Security/AuthKitFormLoginParameters.php` | production | FR-SEC-001 | Mapped |
| `Security/RegistrationGate.php` | production | FR-SEC-001 | Mapped |
| `Security/UserRegistrar.php` | production | FR-SEC-001 | Mapped |

## Embed

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Embed/AuthEmbedContext.php` | production | FR-EMBED-001 | Mapped |
| `Embed/AuthEmbedContextFactory.php` | production | FR-EMBED-001 | Mapped |
| `Embed/AuthEmbedOptions.php` | production | FR-EMBED-002 | Mapped |

## Routing

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Routing/AuthKitRouteLoader.php` | production | FR-ROUT-001 | Mapped |
| `Routing/AuthKitRouteLocaleParameters.php` | production | FR-ROUT-001 | Mapped |
| `Routing/AuthKitUrlGenerator.php` | production | FR-ROUT-001 | Mapped |

## Twig extension & command

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Command/ConfigureSecurityCommand.php` | production | FR-CLI-001 | Mapped |
| `Twig/AuthEmbedExtension.php` | production | FR-TWIG-002 | Mapped |
| `Twig/AuthKitRoutingExtension.php` | production | FR-TWIG-002 | Mapped |

## Twig views

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Resources/views/embed/_authenticated.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/embed/_login_panel.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/embed/_register_panel.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/embed/dropdown.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/layout.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/security/login.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/security/magic_login_request.html.twig` | production | FR-MAGIC-001 | Mapped |
| `Resources/views/security/register.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/security/reset_password.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/security/reset_password_code.html.twig` | production | FR-TWIG-001 | Mapped |
| `Resources/views/security/reset_request.html.twig` | production | FR-TWIG-001 | Mapped |

## Translations

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Resources/translations/NowoAuthKitBundle.de.yaml` | production | FR-I18N-001 | Mapped |
| `Resources/translations/NowoAuthKitBundle.en.yaml` | production | FR-I18N-001 | Mapped |
| `Resources/translations/NowoAuthKitBundle.es.yaml` | production | FR-I18N-001 | Mapped |
| `Resources/translations/NowoAuthKitBundle.fr.yaml` | production | FR-I18N-001 | Mapped |
| `Resources/translations/NowoAuthKitBundle.it.yaml` | production | FR-I18N-001 | Mapped |
| `Resources/translations/NowoAuthKitBundle.nl.yaml` | production | FR-I18N-001 | Mapped |
| `Resources/translations/NowoAuthKitBundle.pt.yaml` | production | FR-I18N-001 | Mapped |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Symfony config | 2 | 2 |
| Bundle & DI | 6 | 6 |
| Enums | 7 | 7 |
| Controllers | 9 | 9 |
| Forms | 9 | 9 |
| Password reset | 12 | 12 |
| Magic login | 8 | 8 |
| Profiles | 4 | 4 |
| Security & registration | 3 | 3 |
| Embed | 3 | 3 |
| Routing | 3 | 3 |
| Twig extension & command | 3 | 3 |
| Twig views | 11 | 11 |
| Translations | 7 | 7 |
| **Total production sources** | **87** | **87** |
