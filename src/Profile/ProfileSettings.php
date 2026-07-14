<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Profile;

final readonly class ProfileSettings
{
    /**
     * @param class-string $userClass
     * @param list<array<string, mixed>> $loginFields
     * @param array<string, mixed> $rememberMe
     * @param array<string, mixed> $passwordStrength
     * @param list<array<string, mixed>> $registrationFields
     * @param array<string, string> $templates
     * @param array<string, mixed> $embed
     * @param array<string, mixed> $passwordReset
     * @param array<string, array{path: string, name: string}> $routes
     */
    public function __construct(
        public string $name,
        public string $userClass,
        public string $userIdentifierField,
        public string $registrationRole,
        public string $registrationMode,
        public array $loginFields,
        public array $rememberMe,
        public array $passwordStrength,
        public array $registrationFields,
        public array $templates,
        public array $embed,
        public array $passwordReset,
        public array $routes,
        public string $firewall,
        public ?string $loginSuccessRoute,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        /** @var class-string $userClass */
        $userClass = $config['user_class'];

        return new self(
            name: $name,
            userClass: $userClass,
            userIdentifierField: $config['user_identifier_field'],
            registrationRole: $config['registration_role'],
            registrationMode: $config['registration_mode'],
            loginFields: $config['login_fields'],
            rememberMe: $config['remember_me'],
            passwordStrength: $config['password_strength'],
            registrationFields: $config['registration_fields'],
            templates: $config['templates'],
            embed: $config['embed'],
            passwordReset: $config['password_reset'],
            routes: $config['routes'],
            firewall: $config['firewall'],
            loginSuccessRoute: $config['login_success_route'],
        );
    }
}
