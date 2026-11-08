<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use Nowo\AuthKitBundle\Mfa\MfaStatus;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use function dirname;
use function file_get_contents;
use function preg_replace;
use function substr_count;

/**
 * The 1.38 template points: the pages stay byte-identical to their 1.37.1 copies (`templates/v137`) when nothing uses them.
 * The 1.40 recovery codes blocks: same check against the 1.39 copies (`templates/v139`).
 */
final class TemplateGapsKernelTest extends KernelTestCase
{
    private const CODES = ['mfa_codes' => ['AAAA-BBBB', 'CCCC-DDDD'], 'mfa_profile' => 'default', 'mfa_account_url' => '/account/security/mfa'];

    protected static function getKernelClass(): string
    {
        return FormLoginAndLoginLinkKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($request);
        $loader = $this->twig()->getLoader();
        self::assertInstanceOf(FilesystemLoader::class, $loader);
        $loader->addPath(__DIR__ . '/templates', 'gaps');
    }

    public function testGridIsByteIdenticalByDefault(): void
    {
        self::assertSame(
            $this->twig()->render('@gaps/v137/account_codes_grid.html.twig', self::CODES),
            $this->twig()->render('@NowoAuthKitBundle/mfa/account_codes_grid.html.twig', self::CODES),
        );
    }

    public function testAccountPageIsByteIdenticalByDefault(): void
    {
        self::assertSame(
            $this->withoutCsrf($this->twig()->render('@gaps/v137/account.html.twig', $this->account())),
            $this->withoutCsrf($this->twig()->render('@NowoAuthKitBundle/mfa/account.html.twig', $this->account())),
        );
    }

    public function testBeforeBlockOfTheAccountPage(): void
    {
        self::assertStringContainsString(
            'data-testid="auth-kit-mfa-account"><nav data-testid="host-nav"></nav>',
            $this->twig()->render('@gaps/host_account.html.twig', $this->account()),
        );
    }

    public function testABeforeBlockTurnsTheHeadingIntoAnH2(): void
    {
        $html = $this->twig()->render('@gaps/host_codes_before.html.twig', self::CODES);

        self::assertStringContainsString('<nav data-testid="host-nav"></nav>', $html);
        self::assertStringContainsString('<h2 id="mfa-codes-heading"', $html);
        self::assertStringNotContainsString('<h1 id="mfa-codes-heading"', $html);
    }

    public function testTheHeadingLevelCanBeSetAndIsAllowlisted(): void
    {
        self::assertStringContainsString('<h3 id="mfa-codes-heading"', $this->twig()->render('@gaps/host_codes_h3.html.twig', self::CODES));
        self::assertStringContainsString('<h1 id="mfa-codes-heading"', $this->twig()->render('@gaps/host_codes_bad_level.html.twig', self::CODES));
    }

    public function testActionsAsUiKitKinds(): void
    {
        $html = $this->twig()->render('@gaps/host_codes_kinds.html.twig', self::CODES);

        self::assertStringContainsString('nowo-ui-action--export', $html);
        self::assertStringContainsString('nowo-ui-action--save', $html);
        self::assertStringContainsString('download="recovery-codes.txt"', $html);
        self::assertStringContainsString('data-testid="auth-kit-mfa-codes-done"', $html);
        self::assertStringContainsString('href="/account/security/mfa"', $html);
        self::assertStringContainsString('AAAA-BBBB%0D%0ACCCC-DDDD', $html);
    }

    public function testAccountCodesPageIsByteIdenticalByDefault(): void
    {
        self::assertSame(
            $this->twig()->render('@gaps/v139/account_codes.html.twig', self::CODES),
            $this->twig()->render('@NowoAuthKitBundle/mfa/account_codes.html.twig', self::CODES),
        );
    }

    public function testRecoveryCodesPageIsByteIdenticalByDefault(): void
    {
        self::assertSame(
            $this->withoutFormToken($this->twig()->render('@gaps/v139/recovery_codes.html.twig', $this->recovery())),
            $this->withoutFormToken($this->twig()->render('@NowoAuthKitBundle/mfa/recovery_codes.html.twig', $this->recovery())),
        );
    }

    public function testRecoveryCodesPageActionBlocks(): void
    {
        $html = $this->twig()->render('@gaps/host_recovery_kinds.html.twig', $this->recovery());

        self::assertStringContainsString('<p data-testid="host-codes-actions">', $html);
        self::assertStringContainsString('nowo-ui-action--export', $html);
        self::assertStringContainsString('AAAA-BBBB%0D%0ACCCC-DDDD', $html);
        self::assertSame(1, substr_count($html, 'data-testid="auth-kit-mfa-codes-download"'), 'The block replaces the default download link.');
        self::assertStringNotContainsString('data-testid="auth-kit-mfa-codes-done"', $html, 'No Done without mfa_account_url.');
        self::assertStringContainsString('<button type="submit" data-testid="host-continue"><svg aria-hidden="true"></svg>Continue</button>', $html);
        self::assertStringContainsString('data-testid="auth-kit-mfa-continue"', $html, 'The Continue form stays the bundle\'s.');
        self::assertStringContainsString('_token', $html, 'The CSRF field stays in the form.');
    }

    public function testAccountCodesPageActionBlocks(): void
    {
        $html = $this->twig()->render('@gaps/host_account_codes_kinds.html.twig', self::CODES);

        self::assertStringContainsString('nowo-ui-action--export', $html);
        self::assertStringContainsString('nowo-ui-action--save', $html);
        self::assertSame(1, substr_count($html, 'data-testid="auth-kit-mfa-codes-download"'));
        self::assertSame(1, substr_count($html, 'href="/account/security/mfa"'), 'The emptied done block leaves only the kinds Done.');
    }

    public function testTheGridDeclaresItsListStyle(): void
    {
        self::assertMatchesRegularExpression('/\\.nowo-auth-kit-mfa__code-grid \\{[^}]*list-style: decimal;/', (string) file_get_contents(dirname(__DIR__, 3) . '/src/Resources/public/css/nowo-auth-kit.css'));
    }

    /**
     * @return array<string, mixed>
     */
    private function account(): array
    {
        return ['mfa_status' => new MfaStatus('default', false, null, 0, [], false, null, 3), 'mfa_profile' => 'default', 'mfa_sign_in_again' => 15, 'mfa_logout_route' => 'nowo_auth_kit_logout'];
    }

    /**
     * The recovery codes page context as MfaChallengeController builds it (a fresh form view per render).
     *
     * @return array<string, mixed>
     */
    private function recovery(): array
    {
        $container = self::getContainer();
        $registry  = $container->get(ProfileRegistry::class);
        self::assertInstanceOf(ProfileRegistry::class, $registry);
        $pages = $container->get(AuthPageFactory::class);
        self::assertInstanceOf(AuthPageFactory::class, $pages);
        $forms = $container->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $forms);
        $profile = $registry->getDefault();

        return $pages->context('mfa_recovery_codes', $pages->create('mfa_recovery_codes', $profile), $profile, [
            'mfa_codes'         => self::CODES['mfa_codes'],
            'mfa_continue_form' => $forms->createNamed('nowo_auth_kit_mfa_continue', FormType::class, null, ['action' => '/login/mfa/setup'])->createView(),
        ]);
    }

    /** A form's CSRF field value can differ on every render. */
    private function withoutFormToken(string $html): string
    {
        return (string) preg_replace('/(name="[a-z_]+\[_token\]"[^>]* value=")[^"]*/', '$1x', $html);
    }

    /** A CSRF token differs on every render. */
    private function withoutCsrf(string $html): string
    {
        return (string) preg_replace('/_csrf_token=[^"]+/', '_csrf_token=x', $html);
    }

    private function twig(): Environment
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig;
    }
}
