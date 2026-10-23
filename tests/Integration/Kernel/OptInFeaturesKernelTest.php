<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Controller\ResetPasswordController;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Legacy-host opt-ins through the real kernel (OptInTestKernel).
 */
final class OptInFeaturesKernelTest extends WebTestCase
{
    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return OptInTestKernel::class;
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

    public function testAuthKitEntitiesAreNotMappedDespiteAutoMapping(): void
    {
        $classes = array_map(
            static fn (ClassMetadata $metadata): string => $metadata->getName(),
            $this->entityManager()->getMetadataFactory()->getAllMetadata(),
        );

        self::assertSame([KernelUser::class], $classes);
        self::assertFalse($this->entityManager()->getMetadataFactory()->isTransient(KernelUser::class));
        foreach ([QrLoginChallenge::class, SocialLoginAccount::class, SocialLoginCredential::class] as $entity) {
            self::assertTrue($this->entityManager()->getMetadataFactory()->isTransient($entity), $entity);
        }
    }

    public function testRoutesUseTheSuffixAndSkipDisabledFeatures(): void
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $names = array_keys($router->getRouteCollection()->all());

        foreach (['nowo_auth_kit_login', 'nowo_auth_kit_login.default', 'nowo_auth_kit_logout.default', 'nowo_auth_kit_reset_password_request.default', 'nowo_auth_kit_reset_password.default'] as $name) {
            self::assertContains($name, $names);
        }
        foreach ($names as $name) {
            self::assertStringNotContainsString('_unlocalized', $name);
            self::assertDoesNotMatchRegularExpression('/^nowo_auth_kit_(register|magic_login|social_login|qr_login)/', $name);
        }
        self::assertSame('/reset-password/reset', $router->generate('nowo_auth_kit_reset_password.default'));

        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/register');
        self::assertResponseStatusCodeSame(404);
    }

    public function testLogoutIsAPostWithTheCsrfTokenFromTheEmbedForm(): void
    {
        $crawler = $this->client->request('GET', '/en/login');
        $this->client->submit($crawler->filter('[data-nowo-ui-region="form"]')->form([
            'login_form[_username]' => 'ada@example.com',
            'login_form[_password]' => 'old-password-123',
        ]));
        self::assertResponseRedirects('/home');

        $this->client->request('GET', '/en/logout');
        self::assertResponseStatusCodeSame(405);

        $crawler = $this->client->request('GET', '/embed');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form.nowo-auth-kit-embed__logout');
        self::assertCount(1, $form);
        self::assertSame('/en/logout', $form->attr('action'));
        self::assertCount(0, $crawler->filter('a[href*="logout"]'));

        $this->client->submit($form->form(['_csrf_token' => 'forged']));
        self::assertResponseStatusCodeSame(403);

        $this->client->submit($form->form());
        self::assertResponseRedirects('/home');
        $this->client->request('GET', '/embed');
        self::assertSelectorNotExists('form.nowo-auth-kit-embed__logout');
    }

    public function testResetRequestGoesToTheCheckEmailPageAndTheTokenMovesToTheSession(): void
    {
        $crawler = $this->client->request('GET', '/en/reset-password');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('[data-nowo-ui-region="form"]')->form(['reset_password_request_form[identifier]' => 'ada@example.com']));
        self::assertResponseRedirects('/en/check-email');
        $this->client->followRedirect();
        self::assertSame('ttl=3600 throttled=0', $this->client->getResponse()->getContent());

        $notifier = self::getContainer()->get(CapturingPasswordResetNotifier::class);
        self::assertInstanceOf(CapturingPasswordResetNotifier::class, $notifier);
        $token = $notifier->last?->linkToken();
        self::assertNotNull($token);

        $this->client->request('GET', '/en/reset-password/reset/' . $token);
        self::assertResponseRedirects('/en/reset-password/reset');
        self::assertSame($token, $this->client->getRequest()->getSession()->get(ResetPasswordController::SESSION_TOKEN_KEY . 'default'));

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('[data-testid="auth-kit-reset_password"] form')->form([
            'reset_password_form[password][first]'  => 'new-password-456',
            'reset_password_form[password][second]' => 'new-password-456',
        ]));
        self::assertResponseRedirects();
        self::assertFalse($this->client->getRequest()->getSession()->has(ResetPasswordController::SESSION_TOKEN_KEY . 'default'));
        self::assertSame('new-password-456', $this->entityManager()->getRepository(KernelUser::class)->findOneBy(['email' => 'ada@example.com'])?->getPassword());

        $this->client->request('GET', '/en/reset-password/reset');
        self::assertResponseRedirects('/en/reset-password');
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        return $em;
    }
}
