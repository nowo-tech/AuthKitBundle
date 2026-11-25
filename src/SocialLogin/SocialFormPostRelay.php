<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use Nowo\AuthKitBundle\Form\SocialFormPostRelayType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

use function is_string;
use function strlen;

/**
 * `response_mode=form_post` callbacks (2.3.0, Sign in with Apple).
 *
 * The provider answers with a cross-site POST. A SameSite=Lax (Symfony's default) or Strict session cookie is not sent
 * with it, so the pending state is out of reach, and a session started there would get a new cookie that replaces the
 * visitor's own. So the first POST touches no session: it answers a small page whose form posts the same fields again
 * from the application's own origin (automatically with the bundled script, or with the Continue button), and that
 * second, same-site request carries the session cookie and is checked like any callback.
 *
 * Only the fields the callback needs are relayed (`code`, `state`, `error`, Apple's `user`); the `id_token` of the
 * front channel is dropped (the bundle takes the one from the token endpoint). The page is not cached, sends no
 * referrer and is not indexed. Stateless (REQ-RUNTIME-002).
 */
final class SocialFormPostRelay
{
    /** Hidden field that marks the relayed request. */
    public const MARKER = '_nowo_auth_kit_relay';

    /** @var list<string> */
    public const FIELDS = ['code', 'state', 'error', 'user'];

    public const FIELD_MAX_LENGTH = 4096;

    public const TEMPLATE = '@NowoAuthKitBundle/security/social_form_post.html.twig';

    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly Environment $twig,
    ) {
    }

    /**
     * Whether this POST is the relayed one (the marker is set). A relayed request whose browser says it came from
     * another site is not trusted to be one.
     */
    public static function isRelayed(Request $request): bool
    {
        if ($request->request->get(self::MARKER) !== '1') {
            return false;
        }
        $site = $request->headers->get('Sec-Fetch-Site');

        return $site === null || $site === 'same-origin';
    }

    public function relay(Request $request, string $action): Response
    {
        $data   = [self::MARKER => '1'];
        $fields = [];
        foreach (self::FIELDS as $field) {
            $value = $request->request->get($field);
            if (is_string($value) && $value !== '' && strlen($value) <= self::FIELD_MAX_LENGTH) {
                $data[$field] = $value;
                $fields[]     = $field;
            }
        }

        $form = $this->formFactory->createNamed('', SocialFormPostRelayType::class, $data, ['action' => $action, 'method' => 'POST', 'relayed_fields' => $fields]);

        $response = new Response($this->twig->render(self::TEMPLATE, ['form' => $form->createView()]));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
