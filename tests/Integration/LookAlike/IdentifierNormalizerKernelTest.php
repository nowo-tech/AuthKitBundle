<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LookAlike;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Nowo\AuthKitBundle\Admin\AdminUserCreator;
use Nowo\AuthKitBundle\Identity\LowercaseAsciiEmailIdentifierNormalizer;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginUserResolver;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestHandler;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\CapturingPasswordResetNotifier;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_column;
use function array_map;
use function intdiv;
use function random_int;
use function sprintf;
use function str_ends_with;

/**
 * 1.49.0 (Beacon v1.155, REQ-SEC-004): every lookup by identifier goes through the identifier normaliser, on a folding
 * and on a binary identifier column, with the default normaliser and with `lowercase_ascii_email`.
 *
 * The account stores `ada@example.com`; the person types `Ada@Example.COM`. It is found when the column folds (the
 * behaviour before 1.49.0, kept by the default) or when the normaliser lower-cases it (the opt-in), never on a binary
 * column with the default. PostgreSQL compares exactly whatever the "folding" case asks. The flows: password sign-in
 * (form_login + the entity user provider, through `http_kernel` on one booted kernel: FrankenPHP worker mode, kernel
 * reset off), magic login, password reset request and code, social e-mail match, the port QR completion and the MFA
 * admin reset use (with their re-check), admin creation and registration.
 *
 * {@see self::testTheBinaryCollationMigrationRecipe()} runs the SQL of UPGRADING "To 1.49.0" on MySQL / MariaDB /
 * PostgreSQL.
 */
final class IdentifierNormalizerKernelTest extends KernelTestCase
{
    private const STORED = 'ada@example.com';

    private const TYPED = 'Ada@Example.COM';

    private const PASSWORD = 'ada-password-123';

    /**
     * UPGRADING "To 1.49.0" step 1 (MySQL 8.0+ / MariaDB 10.6+): two accounts that the normaliser would make one.
     * Over-reports Unicode case variants on purpose (review them by hand).
     */
    public const RECIPE_COLLISIONS = <<<'SQL'
        SELECT LOWER(email) COLLATE utf8mb4_bin AS normalised, COUNT(*) AS accounts
        FROM %s
        GROUP BY LOWER(email) COLLATE utf8mb4_bin
        HAVING COUNT(*) > 1
        SQL;

    /** UPGRADING step 2: the identifiers not in normalised form (an ASCII capital letter, exactly one `@`). */
    public const RECIPE_DETECT = <<<'SQL'
        SELECT email
        FROM %s
        WHERE email COLLATE utf8mb4_bin REGEXP '[A-Z]'
          AND email LIKE '_%%@_%%' AND email NOT LIKE '%%@%%@%%'
        SQL;

    /** UPGRADING step 3 (SQL quick check): control characters and edge white space. */
    public const RECIPE_UNUSABLE = "SELECT email FROM %s WHERE email REGEXP '[[:cntrl:]]|^[[:space:]]|[[:space:]]$'";

    /** UPGRADING step 4: lower-case the pure-ASCII ones (bytes = characters); the rest is fixed by hand. */
    public const RECIPE_FIX = <<<'SQL'
        UPDATE %s
        SET email = LOWER(email)
        WHERE email COLLATE utf8mb4_bin REGEXP '[A-Z]'
          AND email LIKE '_%%@_%%' AND email NOT LIKE '%%@%%@%%'
          AND CHAR_LENGTH(email) = LENGTH(email)
        SQL;

    /** UPGRADING step 7: the binary NO PAD collation (`utf8mb4_0900_bin` on MySQL 8.0+, `utf8mb4_nopad_bin` on MariaDB). */
    public const RECIPE_ALTER = 'ALTER TABLE %s MODIFY email VARCHAR(180) CHARACTER SET utf8mb4 COLLATE %s NOT NULL';

    /** UPGRADING step 1, PostgreSQL. */
    public const RECIPE_PG_COLLISIONS = <<<'SQL'
        SELECT LOWER(email) AS normalised, COUNT(*) AS accounts
        FROM %s
        GROUP BY LOWER(email)
        HAVING COUNT(*) > 1
        SQL;

    /** UPGRADING step 2, PostgreSQL. */
    public const RECIPE_PG_DETECT = <<<'SQL'
        SELECT email
        FROM %s
        WHERE email ~ '[A-Z]'
          AND email LIKE '_%%@_%%' AND email NOT LIKE '%%@%%@%%'
        SQL;

    /** UPGRADING step 3, PostgreSQL. */
    public const RECIPE_PG_UNUSABLE = "SELECT email FROM %s WHERE email ~ '[[:cntrl:]]|^[[:space:]]|[[:space:]]$'";

    /** UPGRADING step 4, PostgreSQL. */
    public const RECIPE_PG_FIX = <<<'SQL'
        UPDATE %s
        SET email = LOWER(email)
        WHERE email ~ '[A-Z]'
          AND email LIKE '_%%@_%%' AND email NOT LIKE '%%@%%@%%'
          AND CHAR_LENGTH(email) = OCTET_LENGTH(email)
        SQL;

    /** @var array<string, string> cookie name => value of the one browser of the sign-in helper */
    private array $jar = [];

    private int $client = 0;

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'default normaliser, folding column' => ['exact', true];
        yield 'default normaliser, binary column' => ['exact', false];
        yield 'lowercase_ascii_email, folding column' => ['lower', true];
        yield 'lowercase_ascii_email, binary column' => ['lower', false];
    }

    protected static function getKernelClass(): string
    {
        return IdentifierNormalizerTestKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel($options + ['debug' => false]);
    }

    #[DataProvider('cases')]
    public function testPasswordSignIn(string $normalizer, bool $folding): void
    {
        $expected = $this->start($normalizer, $folding);

        self::assertSame($expected, $this->signIn(self::TYPED, self::PASSWORD), 'Typed spelling.');
        self::assertTrue($this->signIn(self::STORED, self::PASSWORD), 'Stored spelling.');
        self::assertFalse($this->signIn("\u{0430}da@example.com", self::PASSWORD), 'A Cyrillic look-alike never signs in.');
    }

    #[DataProvider('cases')]
    public function testMagicLogin(string $normalizer, bool $folding): void
    {
        $expected = $this->start($normalizer, $folding);
        $resolver = $this->service('test.identifier.magic_login', MagicLoginUserResolver::class);

        self::assertSame($expected ? self::STORED : null, $resolver->findByIdentifier(self::TYPED)?->getUserIdentifier());
        self::assertNull($resolver->findByIdentifier(self::STORED . ' '), 'Refused.');
    }

    #[DataProvider('cases')]
    public function testPasswordResetRequest(string $normalizer, bool $folding): void
    {
        $expected = $this->start($normalizer, $folding);

        $user = $this->requestReset(self::TYPED);
        self::assertSame($expected ? self::STORED : null, $user instanceof UserInterface ? $user->getUserIdentifier() : null);
        self::assertNull($this->requestReset("\u{FEFF}" . self::STORED), 'Refused.');
    }

    #[DataProvider('cases')]
    public function testPasswordResetCode(string $normalizer, bool $folding): void
    {
        $expected = $this->start($normalizer, $folding);
        self::assertInstanceOf(KernelUser::class, $this->requestReset(self::STORED));
        $code = $this->notifier()->last?->code();
        self::assertIsString($code);

        $tokens = $this->service('test.identifier.reset_tokens', PasswordResetTokenManagerInterface::class);
        $user   = $tokens->resolveUserByIdentifierAndCode(self::TYPED, $code);
        self::assertSame($expected ? self::STORED : null, $user instanceof UserInterface ? $user->getUserIdentifier() : null);
    }

    #[DataProvider('cases')]
    public function testSocialEmailMatch(string $normalizer, bool $folding): void
    {
        $expected = $this->start($normalizer, $folding);
        $linker   = $this->service('test.identifier.social', SocialAccountLinker::class);
        $profile  = $this->service('test.identifier.profiles', ProfileRegistry::class)->getDefault();

        try {
            $user = $linker->linkOrCreate($profile, 'github', new SocialUserProfile('subject-1', self::TYPED, 'Ada', [], true), ['access_token' => 'placeholder', 'refresh_token' => null, 'expires_in' => null]);
            self::assertTrue($expected, 'Linked only when the typed spelling finds the account.');
            self::assertSame(self::STORED, $user->getUserIdentifier());
        } catch (RuntimeException $exception) {
            self::assertFalse($expected, $exception->getMessage());
            self::assertStringContainsString('No local account matches', $exception->getMessage());
        }
    }

    /**
     * QR completion and the MFA admin reset look the user up through the port, then re-check the stored identifier.
     */
    #[DataProvider('cases')]
    public function testQrCompletionAndMfaAdminLookups(string $normalizer, bool $folding): void
    {
        $expected = $this->start($normalizer, $folding);
        $lookup   = $this->service('test.identifier.user_lookup', UserLookupInterface::class);
        $matcher  = $this->service('test.identifier.matcher', UserIdentifierMatcher::class);
        $profile  = $this->service('test.identifier.profiles', ProfileRegistry::class)->getDefault();

        $user = $lookup->findByIdentifier($profile, self::TYPED);
        self::assertSame($expected, $user instanceof UserInterface && $matcher->matchesUser($user, $profile->userIdentifierField, self::TYPED));
        self::assertNull($lookup->findByIdentifier($profile, "ada\u{202E}@example.com"), 'Refused.');
        self::assertNull($lookup->findByIdentifier($profile, "\u{0430}da@example.com"), 'A Cyrillic look-alike never folds into the account.');
    }

    /**
     * Accounts AuthKit creates store the normalised form, so the binary column finds them by every ASCII spelling.
     */
    #[DataProvider('cases')]
    public function testAdminCreationAndRegistration(string $normalizer, bool $folding): void
    {
        $expected = $this->start($normalizer, $folding);
        $admin    = $this->service('test.identifier.admin', AdminUserCreator::class);
        self::assertSame($expected, $admin->exists(self::TYPED));

        $created = $admin->create('Grace@Example.COM', 'grace-password-1', ['ROLE_ADMIN']);
        $stored  = $normalizer === 'lower' ? 'grace@example.com' : 'Grace@Example.COM';
        self::assertSame($stored, $created->getUserIdentifier());
        $registered = $this->service('test.identifier.registrar', UserRegistrar::class)->register(['email' => 'Linus@Example.COM', 'password' => 'linus-password-1']);
        self::assertSame($normalizer === 'lower' ? 'linus@example.com' : 'Linus@Example.COM', $registered->getUserIdentifier());
        $this->entityManager()->clear();

        self::assertSame($normalizer === 'lower' || $this->folds($folding), $this->signIn('GRACE@example.com', 'grace-password-1'));
        self::assertTrue($this->signIn('Grace@Example.COM', 'grace-password-1'));
    }

    /**
     * Audit OV probe P1a: `lowercase_ascii_email` on an exact column (binary MySQL / MariaDB, PostgreSQL, SQLite) before
     * the rows are fixed: the legacy `Bob@example.com` is found by no spelling. Hence UPGRADING "To 1.49.0": the option
     * goes live in the same deploy as the row fix, never before, and on an exact store the rows are fixed first.
     */
    public function testTheLowercaserLocksAnUnfixedMixedCaseRowOutOfAnExactColumn(): void
    {
        $this->start('lower', false, ['Bob@example.com' => 'bob-legacy-pass-1']);
        $profile = $this->service('test.identifier.profiles', ProfileRegistry::class)->getDefault();

        self::assertNull($this->service('test.identifier.user_lookup', UserLookupInterface::class)->findByIdentifier($profile, 'Bob@example.com'));
        self::assertFalse($this->signIn('Bob@example.com', 'bob-legacy-pass-1'));
    }

    /**
     * Audit OV-M1, probe P1d: with `lowercase_ascii_email`, registering `BOB@Example.com` next to an unfixed
     * `Bob@example.com` on an exact column would store `bob@example.com`, which every later lookup of `Bob@example.com`
     * reaches (pre-hijack). Every creation path refuses it; the default normaliser keeps the 1.48.0 byte-exact behaviour.
     */
    public function testRegistrationNextToAnUnfixedCaseVariantIsRefusedWithTheLowercaser(): void
    {
        $this->start('lower', false, ['Bob@example.com' => 'pass-of-A-123']);
        try {
            $this->service('test.identifier.registrar', UserRegistrar::class)->register(['email' => 'BOB@Example.com', 'password' => 'attacker-pass-1']);
            self::fail('The case variant must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('same identifier (any case) already exists', $exception->getMessage());
        }
        try {
            $this->service('test.identifier.admin', AdminUserCreator::class)->create('bob@example.com', 'attacker-pass-1', []);
            self::fail('The case variant must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('same identifier (any case) already exists', $exception->getMessage());
        }
        $this->entityManager()->clear();
        self::assertSame(1, $this->entityManager()->getRepository(KernelUser::class)->count([]));
        self::assertFalse($this->signIn('Bob@example.com', 'attacker-pass-1'));

        $this->start('exact', false, ['Bob@example.com' => 'pass-of-A-123']);
        $registered = $this->service('test.identifier.registrar', UserRegistrar::class)->register(['email' => 'bob@example.com', 'password' => 'pass-of-B-123']);
        self::assertSame('bob@example.com', $registered->getUserIdentifier(), 'Default normaliser: case variants stay distinct accounts.');
        $this->entityManager()->clear();
        self::assertTrue($this->signIn('Bob@example.com', 'pass-of-A-123'));
    }

    /**
     * REQ-RUNTIME-002: one kernel, no reset, alternating identities and spellings; each sign-in is its own.
     */
    public function testOneWorkerKernelServesAlternatingSpellings(): void
    {
        $this->start('lower', false);
        $this->entityManager()->persist((new KernelUser())->setEmail('grace@example.com')->setPassword('grace-password-1'));
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        for ($i = 0; $i < 20; ++$i) {
            [$typed, $password] = $i % 2 === 0 ? ['ADA@example.com', self::PASSWORD] : ['Grace@EXAMPLE.com', 'grace-password-1'];
            self::assertTrue($this->signIn($typed, $password), sprintf('Round %d', $i));
            self::assertFalse($this->signIn($typed, $i % 2 === 0 ? 'grace-password-1' : self::PASSWORD), sprintf('Round %d: the other password never works.', $i));
        }
    }

    /**
     * UPGRADING "To 1.49.0": the detection queries, the fix, the ALTER (MySQL / MariaDB; PostgreSQL compares exactly
     * already), then sign-in by every ASCII spelling with `lowercase_ascii_email`; accented look-alikes stay apart.
     */
    public function testTheBinaryCollationMigrationRecipe(): void
    {
        $this->start('lower', true, [
            'Ada@Example.COM'   => self::PASSWORD,
            'grace@example.com' => 'grace-password-1',
            'JOSÉ@Example.com'  => 'jose-password-1',
            'AdminUser'         => 'admin-password-1',
            'pad@example.com '  => 'pad-password-1',
            "ctl\t@example.com" => 'ctl-password-1',
        ]);
        $connection = $this->entityManager()->getConnection();
        $platform   = $connection->getDatabasePlatform();
        $mysql      = $platform instanceof AbstractMySQLPlatform;
        if (!$mysql && !$platform instanceof PostgreSQLPlatform) {
            self::markTestSkipped('The recipe is MySQL / MariaDB / PostgreSQL SQL (CI job real-databases).');
        }
        $table                                  = 'kernel_users';
        [$collisions, $detect, $unusable, $fix] = $mysql
            ? [self::RECIPE_COLLISIONS, self::RECIPE_DETECT, self::RECIPE_UNUSABLE, self::RECIPE_FIX]
            : [self::RECIPE_PG_COLLISIONS, self::RECIPE_PG_DETECT, self::RECIPE_PG_UNUSABLE, self::RECIPE_PG_FIX];
        $detected = static fn (): array => array_column($connection->fetchAllAssociative(sprintf($detect, $table)), 'email');

        self::assertSame([], $connection->fetchAllAssociative(sprintf($collisions, $table)));
        self::assertEqualsCanonicalizing(['Ada@Example.COM', 'JOSÉ@Example.com'], $detected());
        self::assertEqualsCanonicalizing(['pad@example.com ', "ctl\t@example.com"], array_column($connection->fetchAllAssociative(sprintf($unusable, $table)), 'email'));

        self::assertSame(1, (int) $connection->executeStatement(sprintf($fix, $table)));
        self::assertSame(['JOSÉ@Example.com'], $detected(), 'Non-ASCII rows are left for a manual fix.');
        $connection->executeStatement(sprintf('UPDATE %s SET email = ? WHERE email = ?', $table), [(new LowercaseAsciiEmailIdentifierNormalizer())->normalize('JOSÉ@Example.com'), 'JOSÉ@Example.com']);
        self::assertSame([], $detected());

        if ($mysql) {
            $connection->executeStatement(sprintf(self::RECIPE_ALTER, $table, $platform instanceof MariaDBPlatform ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin'));
        }

        self::assertTrue($this->signIn('ADA@example.com', self::PASSWORD));
        self::assertTrue($this->signIn('ada@example.com', self::PASSWORD));
        self::assertTrue($this->signIn('Grace@Example.com', 'grace-password-1'));
        self::assertTrue($this->signIn('JOSÉ@EXAMPLE.COM', 'jose-password-1'));
        self::assertFalse($this->signIn('josé@example.com', 'jose-password-1'), 'Binary: the accented look-alike is another identifier.');
        self::assertTrue($this->signIn('AdminUser', 'admin-password-1'));
        self::assertFalse($this->signIn('adminuser', 'admin-password-1'), 'Not an e-mail: never lower-cased.');

        // On a binary column, two ASCII case variants are two rows: the collision query lists them.
        $connection->insert($table, ['email' => 'GRACE@example.com', 'password' => 'x', 'roles' => '[]', 'newsletter' => 0], ['newsletter' => ParameterType::BOOLEAN]);
        self::assertSame([['normalised' => 'grace@example.com', 'accounts' => 2]], array_map(
            static fn (array $row): array => ['normalised' => (string) $row['normalised'], 'accounts' => (int) $row['accounts']],
            $connection->fetchAllAssociative(sprintf($collisions, $table)),
        ));
    }

    /**
     * Boots the kernel of the normaliser, builds the schema with the column's collation and stores the users.
     *
     * @param array<string, string> $users identifier => password
     *
     * @return bool whether {@see self::TYPED} must find {@see self::STORED}
     */
    private function start(string $normalizer, bool $folding, array $users = [self::STORED => self::PASSWORD]): bool
    {
        self::bootKernel(['environment' => 'norm_' . $normalizer]);
        $em        = $this->entityManager();
        $platform  = $em->getConnection()->getDatabasePlatform();
        $collation = match (true) {
            $platform instanceof AbstractMySQLPlatform => $folding ? 'utf8mb4_unicode_ci' : ($platform instanceof MariaDBPlatform ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin'),
            $platform instanceof SQLitePlatform        => $folding ? 'NOCASE' : null,
            default                                    => null,
        };
        $schema = new SchemaTool($em);
        $all    = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($all);
        if ($collation !== null) {
            $em->getEventManager()->addEventListener(ToolEvents::postGenerateSchemaTable, new UserEmailCollationListener($collation));
        }
        $schema->createSchema($all);
        foreach ($users as $email => $password) {
            $em->persist((new KernelUser())->setEmail($email)->setPassword($password));
        }
        $em->flush();
        $em->clear();
        $this->jar = [];

        return $normalizer === 'lower' || $this->folds($folding);
    }

    private function folds(bool $folding): bool
    {
        return $folding && !$this->entityManager()->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    /** Signs in from a new browser through `http_kernel` (no kernel reboot); whether it reached the target page. */
    private function signIn(string $identifier, string $password): bool
    {
        $http      = $this->service('http_kernel', HttpKernelInterface::class);
        $this->jar = [];
        $page      = $this->send($http, Request::create('/login'));
        self::assertSame(200, $page->getStatusCode());
        $csrf  = (new Crawler((string) $page->getContent()))->filter('input[name="login_form[_csrf_token]"]')->attr('value');
        $login = $this->send($http, Request::create('/login', 'POST', ['login_form' => ['_username' => $identifier, '_password' => $password, '_csrf_token' => $csrf]], [], [], ['REMOTE_ADDR' => $this->clientAddress()]));
        self::assertSame(302, $login->getStatusCode());

        return str_ends_with((string) $login->headers->get('Location'), '/home');
    }

    /** The user the reset request was issued for (null: none), from a fresh client address. */
    private function requestReset(string $identifier): ?object
    {
        $notifier       = $this->notifier();
        $notifier->last = null;
        $stack          = $this->service('request_stack', RequestStack::class);
        $stack->push(Request::create('/reset-password', 'POST', [], [], [], ['REMOTE_ADDR' => $this->clientAddress()]));
        try {
            $this->service('test.identifier.reset_request', PasswordResetRequestHandler::class)->handle($identifier);
        } finally {
            $stack->pop();
        }

        return $this->lastResetUser($notifier);
    }

    private function lastResetUser(CapturingPasswordResetNotifier $notifier): ?object
    {
        return $notifier->last?->user;
    }

    private function clientAddress(): string
    {
        ++$this->client;

        return sprintf('10.%d.%d.%d', random_int(0, 255), intdiv($this->client, 250), $this->client % 250 + 1);
    }

    private function send(HttpKernelInterface $http, Request $request): Response
    {
        foreach ($this->jar as $name => $value) {
            $request->cookies->set($name, $value);
        }
        $response = $http->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->jar[$cookie->getName()]);
                continue;
            }
            $this->jar[$cookie->getName()] = (string) $cookie->getValue();
        }
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }

        return $response;
    }

    private function notifier(): CapturingPasswordResetNotifier
    {
        return $this->service(CapturingPasswordResetNotifier::class, CapturingPasswordResetNotifier::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->service('doctrine.orm.entity_manager', EntityManagerInterface::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $id, string $class): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
