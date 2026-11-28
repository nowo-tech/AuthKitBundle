<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use Nowo\AuthKitBundle\Form\SocialFormPostRelayType;
use Nowo\AuthKitBundle\SocialLogin\SocialFormPostRelay;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

use function is_string;
use function strlen;

/**
 * The IdP's HTTP-POST to the ACS is a cross-site POST (2.10.0): a SameSite=Lax or Strict session cookie does not come
 * with it, and a session started there would replace the visitor's own cookie. As for the `form_post` OAuth callbacks
 * ({@see SocialFormPostRelay}), the first POST touches no session and answers a page that posts `SAMLResponse` and
 * `RelayState` again from the application's own origin (automatically, or with the Continue button); that same-site
 * request carries the session and is checked. Same template and form type as the OAuth relay. Stateless.
 *
 * @internal
 */
final class SamlPostRelay
{
    /** @var array<string, int> relayed field => longest value */
    public const FIELDS = ['SAMLResponse' => SamlResponseValidator::MAX_RESPONSE_BYTES, 'RelayState' => 80];

    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly Environment $twig,
    ) {
    }

    public function relay(Request $request, string $action): Response
    {
        $data   = [SocialFormPostRelay::MARKER => '1'];
        $fields = [];
        foreach (self::FIELDS as $field => $maxLength) {
            $value = $request->request->get($field);
            if (is_string($value) && $value !== '' && strlen($value) <= $maxLength) {
                $data[$field] = $value;
                $fields[]     = $field;
            }
        }

        $form = $this->formFactory->createNamed('', SocialFormPostRelayType::class, $data, ['action' => $action, 'method' => 'POST', 'relayed_fields' => $fields]);

        $response = new Response($this->twig->render(SocialFormPostRelay::TEMPLATE, ['form' => $form->createView()]));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
