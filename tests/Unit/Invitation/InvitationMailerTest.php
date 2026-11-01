<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Invitation;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\InvitationMail;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\Invitation\MailerInvitationMailer;
use Nowo\AuthKitBundle\Invitation\NullInvitationMailer;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

use function dirname;

use const JSON_THROW_ON_ERROR;

/**
 * The Symfony Mailer delivery: translated subject, HTML and text bodies from the configured templates (no token hash
 * in the context), optional sender, invitation locale; a transport error is logged without the link and reported as
 * not delivered.
 */
final class InvitationMailerTest extends TestCase
{
    public function testSendsTheTranslatedInvitation(): void
    {
        $mailer = new class implements MailerInterface {
            public ?RawMessage $message = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->message = $message;
            }
        };

        self::assertTrue($this->mailer($mailer, ['from' => 'noreply@example.com'])->send($this->mail('es')));

        $email = $mailer->message;
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Te invitan a unirte a Acme', $email->getSubject());
        self::assertSame('noreply@example.com', $email->getFrom()[0]->getAddress());
        self::assertSame('ana@example.com', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('href="https://app.example.com/invitation/tok"', (string) $email->getHtmlBody());
        self::assertStringContainsString('admin@example.com te ha invitado', (string) $email->getHtmlBody());
        self::assertStringContainsString('Hola &lt;b&gt;', (string) $email->getHtmlBody(), 'The message is escaped.');
        self::assertStringContainsString('https://app.example.com/invitation/tok', (string) $email->getTextBody());
        self::assertStringNotContainsString('stored-hash', $email->getHtmlBody() . $email->getTextBody());
    }

    public function testTransportErrorsAreLoggedWithoutTheLink(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new RuntimeException('SMTP down: https://app.example.com/invitation/tok'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'AuthKit invitation e-mail not sent.',
            self::callback(static fn (array $context): bool => !str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'invitation/tok')),
        );

        self::assertFalse($this->mailer($mailer, [], $logger)->send($this->mail(null)));
    }

    public function testNullMailerNeverSends(): void
    {
        self::assertFalse((new NullInvitationMailer())->send($this->mail(null)));
    }

    /**
     * @param array<string, mixed> $mail
     */
    private function mailer(MailerInterface $mailer, array $mail, ?LoggerInterface $logger = null): MailerInvitationMailer
    {
        $views  = dirname(__DIR__, 3) . '/src/Resources/views';
        $loader = new FilesystemLoader();
        $loader->addPath($views, 'NowoAuthKitBundle');
        $twig       = new Environment($loader, ['strict_variables' => true]);
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['en', 'es'] as $locale) {
            $translator->addResource('yaml', dirname(__DIR__, 3) . '/src/Resources/translations/NowoAuthKitBundle.' . $locale . '.yaml', $locale, 'NowoAuthKitBundle');
        }
        $twig->addExtension(new class($translator) extends AbstractExtension {
            public function __construct(private readonly Translator $translator)
            {
            }

            public function getFilters(): array
            {
                return [new TwigFilter('trans', fn (string $key, array $params = [], ?string $domain = null): string => $this->translator->trans($key, $params, $domain, 'es'))];
            }
        });
        $registry = ProfileRegistryFactory::single(TestUser::class, ['web_ui' => ['brand' => 'Acme'], 'invitations' => ['mode' => 'enabled', 'mail' => $mail]]);

        return $logger instanceof LoggerInterface
            ? new MailerInvitationMailer($mailer, $twig, $translator, $registry, logger: $logger)
            : new MailerInvitationMailer($mailer, $twig, $translator, $registry);
    }

    private function mail(?string $locale): InvitationMail
    {
        $invitation = (new Invitation('default', 'ana@example.com', 'admin@example.com', 7, new DateTimeImmutable('2026-11-01')))
            ->setMessage('Hola <b>Ana</b>')
            ->setLocale($locale);
        $invitation->issue('stored-hash', 7, new DateTimeImmutable('2026-11-01'));

        return new InvitationMail(InvitationSnapshot::of($invitation, new DateTimeImmutable('2026-11-01')), 'https://app.example.com/invitation/tok');
    }
}
