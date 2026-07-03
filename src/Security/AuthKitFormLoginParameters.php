<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

/**
 * Maps AuthKit LoginFormType field names to Symfony Security form_login parameters.
 *
 * LoginFormType uses block prefix {@see BLOCK_PREFIX}, so POST keys are nested
 * (e.g. login_form[_username]), not bare _username.
 */
final class AuthKitFormLoginParameters
{
    public const BLOCK_PREFIX = 'login_form';

    public const CSRF_TOKEN_ID = 'authenticate';

    public static function fieldParameter(string $fieldName): string
    {
        return self::BLOCK_PREFIX . '[' . $fieldName . ']';
    }

    public static function usernameParameter(): string
    {
        return self::fieldParameter('_username');
    }

    public static function passwordParameter(): string
    {
        return self::fieldParameter('_password');
    }

    public static function csrfParameter(): string
    {
        return self::fieldParameter('_csrf_token');
    }

    public static function rememberMeParameter(): string
    {
        return self::fieldParameter('_remember_me');
    }

    /**
     * @return array<string, mixed>
     */
    public static function formLoginOptions(): array
    {
        return [
            'enable_csrf'        => true,
            'username_parameter' => self::usernameParameter(),
            'password_parameter' => self::passwordParameter(),
            'csrf_parameter'     => self::csrfParameter(),
            'csrf_token_id'      => self::CSRF_TOKEN_ID,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rememberMeOptions(int $lifetime, string $path = '/'): array
    {
        return [
            'secret'                  => '%kernel.secret%',
            'lifetime'                => $lifetime,
            'path'                    => $path,
            'remember_me_parameter'   => self::rememberMeParameter(),
        ];
    }
}
