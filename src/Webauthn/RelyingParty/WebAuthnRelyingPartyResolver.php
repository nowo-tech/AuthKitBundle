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
use function count;
use function hash;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function json_encode;
use function strtolower;
use function substr;

/**
 * Asks {@see WebAuthnRelyingPartyProviderInterface} for the relying party of one ceremony (2.6.0) and validates the
 * answer ({@see WebAuthnRelyingPartyValidator}): per call, nothing kept (FrankenPHP worker, kernel reset off). The
 * origins are asked with the resolved RP id in the context. An invalid RP id, no usable origin or an exception of the
 * provider is logged (error) and throws {@see WebauthnFailure::RELYING_PARTY}: the ceremony fails closed. An origin
 * that cannot serve the RP id is dropped with a warning (audit ZCQ-M2).
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
     * @throws WebauthnFailure `relying_party` when the provider fails, answers an invalid RP id or no usable origin
     */
    public function resolve(ProfileSettings $profile, Request $request): WebAuthnRelyingParty
    {
        $context = self::context($profile, $request);
        try {
            $rpId = strtolower($this->provider->rpId($context));
            // Audit ZCQ-M1: the origins follow the RP id just resolved (a decorator's), never the request Host.
            $origins = array_map(strtolower(...), $this->provider->allowedOrigins($context->withRpId($rpId)));
        } catch (Throwable $exception) {
            // Audit ZCQ-L2: the class, the code and a digest of the message only (a host exception may carry a DSN or
            // a token; the interface asks providers not to put secrets in messages).
            $this->logger->error('Passkeys are disabled: the WebAuthn relying party provider failed.', [
                'profile'         => $profile->name,
                'exception_class' => $exception::class,
                'exception_code'  => $exception->getCode(),
                'message_sha256'  => substr(hash('sha256', $exception->getMessage()), 0, 16),
            ]);

            throw new WebauthnFailure(WebauthnFailure::RELYING_PARTY, 'The WebAuthn relying party provider failed.', $exception);
        }
        if (!WebAuthnRelyingPartyValidator::isValidRpId($rpId) || count($origins) > WebAuthnRelyingPartyValidator::MAX_ORIGINS) {
            $this->refuse($profile, $rpId, WebAuthnRelyingPartyValidator::violations($rpId, $origins, $this->allowHttpLocalhost($profile)));
        }
        // Audit ZCQ-M2 (BC with 2.5): an origin that cannot serve this RP id (another domain, a malformed entry) is
        // dropped with a warning; it could never verify. Passkeys stay on while one usable origin remains.
        $usable = [];
        foreach ($origins as $origin) {
            $violation = WebAuthnRelyingPartyValidator::originViolation($origin, $rpId, $this->allowHttpLocalhost($profile));
            if ($violation === null) {
                $usable[] = $origin;
                continue;
            }
            $this->logger->warning('A WebAuthn allowed origin is ignored for this RP id.', ['profile' => $profile->name, 'rp_id' => $rpId, 'violation' => $violation]);
        }
        if ($usable === []) {
            $this->refuse($profile, $rpId, $origins === [] ? ['No allowed origin.'] : ['No allowed origin can serve the RP id ' . json_encode($rpId) . '.']);
        }

        return new WebAuthnRelyingParty($rpId, $usable);
    }

    /**
     * @param list<string> $violations
     *
     * @throws WebauthnFailure `relying_party`, always
     */
    private function refuse(ProfileSettings $profile, string $rpId, array $violations): never
    {
        $this->logger->error('Passkeys are disabled: the WebAuthn relying party is invalid.', ['profile' => $profile->name, 'rp_id' => json_encode($rpId), 'violations' => $violations]);

        throw new WebauthnFailure(WebauthnFailure::RELYING_PARTY, 'Invalid WebAuthn relying party: ' . $violations[0]);
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
