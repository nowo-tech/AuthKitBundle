<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Auth pages through the real kernel (REQ-UI-003): UiKit auth scaffold on the guest layout, form_login CSRF and
 * credential errors shown in the panel, password reset by link and by code with the confirmation field before the
 * submit button and the validation errors displayed.
 */
final class AuthPagesKernelTest extends WebTestCase
{
    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return AuthKitTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $em       = $this->entityManager();
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $em->persist((new KernelUser())->setEmail('ada@example.com')->setPassword('old-password-123'));
        $em->flush();
        $em->clear();
    }

    public function testLoginPageRendersTheAuthScaffoldOnTheGuestLayout(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-nowo-ui-host-tokens], html');
        self::assertSelectorExists('.nowo-ui-page--guest main[data-nowo-ui-bundle="NowoAuthKitBundle"]');
        $panel = $crawler->filter('section[data-nowo-ui-page="auth"][data-testid="auth-kit-login"]');
        self::assertCount(1, $panel);
        self::assertSame('Acme', trim($panel->filter('.nowo-ui-auth-panel__brand')->text()));
        self::assertSame('Sign in', trim($panel->filter('h1')->text()));
        self::assertSame('Sign in', trim($crawler->filter('title')->text()));
        self::assertCount(1, $panel->filter('form[data-nowo-ui-region="form"] input[name="login_form[_csrf_token]"]'));
        self::assertSame(
            ['login_form[_username]', 'login_form[_password]', 'login_form[_csrf_token]'],
            $panel->filter('form input')->each(static fn (Crawler $input): string => (string) $input->attr('name')),
        );
        self::assertSame(
            ['/magic-login', '/login/qr', '/reset-password', '/register'],
            $panel->filter('footer .nowo-ui-auth-panel__links a')->each(static fn (Crawler $a): string => (string) $a->attr('href')),
        );
        self::assertSelectorExists('link[href$="/bundles/nowoauthkit/css/nowo-auth-kit.css"]');
        self::assertSelectorNotExists('script[src^="http"], link[href^="http"]');
    }

    public function testLoginShowsTheCsrfError(): void
    {
        $this->client->request('POST', '/login', ['login_form' => ['_username' => 'ada@example.com', '_password' => 'old-password-123', '_csrf_token' => 'forged']]);
        self::assertResponseRedirects();

        $crawler = $this->client->followRedirect();
        self::assertSame('Invalid CSRF token.', trim($crawler->filter('[data-nowo-ui-page="auth"] [role="alert"]')->text()));
        self::assertSame(1, substr_count((string) $this->client->getResponse()->getContent(), 'Invalid CSRF token.'), 'The login error is shown exactly once.');
        self::assertSame('ada@example.com', $crawler->filter('input[name="login_form[_username]"]')->attr('value'));
    }

    public function testLoginShowsTheCredentialsErrorThenSignsIn(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form    = $crawler->filter('[data-nowo-ui-region="form"]')->form([
            'login_form[_username]' => 'ada@example.com',
            'login_form[_password]' => 'wrong',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSame('Invalid credentials.', trim($crawler->filter('[data-nowo-ui-page="auth"] [role="alert"]')->text()));

        $form = $crawler->filter('[data-nowo-ui-region="form"]')->form(['login_form[_password]' => 'old-password-123']);
        $this->client->submit($form);
        self::assertResponseRedirects('/home');
    }

    public function testResetByLinkRendersTheConfirmationBeforeTheSubmitAndShowsErrors(): void
    {
        $crawler = $this->client->request('GET', '/reset-password');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="auth-kit-reset_request"] .nowo-ui-page-header__intro', 'Enter your account identifier');

        $this->client->submit($crawler->filter('[data-nowo-ui-region="form"]')->form(['reset_password_request_form[identifier]' => 'ada@example.com']));
        self::assertResponseRedirects();
        $token = $this->notifier()->last?->linkToken();
        self::assertNotNull($token);

        $crawler = $this->client->request('GET', '/reset-password/reset/' . $token);
        self::assertResponseIsSuccessful();
        $form  = $crawler->filter('[data-testid="auth-kit-reset_password"] form');
        $order = $form->filter('input[type="password"], button[type="submit"], input[type="hidden"]')
            ->each(static fn (Crawler $node): string => $node->attr('name') ?? $node->nodeName());
        self::assertSame(['reset_password_form[password][first]', 'reset_password_form[password][second]', 'reset_password_form[_token]', 'button'], $order, 'Every field (confirmation and CSRF included) renders before the submit button.');

        $crawler = $this->client->submit($form->form([
            'reset_password_form[password][first]'  => 'new-password-456',
            'reset_password_form[password][second]' => 'something-else-789',
        ]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="auth-kit-reset_password"] form', 'The password fields must match.');

        $crawler = $this->client->submit($crawler->filter('[data-testid="auth-kit-reset_password"] form')->form([
            'reset_password_form[password][first]'  => 'new-password-456',
            'reset_password_form[password][second]' => 'new-password-456',
            'reset_password_form[_token]'           => 'forged',
        ]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="auth-kit-reset_password"] form', 'The CSRF token is invalid');
        self::assertSame(1, substr_count((string) $this->client->getResponse()->getContent(), 'The CSRF token is invalid'), 'The root form error is shown exactly once (AuthKit prints it; the UiKit >= 2.3 scaffold copy is opted out).');

        $this->client->submit($crawler->filter('[data-testid="auth-kit-reset_password"] form')->form([
            'reset_password_form[password][first]'  => 'new-password-456',
            'reset_password_form[password][second]' => 'new-password-456',
        ]));
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Your password has been updated', $crawler->filter('body')->text());
        self::assertSame('new-password-456', $this->entityManager()->getRepository(KernelUser::class)->findOneBy(['email' => 'ada@example.com'])?->getPassword());
    }

    public function testResetByCodeShowsTheInvalidCodeError(): void
    {
        $crawler = $this->client->request('GET', '/reset-password/complete');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('[data-testid="auth-kit-reset_password_code"] form');
        self::assertSame(
            ['reset_password_code_form[identifier]', 'reset_password_code_form[code]', 'reset_password_code_form[password][first]', 'reset_password_code_form[password][second]', 'reset_password_code_form[_token]', 'button'],
            $form->filter('input, button')->each(static fn (Crawler $node): string => $node->attr('name') ?? $node->nodeName()),
        );

        $this->client->submit($form->form([
            'reset_password_code_form[identifier]'       => 'ada@example.com',
            'reset_password_code_form[code]'             => '000000',
            'reset_password_code_form[password][first]'  => 'new-password-456',
            'reset_password_code_form[password][second]' => 'new-password-456',
        ]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="auth-kit-reset_password_code"] form', 'The code is invalid or has expired.');
    }

    public function testQrChallengePageExposesTheStatusDataAttributes(): void
    {
        $this->client->request('GET', '/login/qr');
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        $root = $crawler->filter('[data-testid="auth-kit-qr_login_show"] [data-nowo-auth-kit-qr]');
        self::assertCount(1, $root);
        self::assertSame('pending', $root->attr('data-nowo-auth-kit-qr-state'));
        self::assertMatchesRegularExpression('#^/login/qr/[0-9a-f-]{36}/status$#', (string) $root->attr('data-status-url'));
        self::assertSame('Waiting for approval…', trim($root->filter('[data-nowo-auth-kit-qr-region="status"]')->text()));
        self::assertSame('/login/qr', $crawler->filter('[data-testid="auth-kit-qr_login_show"] form')->attr('action'));
        self::assertSelectorExists('script[src$="/bundles/nowoauthkit/js/nowo-auth-kit-qr-login.js"]');
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        return $em;
    }

    private function notifier(): CapturingPasswordResetNotifier
    {
        /** @var CapturingPasswordResetNotifier $notifier */
        $notifier = self::getContainer()->get(CapturingPasswordResetNotifier::class);

        return $notifier;
    }
}
