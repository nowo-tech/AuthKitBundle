# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/auth-kit-bundle`  
**Last audited**: 2026-07-07

## Symfony config

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/services.yaml` | Service wiring | FR-DI-001 |
| `Resources/config/routing.yaml` | Route imports | FR-ROUT-001 |

## Bundle & DI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `NowoAuthKitBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/NowoAuthKitExtension.php` | DI extension | FR-CFG-002 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Twig namespace | FR-TWIG-001 |
| `Config/FieldConfigNormalizer.php` | Field config normalizer | FR-CFG-002 |
| `Config/RememberMeConfigResolver.php` | Remember-me resolver | FR-CFG-002 |

## Enums

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Enum/RegistrationMode.php` | Registration gate modes | FR-ENUM-001 |
| `Enum/AuthEmbedMode.php` | Embed layout modes | FR-EMBED-001 |
| `Enum/PasswordResetMode.php` | Reset flow mode | FR-RESET-001 |
| `Enum/PasswordResetDeliveryMode.php` | Email/code delivery | FR-RESET-001 |

## Controllers

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/LoginController.php` | Login page | FR-CTRL-001 |
| `Controller/LogoutController.php` | Logout route | FR-CTRL-001 |
| `Controller/RegisterController.php` | Registration | FR-CTRL-001 |
| `Controller/ResetPasswordRequestController.php` | Reset request | FR-CTRL-002 |
| `Controller/ResetPasswordCodeController.php` | Code verification | FR-CTRL-002 |
| `Controller/ResetPasswordController.php` | New password form | FR-CTRL-002 |

## Forms

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Form/LoginFormType.php` | Login form | FR-FORM-001 |
| `Form/RegistrationFormType.php` | Register form | FR-FORM-001 |
| `Form/ResetPasswordRequestFormType.php` | Request form | FR-FORM-001 |
| `Form/ResetPasswordCodeFormType.php` | Code form | FR-FORM-001 |
| `Form/ResetPasswordFormType.php` | Password form | FR-FORM-001 |
| `Form/PasswordFieldConstraintResolver.php` | Password constraints | FR-FORM-002 |
| `Form/PasswordFieldTypeResolver.php` | Password field type | FR-FORM-002 |
| `Form/PasswordRepeatedFieldBuilder.php` | Repeated password | FR-FORM-002 |

## Password reset

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `PasswordReset/PasswordResetTokenManagerInterface.php` | Token contract | FR-RESET-001 |
| `PasswordReset/PasswordResetTokenManager.php` | Token storage/TTL | FR-RESET-001 |
| `PasswordReset/PasswordResetTokenResult.php` | Token DTO | FR-RESET-001 |
| `PasswordReset/PasswordResetUserResolver.php` | User lookup | FR-RESET-001 |
| `PasswordReset/PasswordResetRequestHandler.php` | Request orchestration | FR-RESET-001 |
| `PasswordReset/PasswordResetGate.php` | Feature gate | FR-RESET-001 |
| `PasswordReset/PasswordResetCompleter.php` | Password change | FR-RESET-001 |
| `PasswordReset/PasswordResetNotifierInterface.php` | Notify contract | FR-RESET-002 |
| `PasswordReset/LoggingPasswordResetNotifier.php` | Log notifier | FR-RESET-002 |
| `PasswordReset/NullPasswordResetNotifier.php` | No-op notifier | FR-RESET-002 |
| `PasswordReset/PasswordResetNotificationContext.php` | Notify payload | FR-RESET-002 |
| `PasswordReset/PasswordResetRequestedEvent.php` | Domain event | FR-RESET-002 |

## Security & registration

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Security/RegistrationGate.php` | Registration access | FR-SEC-001 |
| `Security/UserRegistrar.php` | User persistence | FR-SEC-001 |
| `Security/AuthKitFormLoginParameters.php` | form_login params | FR-SEC-001 |

## Embed

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Embed/AuthEmbedContext.php` | Embed view model | FR-EMBED-001 |
| `Embed/AuthEmbedContextFactory.php` | Context factory | FR-EMBED-001 |

## Routing

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Routing/AuthKitRouteLoader.php` | Dynamic routes | FR-ROUT-001 |
| `Routing/AuthKitRouteLocaleParameters.php` | Locale params | FR-ROUT-001 |
| `Routing/AuthKitUrlGenerator.php` | URL generation | FR-ROUT-001 |

## Twig

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Twig/AuthKitRoutingExtension.php` | Route helpers | FR-TWIG-001 |
| `Twig/AuthEmbedExtension.php` | Embed function | FR-TWIG-001 |

## CLI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/ConfigureSecurityCommand.php` | Security YAML scaffold | FR-CLI-001 |

## Translations

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/translations/NowoAuthKitBundle.en.yaml` | English UI | FR-I18N-001 |
| `Resources/translations/NowoAuthKitBundle.es.yaml` | Spanish UI | FR-I18N-001 |
| `Resources/translations/NowoAuthKitBundle.de.yaml` | German UI | FR-I18N-001 |
| `Resources/translations/NowoAuthKitBundle.fr.yaml` | French UI | FR-I18N-001 |
| `Resources/translations/NowoAuthKitBundle.it.yaml` | Italian UI | FR-I18N-001 |
| `Resources/translations/NowoAuthKitBundle.nl.yaml` | Dutch UI | FR-I18N-001 |
| `Resources/translations/NowoAuthKitBundle.pt.yaml` | Portuguese UI | FR-I18N-001 |

## Twig views

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/layout.html.twig` | Base layout | FR-TWIG-002 |
| `Resources/views/security/login.html.twig` | Login page | FR-TWIG-002 |
| `Resources/views/security/register.html.twig` | Register page | FR-TWIG-002 |
| `Resources/views/security/reset_request.html.twig` | Reset request | FR-TWIG-002 |
| `Resources/views/security/reset_password_code.html.twig` | Code entry | FR-TWIG-002 |
| `Resources/views/security/reset_password.html.twig` | New password | FR-TWIG-002 |
| `Resources/views/embed/dropdown.html.twig` | Embed dropdown | FR-TWIG-002 |
| `Resources/views/embed/_login_panel.html.twig` | Login partial | FR-TWIG-002 |
| `Resources/views/embed/_register_panel.html.twig` | Register partial | FR-TWIG-002 |
| `Resources/views/embed/_authenticated.html.twig` | Logged-in partial | FR-TWIG-002 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Symfony config | 2 | 2 |
| Bundle & DI | 6 | 6 |
| Enums | 4 | 4 |
| Controllers | 6 | 6 |
| Forms | 8 | 8 |
| Password reset | 12 | 12 |
| Security | 3 | 3 |
| Embed | 2 | 2 |
| Routing | 3 | 3 |
| Twig PHP | 2 | 2 |
| CLI | 1 | 1 |
| Translations | 7 | 7 |
| Twig views | 10 | 10 |
| **Total production sources** | **66** | **66** |
