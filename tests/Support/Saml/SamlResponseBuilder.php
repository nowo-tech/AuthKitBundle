<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Saml;

use DOMDocument;
use DOMElement;
use DOMNode;
use OneLogin\Saml2\Constants;
use RobRichards\XMLSecLibs\XMLSecEnc;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;

use function base64_encode;
use function bin2hex;
use function gmdate;
use function htmlspecialchars;
use function random_bytes;
use function time;

/**
 * Builds IdP responses for the SAML tests (2.10.0) at test time, signed with a throwaway IdP key ({@see SamlKeys}):
 * signed Response, signed Assertion, both or none, encrypted for an SP certificate, and the broken variants (wrong
 * Destination / Recipient / Audience / Issuer / InResponseTo, expired, not yet valid, SHA-1, wrapped, tampered).
 */
final class SamlResponseBuilder
{
    public const IDP_ENTITY_ID = 'https://idp.example.com/saml';

    public string $idpEntityId = self::IDP_ENTITY_ID;

    public ?string $nameId = 'subject-1';

    public ?string $nameIdFormat = Constants::NAMEID_PERSISTENT;

    /** @var array<string, list<string>> */
    public array $attributes = [
        'mail'      => ['ada@example.com'],
        'givenName' => ['Ada'],
        'sn'        => ['Lovelace'],
        'groups'    => ['staff', 'admins'],
    ];

    /** Seconds from now. */
    public int $notBefore = -60;

    public int $notOnOrAfter = 300;

    public ?int $sessionNotOnOrAfter = null;

    /** none | response | assertion | both */
    public string $sign = 'assertion';

    public ?string $encryptForCertificate = null;

    public ?string $destination = null;

    public bool $omitDestination = false;

    public ?string $recipient = null;

    public ?string $audience = null;

    public bool $omitAudience = false;

    public ?string $responseIssuer = null;

    public ?string $assertionIssuer = null;

    public ?string $responseInResponseTo = null;

    public ?string $subjectInResponseTo = null;

    public string $status = 'urn:oasis:names:tc:SAML:2.0:status:Success';

    /** SHA-1 digest (with an RSA-SHA256 signature the library accepts). */
    public bool $sha1Digest = false;

    /** A second, unsigned assertion next to the signed one (signature wrapping). */
    public bool $wrap = false;

    /** The NameID is changed after signing. */
    public bool $tamper = false;

    /** The (signed-response) assertion is moved into samlp:Extensions: not a child of the Response. */
    public bool $nestInExtensions = false;

    /** Seconds from now of the SubjectConfirmationData NotBefore; null: none. */
    public ?int $subjectNotBefore = null;

    public bool $omitSubjectNotOnOrAfter = false;

    /** Seconds from now of the Conditions NotOnOrAfter; null: $notOnOrAfter. */
    public ?int $conditionsNotOnOrAfter = null;

    /** A second AudienceRestriction naming another SP only. */
    public bool $foreignAudienceRestriction = false;

    /** The first attribute appears twice. */
    public bool $duplicateAttribute = false;

    public string $authnContext = 'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport';

    /** @var array{key: string, cert: string} */
    public array $idpPair;

    public function __construct(
        public string $requestId,
        public string $acsUrl,
        public string $spEntityId,
    ) {
        $this->idpPair = SamlKeys::pair('idp');
    }

    public function build(): string
    {
        $now       = time();
        $assertion = $this->assertionXml($now);
        if ($this->sign === 'assertion' || $this->sign === 'both') {
            $assertion = $this->signed($assertion);
        }
        if ($this->tamper) {
            $assertion = str_replace('>' . htmlspecialchars((string) $this->nameId) . '</saml:NameID>', '>someone-else</saml:NameID>', $assertion);
        }

        $document = new DOMDocument();
        $document->loadXML($this->responseXml($now));
        $root = $document->documentElement;
        if (!$root instanceof DOMElement) {
            throw new RuntimeException('No response element.');
        }
        $imported = new DOMDocument();
        $imported->loadXML($assertion);
        $assertionNode = $document->importNode($imported->documentElement ?? throw new RuntimeException('No assertion.'), true);
        if ($this->encryptForCertificate !== null) {
            $root->appendChild($this->encrypted($document, $assertionNode));
        } elseif ($this->nestInExtensions) {
            $extensions = $document->createElementNS(Constants::NS_SAMLP, 'samlp:Extensions');
            $extensions->appendChild($assertionNode);
            $root->insertBefore($extensions, $root->getElementsByTagNameNS(Constants::NS_SAMLP, 'Status')->item(0));
        } else {
            $root->appendChild($assertionNode);
        }
        if ($this->wrap) {
            $evil = new DOMDocument();
            $evil->loadXML(str_replace(['subject-1', 'ada@example.com', '_assertion-'], ['attacker', 'eve@example.com', '_evil-'], $this->assertionXml($now)));
            $root->insertBefore($document->importNode($evil->documentElement ?? throw new RuntimeException('No assertion.'), true), $root->lastChild);
        }
        $xml = (string) $document->saveXML($root);
        if ($this->sign === 'response' || $this->sign === 'both') {
            $xml = $this->signed($xml);
        }

        return base64_encode($xml);
    }

    private function responseXml(int $now): string
    {
        $destination = $this->omitDestination ? '' : ' Destination="' . htmlspecialchars($this->destination ?? $this->acsUrl) . '"';
        $inResponse  = $this->responseInResponseTo ?? $this->requestId;
        $inResponse  = $inResponse === '' ? '' : ' InResponseTo="' . htmlspecialchars($inResponse) . '"';

        return '<samlp:Response xmlns:samlp="' . Constants::NS_SAMLP . '" xmlns:saml="' . Constants::NS_SAML . '" ID="_response-' . bin2hex(random_bytes(8)) . '" Version="2.0" IssueInstant="' . $this->time($now) . '"' . $destination . $inResponse . '>'
            . '<saml:Issuer>' . htmlspecialchars($this->responseIssuer ?? $this->idpEntityId) . '</saml:Issuer>'
            . '<samlp:Status><samlp:StatusCode Value="' . htmlspecialchars($this->status) . '"/></samlp:Status>'
            . '</samlp:Response>';
    }

    private function assertionXml(int $now): string
    {
        $nameId = '';
        if ($this->nameId !== null) {
            $format = $this->nameIdFormat !== null ? ' Format="' . htmlspecialchars($this->nameIdFormat) . '"' : '';
            $nameId = '<saml:NameID' . $format . '>' . htmlspecialchars($this->nameId) . '</saml:NameID>';
        }
        $subjectInResponse = $this->subjectInResponseTo ?? $this->requestId;
        $attributes        = '';
        $attributeList     = [];
        foreach ($this->attributes as $name => $values) {
            $attributeList[] = [$name, $values];
        }
        if ($this->duplicateAttribute && $attributeList !== []) {
            $attributeList[] = $attributeList[0];
        }
        foreach ($attributeList as [$name, $values]) {
            $attributes .= '<saml:Attribute Name="' . htmlspecialchars($name) . '" FriendlyName="' . htmlspecialchars($name) . '-friendly">';
            foreach ($values as $value) {
                $attributes .= '<saml:AttributeValue>' . htmlspecialchars($value) . '</saml:AttributeValue>';
            }
            $attributes .= '</saml:Attribute>';
        }
        $session  = $this->sessionNotOnOrAfter !== null ? ' SessionNotOnOrAfter="' . $this->time($now + $this->sessionNotOnOrAfter) . '"' : '';
        $audience = $this->omitAudience ? '' : '<saml:AudienceRestriction><saml:Audience>' . htmlspecialchars($this->audience ?? $this->spEntityId) . '</saml:Audience></saml:AudienceRestriction>';
        if ($this->foreignAudienceRestriction) {
            $audience .= '<saml:AudienceRestriction><saml:Audience>https://other-sp.example.com</saml:Audience></saml:AudienceRestriction>';
        }
        $subjectTimes = ($this->omitSubjectNotOnOrAfter ? '' : ' NotOnOrAfter="' . $this->time($now + $this->notOnOrAfter) . '"')
            . ($this->subjectNotBefore !== null ? ' NotBefore="' . $this->time($now + $this->subjectNotBefore) . '"' : '');

        return '<saml:Assertion xmlns:saml="' . Constants::NS_SAML . '" xmlns:xs="http://www.w3.org/2001/XMLSchema" ID="_assertion-' . bin2hex(random_bytes(8)) . '" Version="2.0" IssueInstant="' . $this->time($now) . '">'
            . '<saml:Issuer>' . htmlspecialchars($this->assertionIssuer ?? $this->idpEntityId) . '</saml:Issuer>'
            . '<saml:Subject>' . $nameId
            . '<saml:SubjectConfirmation Method="' . Constants::CM_BEARER . '"><saml:SubjectConfirmationData' . $subjectTimes . ' Recipient="' . htmlspecialchars($this->recipient ?? $this->acsUrl) . '"'
            . ($subjectInResponse === '' ? '' : ' InResponseTo="' . htmlspecialchars($subjectInResponse) . '"') . '/></saml:SubjectConfirmation>'
            . '</saml:Subject>'
            . '<saml:Conditions NotBefore="' . $this->time($now + $this->notBefore) . '" NotOnOrAfter="' . $this->time($now + ($this->conditionsNotOnOrAfter ?? $this->notOnOrAfter)) . '">' . $audience . '</saml:Conditions>'
            . '<saml:AuthnStatement AuthnInstant="' . $this->time($now) . '" SessionIndex="_session-1"' . $session . '><saml:AuthnContext><saml:AuthnContextClassRef>' . htmlspecialchars($this->authnContext) . '</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement>'
            . ($attributes !== '' ? '<saml:AttributeStatement>' . $attributes . '</saml:AttributeStatement>' : '')
            . '</saml:Assertion>';
    }

    /**
     * Signs the root element (Response or Assertion) with an enveloped signature right after its Issuer, as the SAML
     * schema wants it.
     */
    private function signed(string $xml): string
    {
        $document = new DOMDocument();
        $document->loadXML($xml);
        $root = $document->documentElement;
        if (!$root instanceof DOMElement) {
            throw new RuntimeException('Nothing to sign.');
        }
        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($this->idpPair['key']);
        $signature = new XMLSecurityDSig();
        $signature->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $signature->addReferenceList([$root], $this->sha1Digest ? XMLSecurityDSig::SHA1 : XMLSecurityDSig::SHA256, ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N], ['id_name' => 'ID']);
        $signature->sign($key);
        $signature->add509Cert($this->idpPair['cert'], true);
        $issuer = $root->getElementsByTagNameNS(Constants::NS_SAML, 'Issuer')->item(0);
        $signature->insertSignature($root, $issuer?->nextSibling);

        return (string) $document->saveXML($root);
    }

    private function encrypted(DOMDocument $document, DOMNode $assertion): DOMElement
    {
        $wrapper = $document->createElementNS(Constants::NS_SAML, 'saml:EncryptedAssertion');
        $wrapper->appendChild($assertion);

        $symmetric = new XMLSecurityKey(XMLSecurityKey::AES256_GCM);
        $symmetric->generateSessionKey();
        $public = new XMLSecurityKey(XMLSecurityKey::RSA_OAEP_MGF1P, ['type' => 'public']);
        $public->loadKey((string) $this->encryptForCertificate, false, true);

        $encryption = new XMLSecEnc();
        $encryption->setNode($assertion);
        $encryption->type = XMLSecEnc::Element;
        $encryption->encryptKey($public, $symmetric);
        $encryption->encryptNode($symmetric);

        return $wrapper;
    }

    private function time(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
