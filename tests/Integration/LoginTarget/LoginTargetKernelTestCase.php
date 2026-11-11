<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LoginTarget;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Shared steps of the login target kernel tests: users, page navigations, background calls and the sign-in form.
 */
abstract class LoginTargetKernelTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $em->persist((new KernelUser())->setEmail('ada@example.com')->setPassword('old-password-123'));
        $em->flush();
        $em->clear();
    }

    protected function navigate(string $path): void
    {
        $this->client->request('GET', $path, [], [], [
            'HTTP_SEC_FETCH_MODE' => 'navigate',
            'HTTP_SEC_FETCH_DEST' => 'document',
            'HTTP_ACCEPT'         => 'text/html,application/xhtml+xml',
        ]);
    }

    /**
     * A navigation of a browser without fetch metadata that accepts anything (the target the guard must still catch).
     */
    protected function legacyNavigate(string $path): void
    {
        $this->client->request('GET', $path, [], [], ['HTTP_ACCEPT' => 'text/html,*/*']);
    }

    protected function fetch(string $path, string $method = 'GET'): void
    {
        $this->client->request($method, $path, [], [], [
            'HTTP_SEC_FETCH_MODE' => 'cors',
            'HTTP_SEC_FETCH_DEST' => 'empty',
            'HTTP_ACCEPT'         => 'application/json',
        ]);
    }

    protected function signIn(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form    = $crawler->filter('[data-nowo-ui-region="form"]')->form([
            'login_form[_username]' => 'ada@example.com',
            'login_form[_password]' => 'old-password-123',
        ]);
        $this->client->submit($form);
    }

    protected function savedTarget(): mixed
    {
        return $this->client->getRequest()->getSession()->get('_security.main.target_path');
    }
}
