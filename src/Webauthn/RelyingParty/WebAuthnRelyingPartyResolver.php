<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\RelyingParty;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\ValueObject\WebAuthnRelyingPartyContext;
use Nowo\AuthKitBundle\Webauthn\WebauthnFailure;
use Nowo\AuthKitBundle\Webauthn\WebAuthnRelyingPartyProviderInterface;
use Nowo\AuthKitBundle\Webauthn\WebAuthnRelyingPartyValidator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function array_map;
use function array_values;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function strtolower;

/**
 * Asks {@see WebAuthnRelyingPartyProviderInterface} for the relying party of one ceremony (2.6.0) and validates the
 * answer ({@see WebAuthnRelyingPartyValidator}): per call, nothing kept (FrankenPHP worker, kernel reset off). An
 * invalid answer or an exception of the provider is logged (error) and throws {@see WebauthnFailure::RELYING_PARTY}:
 * the ceremony fails closed.
 *
 * `http://localhost` origins are allowed with the profile's `webauthn.allow_http_localhost`, else with `kernel.debug`.
 *
 * @internal
 */
final readonly class WebAuthnRelyingPartyResolver
{
    public function __construct(
        private WebAuthnRelyingPartyProviderInterface $provider,
        private bool $debug = false,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @throws WebauthnFailure `relying_party` when the provider fails or answers an invalid relying party
     */
    public function resolve(ProfileSettings $profile, Request $request): WebAuthnRelyingParty
    {
        $context = self::context($profile, $request);
        try {
            $rpId    = strtolower($this->provider->rpId($context));
            $origins = array_map(strtolower(...), $this->provider->allowedOrigins($context));
        } catch (Throwable $exception) {
            $this->logger->error('Passkeys are disabled: the WebAuthn relying party provider failed.', ['profile' => $profile->name, 'exception' => $exception]);

            throw new WebauthnFailure(WebauthnFailure::RELYING_PARTY, 'The WebAuthn relying party provider failed.', $exception);
        }
        $violations = WebAuthnRelyingPartyValidator::violations($rpId, $origins, $this->allowHttpLocalhost($profile));
        if ($violations !== []) {
            $this->logger->error('Passkeys are disabled: the WebAuthn relying party is invalid.', ['profile' => $profile->name, 'rp_id' => $rpId, 'origins' => $origins, 'violations' => $violations]);

            throw new WebauthnFailure(WebauthnFailure::RELYING_PARTY, 'Invalid WebAuthn relying party: ' . $violations[0]);
        }

        return new WebAuthnRelyingParty($rpId, $origins);
    }

    /**
     * The provider's context of a ceremony of the profile on the request.
     */
    public static function context(ProfileSettings $profile, Request $request): WebAuthnRelyingPartyContext
    {
        $rpId    = $profile->webauthn['rp_id'] ?? null;
        $origins = $profile->webauthn['allowed_origins'] ?? [];

        return new WebAuthnRelyingPartyContext(
            $profile->name,
            $request->getHost(),
            $request->getSchemeAndHttpHost(),
            is_string($rpId) && $rpId !== '' ? $rpId : null,
            is_array($origins) ? array_values(array_map(static fn (mixed $origin): string => is_scalar($origin) ? (string) $origin : '', $origins)) : [],
        );
    }

    private function allowHttpLocalhost(ProfileSettings $profile): bool
    {
        $flag = $profile->webauthn['allow_http_localhost'] ?? null;

        return is_bool($flag) ? $flag : $this->debug;
    }
}
