<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use Nowo\AuthKitBundle\DependencyInjection\InvitationsConfiguration;
use Nowo\AuthKitBundle\Locale\RecipientLocale;
use Nowo\AuthKitBundle\NowoAuthKitBundle;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use Twig\Environment;

use function is_string;

/**
 * Sends the invitation through Symfony Mailer: subject `invitation.email.subject` (NowoAuthKitBundle domain), HTML and
 * text bodies from `invitations.mail.html_template` / `text_template`, sender `invitations.mail.from` (null: the
 * mailer default), in the invitation locale. A transport error is logged (public id only, never the link) and
 * reported as "not delivered".
 */
final readonly class MailerInvitationMailer implements InvitationMailerInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private TranslatorInterface $translator,
        private ProfileRegistry $profiles,
        private RecipientLocale $recipientLocale = new RecipientLocale(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function send(InvitationMail $mail): bool
    {
        $invitation = $mail->invitation;
        $profile    = $this->profiles->getByName($invitation->profile);
        /** @var array{from?: ?string, html_template?: string, text_template?: string} $settings */
        $settings = $profile->invitations['mail'] ?? [];
        $brand    = $profile->webUi['brand'] ?? null;
        $context  = [
            'invitation' => $invitation,
            'accept_url' => $mail->acceptUrl,
            'resend'     => $mail->resend,
            'brand'      => is_string($brand) ? $brand : '',
        ];

        try {
            $email = $this->recipientLocale->run($invitation->locale, fn (): Email => $this->compose($invitation, $settings, $context));
            $this->mailer->send($email);

            return true;
        } catch (Throwable $exception) {
            $this->logger->warning('AuthKit invitation e-mail not sent.', ['invitation' => $invitation->publicId, 'exception' => $exception::class]);

            return false;
        }
    }

    /**
     * @param array{from?: ?string, html_template?: string, text_template?: string} $settings
     * @param array<string, mixed> $context
     */
    private function compose(InvitationSnapshot $invitation, array $settings, array $context): Email
    {
        $subject = $this->translator->trans('invitation.email.subject', [
            '%brand%'   => $context['brand'],
            '%inviter%' => $invitation->invitedBy ?? '',
        ], NowoAuthKitBundle::TRANSLATION_DOMAIN, $invitation->locale);

        $email = (new Email())
            ->to(new Address($invitation->email))
            ->subject($subject)
            ->html($this->twig->render($settings['html_template'] ?? InvitationsConfiguration::MAIL_HTML_TEMPLATE, $context))
            ->text($this->twig->render($settings['text_template'] ?? InvitationsConfiguration::MAIL_TEXT_TEMPLATE, $context));

        $from = $settings['from'] ?? null;
        if (is_string($from) && $from !== '') {
            $email->from(Address::create($from));
        }

        return $email;
    }
}
