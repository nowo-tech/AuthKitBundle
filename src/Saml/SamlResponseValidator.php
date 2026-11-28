<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Nowo\AuthKitBundle\Entity\SamlProvider;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Error;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Utils;
use OneLogin\Saml2\ValidationError;
use Psr\Clock\ClockInterface;
use Throwable;

use function array_filter;
use function array_values;
use function base64_decode;
use function in_array;
use function is_array;
use function is_string;
use function strlen;
use function strtolower;
use function trim;

/**
 * Validates the SAML response of an SP-initiated sign-in (2.10.0) and returns the data of its signed assertion.
 *
 * Two layers. onelogin/php-saml in strict mode ({@see SamlSettingsFactory}): XML schema, status, exactly one assertion
 * (encrypted or not) in the whole document, signature structure (one signature per signed element, referencing its
 * parent's ID, covering it, verified with the configured IdP certificates only), the assertion read from the signed
 * node, decryption, InResponseTo, Destination, Audience, Issuer, SubjectConfirmation and timestamps with its fixed drift.
 * Then AuthKit's own checks on the same document, exact and with the configured clock skew: no SHA-1 signature or
 * digest, a signature on the Response or the Assertion, InResponseTo equal to the stored request id on the Response and
 * on a bearer SubjectConfirmationData, Destination and Recipient equal to the ACS URL, an Audience equal to the SP
 * entity id, every Issuer equal to the IdP entity id, Conditions / SubjectConfirmationData / SessionNotOnOrAfter times.
 *
 * An assertion encrypted for the next SP key (rotation) is decrypted with it when the current key fails. Errors carry a
 * reason code only. Stateless (worker-safe); onelogin's static URL helpers are pinned per call ({@see SamlServerContext}).
 *
 * @internal
 */
final class SamlResponseValidator
{
    /** Longest base64 SAMLResponse accepted (before decoding). */
    public const MAX_RESPONSE_BYTES = 524288;

    private const SHA1_ALGORITHMS = [
        'http://www.w3.org/2000/09/xmldsig#rsa-sha1',
        'http://www.w3.org/2000/09/xmldsig#dsa-sha1',
        'http://www.w3.org/2000/09/xmldsig#hmac-sha1',
        'http://www.w3.org/2000/09/xmldsig#sha1',
    ];

    /** @var array<int, string> library error code => reason */
    private const REASONS_BY_CODE = [
        ValidationError::STATUS_CODE_IS_NOT_SUCCESS              => SamlFailure::STATUS,
        ValidationError::MISSING_STATUS                          => SamlFailure::STATUS,
        ValidationError::MISSING_STATUS_CODE                     => SamlFailure::STATUS,
        ValidationError::WRONG_SIGNED_ELEMENT                    => SamlFailure::SIGNATURE,
        ValidationError::ID_NOT_FOUND_IN_SIGNED_ELEMENT          => SamlFailure::SIGNATURE,
        ValidationError::DUPLICATED_ID_IN_SIGNED_ELEMENTS        => SamlFailure::SIGNATURE,
        ValidationError::INVALID_SIGNED_ELEMENT                  => SamlFailure::SIGNATURE,
        ValidationError::DUPLICATED_REFERENCE_IN_SIGNED_ELEMENTS => SamlFailure::SIGNATURE,
        ValidationError::UNEXPECTED_SIGNED_ELEMENTS              => SamlFailure::SIGNATURE,
        ValidationError::WRONG_NUMBER_OF_SIGNATURES_IN_RESPONSE  => SamlFailure::SIGNATURE,
        ValidationError::WRONG_NUMBER_OF_SIGNATURES_IN_ASSERTION => SamlFailure::SIGNATURE,
        ValidationError::WRONG_NUMBER_OF_SIGNATURES              => SamlFailure::SIGNATURE,
        ValidationError::NO_SIGNED_MESSAGE                       => SamlFailure::SIGNATURE,
        ValidationError::NO_SIGNED_ASSERTION                     => SamlFailure::SIGNATURE,
        ValidationError::NO_SIGNATURE_FOUND                      => SamlFailure::SIGNATURE,
        ValidationError::INVALID_SIGNATURE                       => SamlFailure::SIGNATURE,
        ValidationError::UNEXPECTED_REFERENCE                    => SamlFailure::SIGNATURE,
        ValidationError::WRONG_NUMBER_OF_ASSERTIONS              => SamlFailure::SIGNATURE,
        ValidationError::WRONG_INRESPONSETO                      => SamlFailure::IN_RESPONSE_TO,
        ValidationError::NO_ENCRYPTED_ASSERTION                  => SamlFailure::ENCRYPTION_REQUIRED,
        ValidationError::DECRYPTION_FAILED                       => SamlFailure::DECRYPTION,
        ValidationError::KEY_ALGORITHM_ERROR                     => SamlFailure::DECRYPTION,
        ValidationError::ENCRYPTION_ALGORITHM_NOT_ALLOWED        => SamlFailure::DECRYPTION,
        ValidationError::MISSING_ENCRYPTED_ELEMENT               => SamlFailure::DECRYPTION,
        ValidationError::KEYINFO_NOT_FOUND_IN_ENCRYPTED_DATA     => SamlFailure::DECRYPTION,
        ValidationError::CHILDREN_NODE_NOT_FOUND_IN_KEYINFO      => SamlFailure::DECRYPTION,
        ValidationError::UNSUPPORTED_RETRIEVAL_METHOD            => SamlFailure::DECRYPTION,
        ValidationError::ASSERTION_TOO_EARLY                     => SamlFailure::TIMING,
        ValidationError::ASSERTION_EXPIRED                       => SamlFailure::TIMING,
        ValidationError::SESSION_EXPIRED                         => SamlFailure::TIMING,
        ValidationError::RESPONSE_EXPIRED                        => SamlFailure::TIMING,
        ValidationError::WRONG_DESTINATION                       => SamlFailure::DESTINATION,
        ValidationError::EMPTY_DESTINATION                       => SamlFailure::DESTINATION,
        ValidationError::MISSING_DESTINATION                     => SamlFailure::DESTINATION,
        ValidationError::WRONG_AUDIENCE                          => SamlFailure::AUDIENCE,
        ValidationError::WRONG_ISSUER                            => SamlFailure::ISSUER,
        ValidationError::ISSUER_MULTIPLE_IN_RESPONSE             => SamlFailure::ISSUER,
        ValidationError::ISSUER_NOT_FOUND_IN_ASSERTION           => SamlFailure::ISSUER,
        ValidationError::WRONG_SUBJECTCONFIRMATION               => SamlFailure::SUBJECT_CONFIRMATION,
        ValidationError::NO_NAMEID                               => SamlFailure::UNUSABLE_SUBJECT,
        ValidationError::EMPTY_NAMEID                            => SamlFailure::UNUSABLE_SUBJECT,
        ValidationError::SP_NAME_QUALIFIER_NAME_MISMATCH         => SamlFailure::UNUSABLE_SUBJECT,
    ];

    public function __construct(
        private readonly SamlSettingsFactory $settingsFactory,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws SamlFailure
     */
    public function validate(SamlProvider $provider, string $samlResponse, string $requestId, string $acsUrl, string $spEntityId, int $clockSkew): SamlAssertion
    {
        if ($samlResponse === '' || strlen($samlResponse) > self::MAX_RESPONSE_BYTES || base64_decode($samlResponse, true) === false) {
            throw new SamlFailure(SamlFailure::MALFORMED, 'The SAMLResponse is empty, too large or not base64.');
        }

        $slots    = $provider->getSpNextPrivateKey() !== null ? [SamlSettingsFactory::SLOT_CURRENT, SamlSettingsFactory::SLOT_NEXT] : [SamlSettingsFactory::SLOT_CURRENT];
        $response = null;
        $failure  = null;
        foreach ($slots as $slot) {
            [$response, $failure] = SamlServerContext::run($acsUrl, fn (): array => $this->libraryCheck($provider, $samlResponse, $requestId, $acsUrl, $spEntityId, $slot));
            if (!$failure instanceof SamlFailure && $response instanceof Response) {
                break;
            }
            // Only a decryption failure tries the next key; anything else is final.
            if ($failure instanceof SamlFailure && $failure->reason !== SamlFailure::DECRYPTION) {
                throw $failure;
            }
        }
        if ($failure instanceof SamlFailure || !$response instanceof Response) {
            throw $failure ?? new SamlFailure(SamlFailure::MALFORMED);
        }

        return $this->assertion($response, $provider, $requestId, $acsUrl, $spEntityId, $clockSkew);
    }

    /**
     * @return array{0: Response|null, 1: SamlFailure|null}
     */
    private function libraryCheck(SamlProvider $provider, string $samlResponse, string $requestId, string $acsUrl, string $spEntityId, string $slot): array
    {
        try {
            $response = new Response($this->settingsFactory->create($provider, $spEntityId, $acsUrl, $slot), $samlResponse);
            if ($response->isValid($requestId)) {
                return [$response, null];
            }
            $error = $response->getErrorException();
        } catch (Throwable $exception) {
            $error = $exception;
        }

        return [null, new SamlFailure(self::reasonOf($error), 'The SAML response was refused by the protocol checks.')];
    }

    /**
     * AuthKit's own checks on the validated document, then the data of the signed assertion.
     *
     * @throws SamlFailure
     */
    private function assertion(Response $response, SamlProvider $provider, string $requestId, string $acsUrl, string $spEntityId, int $clockSkew): SamlAssertion
    {
        // The decrypted copy when the assertion was encrypted, else the received document (both checked by the library).
        $document = $response->getXMLDocument();
        $original = $response->document;
        $root     = $original->documentElement;
        if (!$root instanceof DOMElement) {
            // Defence in depth: the library refused such a document.
            throw new SamlFailure(SamlFailure::MALFORMED); // @codeCoverageIgnore
        }
        $xpath = self::xpath($document);

        foreach ([$original, $document] as $checked) {
            foreach (self::nodes(self::xpath($checked), '//ds:SignatureMethod/@Algorithm | //ds:DigestMethod/@Algorithm') as $algorithm) {
                if (in_array(strtolower(trim($algorithm->textContent)), self::SHA1_ALGORITHMS, true)) {
                    throw new SamlFailure(SamlFailure::SIGNATURE, 'SHA-1 signatures and digests are refused.');
                }
            }
        }
        // Re-checks of what the library enforced already (defence in depth: a library change must not open them).
        $originalPath = self::xpath($original);
        if ((int) $originalPath->evaluate('count(//saml:Assertion) + count(//saml:EncryptedAssertion)') !== 1 || (int) $xpath->evaluate('count(//saml:Assertion)') !== 1) {
            // Defence in depth: also refused by the library.
            throw new SamlFailure(SamlFailure::SIGNATURE, 'The response must hold exactly one assertion.'); // @codeCoverageIgnore
        }
        $assertion = self::nodes($xpath, '/samlp:Response/saml:Assertion')[0] ?? null;
        if (!$assertion instanceof DOMElement) {
            // Defence in depth: also refused by the library.
            throw new SamlFailure(SamlFailure::SIGNATURE, 'The assertion is not a child of the response.'); // @codeCoverageIgnore
        }
        $responseSigned  = (int) $originalPath->evaluate('count(/samlp:Response/ds:Signature)') === 1;
        $assertionSigned = (int) $xpath->evaluate('count(/samlp:Response/saml:Assertion/ds:Signature)') === 1;
        if (!$responseSigned && !$assertionSigned) {
            // Defence in depth: also refused by the library.
            throw new SamlFailure(SamlFailure::SIGNATURE, 'Neither the response nor the assertion is signed.'); // @codeCoverageIgnore
        }

        if ($root->getAttribute('InResponseTo') !== $requestId) {
            // Defence in depth: also refused by the library.
            throw new SamlFailure(SamlFailure::IN_RESPONSE_TO, 'The response does not answer the pending request.'); // @codeCoverageIgnore
        }
        if ($root->getAttribute('Destination') !== $acsUrl) {
            throw new SamlFailure(SamlFailure::DESTINATION, 'The response Destination is not this ACS.');
        }
        $idpEntityId = $provider->getIdpEntityId();
        $assertionIssuer = null;
        foreach (self::nodes($xpath, '/samlp:Response/saml:Issuer | /samlp:Response/saml:Assertion/saml:Issuer') as $issuer) {
            if (trim($issuer->textContent) !== $idpEntityId) {
                // Defence in depth: also refused by the library.
                throw new SamlFailure(SamlFailure::ISSUER, 'An issuer is not the configured identity provider.'); // @codeCoverageIgnore
            }
            if ($issuer->parentNode === $assertion) {
                $assertionIssuer = $idpEntityId;
            }
        }
        if ($assertionIssuer === null) {
            // Defence in depth: also refused by the library.
            throw new SamlFailure(SamlFailure::ISSUER, 'The assertion has no issuer.'); // @codeCoverageIgnore
        }

        $now = $this->clock->now()->getTimestamp();
        $this->checkSubjectConfirmation($xpath, $assertion, $requestId, $acsUrl, $now, $clockSkew);
        $this->checkConditions($xpath, $assertion, $spEntityId, $now, $clockSkew);
        foreach (self::nodes($xpath, 'saml:AuthnStatement/@SessionNotOnOrAfter', $assertion) as $sessionEnd) {
            if (self::time($sessionEnd->textContent) + $clockSkew <= $now) {
                throw new SamlFailure(SamlFailure::TIMING, 'The IdP session of the assertion has ended.');
            }
        }

        try {
            $nameId     = $response->getNameId();
            $format     = $response->getNameIdFormat();
            $session    = $response->getSessionIndex();
            $attributes = $response->getAttributes();
            $friendly   = $response->getAttributesWithFriendlyName();
        } catch (Throwable) {
            throw new SamlFailure(SamlFailure::MALFORMED, 'The assertion subject or attributes cannot be read.');
        }
        $contexts = [];
        foreach (self::nodes($xpath, 'saml:AuthnStatement/saml:AuthnContext/saml:AuthnContextClassRef', $assertion) as $context) {
            $value = trim($context->textContent);
            if ($value !== '' && !in_array($value, $contexts, true)) {
                $contexts[] = $value;
            }
        }
        $assertionId = $assertion->getAttribute('ID');

        return new SamlAssertion(
            $idpEntityId,
            is_string($nameId) && $nameId !== '' ? $nameId : null,
            is_string($format) && $format !== '' ? $format : null,
            is_string($session) && $session !== '' ? $session : null,
            self::stringLists($attributes),
            self::stringLists($friendly),
            $contexts,
            $assertionId !== '' ? $assertionId : null,
        );
    }

    /**
     * @throws SamlFailure
     */
    private function checkSubjectConfirmation(DOMXPath $xpath, DOMElement $assertion, string $requestId, string $acsUrl, int $now, int $clockSkew): void
    {
        foreach (self::nodes($xpath, 'saml:Subject/saml:SubjectConfirmation[@Method="' . Constants::CM_BEARER . '"]/saml:SubjectConfirmationData', $assertion) as $data) {
            if (!$data instanceof DOMElement || $data->getAttribute('InResponseTo') !== $requestId || $data->getAttribute('Recipient') !== $acsUrl || !$data->hasAttribute('NotOnOrAfter')) {
                continue;
            }
            if (self::time($data->getAttribute('NotOnOrAfter')) + $clockSkew <= $now) {
                continue;
            }
            if ($data->hasAttribute('NotBefore') && self::time($data->getAttribute('NotBefore')) > $now + $clockSkew) {
                continue;
            }

            return;
        }

        throw new SamlFailure(SamlFailure::SUBJECT_CONFIRMATION, 'No bearer subject confirmation answers this request at this ACS in time.');
    }

    /**
     * @throws SamlFailure
     */
    private function checkConditions(DOMXPath $xpath, DOMElement $assertion, string $spEntityId, int $now, int $clockSkew): void
    {
        $conditions = self::nodes($xpath, 'saml:Conditions', $assertion)[0] ?? null;
        if (!$conditions instanceof DOMElement) {
            // Defence in depth: also refused by the library.
            throw new SamlFailure(SamlFailure::TIMING, 'The assertion has no conditions.'); // @codeCoverageIgnore
        }
        if ($conditions->hasAttribute('NotBefore') && self::time($conditions->getAttribute('NotBefore')) > $now + $clockSkew) {
            throw new SamlFailure(SamlFailure::TIMING, 'The assertion is not valid yet.');
        }
        if ($conditions->hasAttribute('NotOnOrAfter') && self::time($conditions->getAttribute('NotOnOrAfter')) + $clockSkew <= $now) {
            throw new SamlFailure(SamlFailure::TIMING, 'The assertion has expired.');
        }
        $restrictions = self::nodes($xpath, 'saml:AudienceRestriction', $conditions);
        if ($restrictions === []) {
            throw new SamlFailure(SamlFailure::AUDIENCE, 'The assertion names no audience.');
        }
        // Every AudienceRestriction must name this SP (SAML core 2.5.1.4: the conditions are ANDed).
        foreach ($restrictions as $restriction) {
            $named = false;
            foreach (self::nodes($xpath, 'saml:Audience', $restriction) as $audience) {
                $named = $named || trim($audience->textContent) === $spEntityId;
            }
            if (!$named) {
                throw new SamlFailure(SamlFailure::AUDIENCE, 'The assertion is meant for another service provider.');
            }
        }
    }

    /**
     * A SAML time (xs:dateTime in UTC); an unreadable one is refused.
     *
     * @throws SamlFailure
     */
    private static function time(string $value): int
    {
        try {
            return (int) Utils::parseSAML2Time(trim($value));
        } catch (Throwable) {
            // Defence in depth: the library parsed every time first.
            throw new SamlFailure(SamlFailure::MALFORMED, 'A time of the assertion cannot be read.'); // @codeCoverageIgnore
        }
    }

    /**
     * The DOM nodes an expression selects (attributes and elements).
     *
     * @return list<DOMNode>
     */
    private static function nodes(DOMXPath $xpath, string $expression, ?DOMNode $context = null): array
    {
        $nodes = [];
        foreach ($xpath->query($expression, $context) ?: [] as $node) {
            if ($node instanceof DOMNode) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private static function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('samlp', Constants::NS_SAMLP);
        $xpath->registerNamespace('saml', Constants::NS_SAML);
        $xpath->registerNamespace('ds', Constants::NS_DS);

        return $xpath;
    }

    /**
     * @param array<mixed> $attributes
     *
     * @return array<string, list<string>>
     */
    private static function stringLists(array $attributes): array
    {
        $lists = [];
        foreach ($attributes as $name => $values) {
            // A numeric attribute name became an integer key.
            $lists[(string) $name] = array_values(array_filter(is_array($values) ? $values : [], is_string(...)));
        }

        return $lists;
    }

    /**
     * The reason code of a library refusal (its message, which quotes values, is dropped).
     */
    private static function reasonOf(?Throwable $error): string
    {
        if ($error instanceof Error) {
            // The library refused the row's settings (a misconfigured provider).
            return SamlFailure::UNAVAILABLE;
        }
        if (!$error instanceof ValidationError) {
            // Signature-structure and key errors of the library are plain exceptions.
            return SamlFailure::SIGNATURE;
        }

        return self::REASONS_BY_CODE[$error->getCode()] ?? SamlFailure::MALFORMED;
    }
}
