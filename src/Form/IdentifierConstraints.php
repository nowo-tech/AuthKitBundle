<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Form;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Constraints of the identifier typed on the anonymous request forms (magic login, password reset request / code).
 *
 * With an e-mail identifier the input must be a plain ASCII address (HTML5 rule): look-alike Unicode addresses
 * (`victim@exámple.com`) are refused before any lookup (SEC-004 H1, defence in depth with UserIdentifierMatcher).
 */
final class IdentifierConstraints
{
    public const EMAIL_MESSAGE = 'validation.identifier_email';

    /**
     * @return list<Constraint>
     */
    public static function for(ProfileSettings $profile, string $requiredMessage): array
    {
        $constraints = [new NotBlank(message: $requiredMessage)];

        if ($profile->userIdentifierField === 'email') {
            $constraints[] = new Email(message: self::EMAIL_MESSAGE, mode: Email::VALIDATION_MODE_HTML5);
        }

        return $constraints;
    }
}
