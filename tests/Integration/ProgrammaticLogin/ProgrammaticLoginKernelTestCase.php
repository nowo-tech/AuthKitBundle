<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Invitation\InvitationManager;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\Totp\Totp;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\Tests\Integration\Invitation\CapturingInvitationMailer;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

use function basename;
use function parse_str;
use function parse_url;
use function str_contains;
use function time;

use const PHP_URL_PATH;
use const PHP_URL_QUERY;

/**
 * Social and QR sign-in through a real firewall: fresh schema, the user, a provider, and the two flows up to the sign-in.
 */
abstract class ProgrammaticLoginKernelTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em       = $this->em();
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $em->persist((new KernelUser())->setEmail('ada@example.com')->setPassword('old-password-123'));
        $em->persist((new SocialLoginCredential())->setProvider('google')->setLabel('Google')->setEnabled(true)->setClientId('id-placeholder')->setClientSecret('secret-placeholder'));
        $em->flush();
    }

    /**
     * @return string|null the session id before the sign-in
     */
    protected function socialCallback(): ?string
    {
        $this->client->request('GET', '/login/social/google');
        self::assertResponseStatusCodeSame(302);
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);
        $state = $query['state'] ?? null;
        self::assertIsString($state);
        $session = $this->sessionId();
        $this->client->request('GET', '/login/social/google/check', ['code' => 'c', 'state' => $state]);

        return $session;
    }

    /**
     * @return string|null the session id before the sign-in
     */
    protected function qrCompletion(): ?string
    {
        // A session that exists before the sign-in, as a browser coming back from the sign-in page has.
        $this->client->request('GET', '/login');
        $this->client->followRedirects();
        $this->client->request('GET', '/login/qr');
        $this->client->followRedirects(false);
        self::assertResponseIsSuccessful();
        $id = basename((string) parse_url($this->client->getRequest()->getUri(), PHP_URL_PATH));

        $manager = self::getContainer()->get(QrLoginChallengeManager::class);
        self::assertInstanceOf(QrLoginChallengeManager::class, $manager);
        $challenge = $manager->find($id);
        self::assertInstanceOf(QrLoginChallenge::class, $challenge);
        $manager->approve($challenge, $this->user(), null);

        $session = $this->sessionId();
        $this->client->request('GET', '/login/qr/' . $id . '/complete');

        return $session;
    }

    protected function assertAdaKeepsHerPasswordAndHasNoLink(): void
    {
        $this->em()->clear();
        self::assertSame('old-password-123', $this->user()->getPassword(), 'GY-L9: a failed attempt replaces no password.');
        self::assertSame(0, $this->em()->getRepository(SocialLoginAccount::class)->count([]), 'GY-L9: and links nothing.');
    }

    /**
     * Submits the registration form on `/register` or an invitation page.
     */
    protected function submitRegistration(string $path, string $email): void
    {
        $crawler = $this->client->request('GET', $path);
        if ($this->client->getResponse()->isRedirect()) {
            $crawler = $this->client->followRedirect();
        }
        $form = $crawler->filter('form')->first()->form();
        foreach ($form->all() as $name => $field) {
            if (str_contains($name, 'email') && !$field->isDisabled()) {
                $form[$name] = $email;
            }
            if (str_contains($name, 'password')) {
                $form[$name] = 'a-long-enough-password-123';
            }
        }
        $this->client->submit($form);
    }

    protected function userCount(string $email): int
    {
        return $this->em()->getRepository(KernelUser::class)->count(['email' => $email]);
    }

    protected function invitationPath(string $email): string
    {
        $manager = self::getContainer()->get(InvitationManager::class);
        self::assertInstanceOf(InvitationManager::class, $manager);
        $manager->invite(null, 'default', new InvitationRequest($email));
        $mailer = self::getContainer()->get(CapturingInvitationMailer::class);
        self::assertInstanceOf(CapturingInvitationMailer::class, $mailer);
        $mail = $mailer->last();
        self::assertNotNull($mail);

        return (string) parse_url($mail->acceptUrl, PHP_URL_PATH);
    }

    protected function enrol(): void
    {
        $manager = self::getContainer()->get(MfaManager::class);
        self::assertInstanceOf(MfaManager::class, $manager);
        $secret = $manager->newSecret();
        $totp   = new Totp();
        self::assertNotNull($manager->enable($this->user(), $secret, $totp->code($secret, $totp->step(time()))));
    }

    protected function assertSessionMovedAndNoRememberMe(?string $before): void
    {
        self::assertNotNull($before);
        self::assertNotSame($before, $this->sessionId(), 'Session fixation: the id moved on at sign-in.');
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            self::assertNotSame('REMEMBERME', $cookie->getName(), 'Nobody asked to be remembered.');
        }
    }

    private function sessionId(): ?string
    {
        return $this->client->getCookieJar()->get('MOCKSESSID')?->getValue();
    }

    protected function token(): ?TokenInterface
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);

        return $tokens->getToken();
    }

    protected function user(): KernelUser
    {
        $user = $this->em()->getRepository(KernelUser::class)->findOneBy(['email' => 'ada@example.com']);
        self::assertInstanceOf(KernelUser::class, $user);

        return $user;
    }

    protected function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
