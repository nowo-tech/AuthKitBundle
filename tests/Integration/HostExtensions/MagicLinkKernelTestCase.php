<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * Uses a freshly signed link through the real firewall and returns the resulting token.
 */
abstract class MagicLinkKernelTestCase extends WebTestCase
{
    protected function useLink(): mixed
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $user = (new KernelUser())->setEmail('ada@example.com')->setPassword('old-password-123');
        $em->persist($user);
        $em->flush();

        $handler = self::getContainer()->get('security.authenticator.login_link_handler.main');
        self::assertInstanceOf(LoginLinkHandlerInterface::class, $handler);
        $client->request('GET', $handler->createLoginLink($user)->getUrl());

        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);

        return $tokens->getToken();
    }
}
