<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function strlen;

/**
 * 1.32.2: with locale.in_path "both" + unlocalized "serve", a firewall whose logout.path is the bare twin intercepts
 * "/logout" and, through the opt-in locale.localized_logout_redirect, "/{_locale}/logout" too (LocalizedLogoutTestKernel).
 */
final class LocalizedLogoutKernelTest extends WebTestCase
{
    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return LocalizedLogoutTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em       = self::getContainer()->get('doctrine.orm.entity_manager');
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $em->persist((new KernelUser())->setEmail('ada@example.com')->setPassword('old-password-123'));
        $em->flush();
        $em->clear();
    }

    public function testTheFirewallInterceptsTheBareLogout(): void
    {
        $logoutPath = $this->signIn();
        self::assertStringStartsWith('/logout?_csrf_token=', $logoutPath);

        $this->client->request('GET', $logoutPath);
        self::assertResponseRedirects('/home');
        self::assertFalse($this->isSignedIn());
    }

    public function testTheLocalizedLogoutIsRedirectedToTheBareUrlAndIntercepted(): void
    {
        $logoutPath = $this->signIn();
        $query      = substr($logoutPath, strlen('/logout'));

        $this->client->request('GET', '/es/logout' . $query);
        self::assertResponseRedirects($logoutPath, 302);

        $this->client->followRedirect();
        self::assertResponseRedirects('/home');
        self::assertFalse($this->isSignedIn());
    }

    public function testTheLocalizedLogoutKeepsTheCsrfCheck(): void
    {
        $this->signIn();

        $this->client->request('GET', '/en/logout?_csrf_token=forged');
        self::assertResponseRedirects('/logout?_csrf_token=forged', 302);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->isSignedIn());
    }

    public function testOnlyTheLogoutRoutesAreRedirected(): void
    {
        $this->client->request('GET', '/es/login?next=//evil.example');
        self::assertResponseIsSuccessful();
    }

    /**
     * @return string the firewall logout URL with its CSRF token
     */
    private function signIn(): string
    {
        $crawler = $this->client->request('GET', '/en/login');
        $this->client->submit($crawler->filter('[data-nowo-ui-region="form"]')->form([
            'login_form[_username]' => 'ada@example.com',
            'login_form[_password]' => 'old-password-123',
        ]));
        self::assertResponseRedirects('/home');
        self::assertTrue($this->isSignedIn());

        $this->client->request('GET', '/logout-link');
        self::assertResponseIsSuccessful();

        return html_entity_decode((string) $this->client->getResponse()->getContent());
    }

    private function isSignedIn(): bool
    {
        $this->client->request('GET', '/logout-link');

        return $this->client->getRequest()->getSession()->has('_security_main');
    }
}
