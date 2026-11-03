<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\Totp\Totp;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function str_replace;

/**
 * Real-kernel MFA tests (1.34): fresh SQLite schema, a member, an admin (MFA required) and a support user (admin
 * reset); helpers to sign in, enrol through the account page and compute TOTP codes on the mock clock.
 */
abstract class MfaKernelTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    /** @var list<object> */
    protected array $events = [];

    protected static function getKernelClass(): string
    {
        return MfaTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();

        $em       = $this->em();
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $em->persist((new KernelUser())->setEmail('member@example.com')->setPassword('member-password-123'));
        $em->persist((new KernelUser())->setEmail('admin@example.com')->setPassword('admin-password-123')->setRoles(['ROLE_ADMIN']));
        $em->persist((new KernelUser())->setEmail('support@example.com')->setPassword('support-password-123')->setRoles(['ROLE_SUPPORT']));
        $em->persist((new KernelUser())->setEmail('boss@example.com')->setPassword('boss-password-123')->setRoles(['ROLE_SUPERVISOR']));
        $em->flush();
        $em->clear();

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach (['MfaEnabledEvent', 'MfaDisabledEvent', 'MfaResetByAdminEvent', 'MfaRecoveryCodesRegeneratedEvent', 'MfaRecoveryCodeUsedEvent', 'MfaChallengeFailedEvent', 'MfaLockedOutEvent', 'MfaChallengePassedEvent', 'MfaLoginCompletedEvent', 'MfaTrustedDeviceAddedEvent', 'MfaTrustedDevicesForgottenEvent'] as $event) {
            $dispatcher->addListener('Nowo\\AuthKitBundle\\Mfa\\Event\\' . $event, function (object $e): void {
                $this->events[] = $e;
            });
        }
    }

    protected function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    protected function clock(): MockClock
    {
        $clock = self::getContainer()->get(MockClock::class);
        self::assertInstanceOf(MockClock::class, $clock);

        return $clock;
    }

    protected function manager(): MfaManager
    {
        $manager = self::getContainer()->get(MfaManager::class);
        self::assertInstanceOf(MfaManager::class, $manager);

        return $manager;
    }

    protected function user(string $email): KernelUser
    {
        $this->em()->clear();
        $user = $this->em()->find(KernelUser::class, $email);
        self::assertInstanceOf(KernelUser::class, $user);

        return $user;
    }

    /**
     * Posts the AuthKit login form (follows nothing).
     */
    protected function signIn(string $email, string $password, bool $remember = false, ?KernelBrowser $client = null): Crawler
    {
        $client ??= $this->client;
        $crawler = $client->request('GET', '/login');
        $values  = ['login_form[_username]' => $email, 'login_form[_password]' => $password];
        $form    = $crawler->filter('form')->form($values);
        if ($remember) {
            $box = $form['login_form[_remember_me]'];
            self::assertInstanceOf(ChoiceFormField::class, $box);
            $box->tick();
        }

        return $client->submit($form);
    }

    /**
     * Turns MFA on through the account page; returns [secret, recovery codes].
     *
     * @return array{0: string, 1: list<string>}
     */
    protected function enrol(?KernelBrowser $client = null): array
    {
        $client ??= $this->client;
        $crawler = $client->request('GET', '/account/security/mfa');
        self::assertResponseIsSuccessful();
        $secret = str_replace(' ', '', $crawler->filter('[data-testid="auth-kit-mfa-secret"]')->text());
        $form   = $crawler->filter('form[name="mfa_enable"]')->form(['mfa_enable[code]' => $this->code($secret)]);
        $page   = $client->submit($form);
        self::assertResponseIsSuccessful();
        $codes = $page->filter('[data-testid="auth-kit-mfa-codes"] li')->each(static fn (Crawler $li): string => $li->text());
        self::assertCount(10, $codes);
        $this->nextStep();

        return [$secret, $codes];
    }

    protected function code(string $secret, int $offset = 0): string
    {
        return (new Totp())->code($secret, (new Totp())->step($this->clock()->now()->getTimestamp()) + $offset);
    }

    /**
     * Moves the mock clock to the next TOTP step (a used step is refused).
     */
    protected function nextStep(): void
    {
        $this->clock()->modify('+30 seconds');
    }

    protected function secretOf(string $email): string
    {
        $credential = $this->em()->getRepository(MfaCredential::class)->findOneBy(['userId' => $email]);
        self::assertInstanceOf(MfaCredential::class, $credential);

        return (string) $credential->getTotpSecret();
    }

    /**
     * Submits the challenge form.
     *
     * @param array<string, bool|string> $values
     */
    protected function challenge(array $values, ?KernelBrowser $client = null): Crawler
    {
        $client ??= $this->client;
        $crawler = $client->request('GET', '/login/mfa');
        self::assertTrue($client->getResponse()->isSuccessful(), 'The challenge page is shown.');
        $form = $crawler->filter('form[name="mfa_code_form"]')->form();
        foreach ($values as $field => $value) {
            if ($value === true) {
                $box = $form['mfa_code_form[' . $field . ']'];
                self::assertInstanceOf(ChoiceFormField::class, $box);
                $box->tick();
            } else {
                $form['mfa_code_form[' . $field . ']'] = (string) $value;
            }
        }

        return $client->submit($form);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    protected function eventsOf(string $class): array
    {
        $found = [];
        foreach ($this->events as $event) {
            if ($event instanceof $class) {
                $found[] = $event;
            }
        }

        return $found;
    }
}
