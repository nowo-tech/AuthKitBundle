<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;

use function str_replace;

/**
 * REQ-SEC-006 / audit OG (1.47.0) on the real kernel: the users are keyed by e-mail (string owner ids) and the MFA table
 * still has a folding unique key (`COLLATE NOCASE`, like a MySQL `*_ci` table not altered yet). A look-alike account
 * (`MEMBER@` next to `member@`) can never take the other's credential: its enrolment is refused with a message, the
 * entity manager stays open, and the first account keeps its second factor.
 */
final class MfaLookAlikeKernelTest extends MfaKernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $em         = $this->em();
        $connection = $em->getConnection();
        $table      = $em->getClassMetadata(MfaCredential::class)->getTableName();
        $create     = (string) $connection->fetchOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
        $indexes    = $connection->fetchFirstColumn("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table]);
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        $connection->executeStatement('DROP TABLE ' . $table);
        $connection->executeStatement(str_replace('user_id VARCHAR(191) NOT NULL', 'user_id VARCHAR(191) NOT NULL COLLATE NOCASE', $create));
        foreach ($indexes as $index) {
            $connection->executeStatement((string) $index);
        }
        $em->persist((new KernelUser())->setEmail('MEMBER@example.com')->setPassword('look-alike-password-123'));
        $em->persist((new KernelUser())->setEmail('ADMIN@example.com')->setPassword('look-alike-password-123')->setRoles(['ROLE_ADMIN']));
        $em->flush();
        $em->clear();
    }

    public function testALookAlikeCannotEnrolOverTheOtherAccountsCredential(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [$secret] = $this->enrol();
        $this->client->request('GET', '/logout');

        $this->signIn('MEMBER@example.com', 'look-alike-password-123');
        self::assertResponseRedirects('/home', null, 'The look-alike has no second factor of its own.');
        $crawler = $this->client->request('GET', '/account/security/mfa');
        $form    = $crawler->filter('form[name="mfa_enable"]')->form(['mfa_enable[code]' => $this->code(str_replace(' ', '', $crawler->filter('[data-testid="auth-kit-mfa-secret"]')->text()))]);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('cannot be set up for this account', (string) $this->client->getResponse()->getContent());

        self::assertTrue($this->em()->isOpen());
        self::assertSame($secret, $this->secretOf('member@example.com'), 'The first account keeps its secret.');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->em()->getClassMetadata(MfaCredential::class)->getTableName()));
    }

    public function testARequiredLookAlikeEndsAtTheSetupStepWithAMessage(): void
    {
        $this->signIn('admin@example.com', 'admin-password-123');
        $this->enrol();
        $this->client->request('GET', '/logout');

        $this->signIn('ADMIN@example.com', 'look-alike-password-123');
        self::assertResponseRedirects('/login/mfa/setup', null, 'Required MFA that cannot be stored: the setup step, no sign-in.');
        $crawler = $this->client->request('GET', '/login/mfa/setup');
        self::assertResponseIsSuccessful();
        $secret = str_replace(' ', '', $crawler->filter('[data-testid="auth-kit-mfa-secret"]')->text());
        $this->client->submit($crawler->filter('form[name="mfa_code_form"]')->form(['mfa_code_form[code]' => $this->code($secret)]));
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertStringContainsString('cannot be set up for this account', (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->em()->isOpen());
    }
}
