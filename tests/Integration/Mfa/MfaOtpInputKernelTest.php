<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\AuthKitBundle\Mfa\Event\MfaChallengeFailedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaChallengePassedEvent;
use Nowo\AuthKitBundle\Mfa\Event\MfaRecoveryCodeUsedEvent;
use Nowo\AuthKitBundle\Mfa\Form\MfaCodeFormType;
use Nowo\OtpInputBundle\Form\OtpType;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function str_replace;

/**
 * The MFA code on nowo-tech/otp-input-bundle (1.51, otp_input.mfa_code): challenge, recovery switch, forced setup and
 * the account page through the real kernel; the server checks see the same submitted value as the text field.
 */
final class MfaOtpInputKernelTest extends MfaKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return MfaOtpInputTestKernel::class;
    }

    public function testTheChallengeTakesTheCodeOnOtpBoxes(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [$secret] = $this->enrol();
        $this->client->request('GET', '/logout');
        $this->signIn('member@example.com', 'member-password-123');
        self::assertResponseRedirects('/login/mfa');

        $crawler = $this->client->request('GET', '/login/mfa');
        self::assertResponseIsSuccessful();
        $group = $crawler->filter('form[name="mfa_code_form"] [data-testid="auth-kit-mfa-otp"]');
        self::assertCount(1, $group);
        self::assertSame('group', $group->attr('role'));
        self::assertSame('mfa_code_form_code_label', $group->attr('aria-labelledby'), 'The boxes are named by the visible label.');
        self::assertSame('mfa_code_form_code_help', $group->attr('aria-describedby'));
        self::assertCount(1, $crawler->filter('label#mfa_code_form_code_label'));
        self::assertSame('1', $group->attr('data-nowo-auth-kit-otp-autosubmit'));
        self::assertSame('6', $group->attr('data-nowo-auth-kit-otp-length'));
        $digits = $group->filter('[data-nowo-otp-digit]');
        self::assertCount(6, $digits);
        self::assertSame('one-time-code', $digits->first()->attr('autocomplete'));
        self::assertSame('numeric', $digits->first()->attr('inputmode'));
        self::assertNull($digits->first()->attr('name'), 'Only the hidden input is submitted.');
        self::assertSame('Digit 1 of 6', $digits->first()->attr('aria-label'));
        self::assertSame('Digit 6 of 6', $digits->last()->attr('aria-label'));
        self::assertNull($group->filter('[role="group"][aria-label]')->getNode(0), 'One name for the field: no generic OTP label.');
        // Audit QV-M1: the submitted input is a visible text input until otp-input.js binds the boxes (hidden until then).
        $text = $group->filter('input[name="mfa_code_form[code]"]');
        self::assertCount(1, $text);
        self::assertSame('text', $text->attr('type'));
        self::assertSame('numeric', $text->attr('inputmode'));
        self::assertSame('one-time-code', $text->attr('autocomplete'));
        self::assertSame('6', $text->attr('maxlength'));
        self::assertSame('mfa_code_form_code', $text->attr('id'), 'The visible label points at it.');
        self::assertStringContainsString('nowo-auth-kit-mfa-otp__text', (string) $text->attr('class'));
        self::assertNull($text->attr('required'));
        self::assertNotNull($group->filter('[data-nowo-otp-render-target]')->attr('hidden'), 'The boxes wait for the script.');
        self::assertStringContainsString('The 6-digit code from your authenticator app.', $crawler->filter('#mfa_code_form_code_help')->text());
        self::assertSame('text', $crawler->filter('details[data-testid="auth-kit-mfa-recovery"] input[name="mfa_code_form[recovery_code]"]')->attr('type'), 'The recovery code stays a text field.');
        $scripts = $crawler->filter('script[src]')->each(static fn (Crawler $s): string => (string) $s->attr('src'));
        self::assertContains('/bundles/nowootpinput/otp-input.js', $scripts);
        self::assertContains('/bundles/nowoauthkit/js/nowo-auth-kit-otp.js', $scripts);

        // A malformed code shows its error on the field, described on the boxes.
        $page = $this->challenge(['code' => '1234567']);
        self::assertResponseIsSuccessful();
        $group = $page->filter('[data-testid="auth-kit-mfa-otp"]');
        self::assertSame('mfa_code_form_code_help mfa_code_form_code_error1', $group->attr('aria-describedby'));
        self::assertStringContainsString('nowo-auth-kit-mfa-otp--invalid', (string) $group->attr('class'));
        self::assertSame('true', $group->filter('input[name="mfa_code_form[code]"]')->attr('aria-invalid'));
        self::assertSame('1234567', $group->filter('input[name="mfa_code_form[code]"]')->attr('value'), 'The typed value is kept.');
        self::assertCount(1, $page->filter('#mfa_code_form_code_error1'));
        self::assertCount(0, $this->eventsOf(MfaChallengeFailedEvent::class), 'The 7-digit value is not cut to 6 digits.');

        $this->challenge(['code' => '000000']);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->eventsOf(MfaChallengeFailedEvent::class));

        $this->challenge(['code' => $this->code($secret)]);
        self::assertResponseStatusCodeSame(303);
        $this->client->request('GET', '/mfa-home');
        self::assertSame('member@example.com|' . UsernamePasswordToken::class, $this->client->getResponse()->getContent());
        self::assertSame('totp', $this->eventsOf(MfaChallengePassedEvent::class)[0]->method);
    }

    /**
     * Audit QV-M1: without JavaScript the browser posts only the visible text input (the boxes have no name).
     */
    public function testWithoutJavaScriptThePlainFieldSignsIn(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [$secret] = $this->enrol();
        $this->client->request('GET', '/logout');
        $this->signIn('member@example.com', 'member-password-123');

        $crawler = $this->client->request('GET', '/login/mfa');
        $form    = $crawler->filter('form[name="mfa_code_form"]')->form();
        $values  = $form->getPhpValues();
        self::assertSame(['code' => '', 'recovery_code' => '', '_token' => $values['mfa_code_form']['_token']], $values['mfa_code_form'], 'Only named fields are posted: the text input, the recovery code, the token.');
        $values['mfa_code_form']['code'] = $this->code($secret);
        $this->client->request('POST', '/login/mfa', $values);
        self::assertResponseStatusCodeSame(303);
        $this->client->request('GET', '/mfa-home');
        self::assertStringStartsWith('member@example.com|', (string) $this->client->getResponse()->getContent());
    }

    public function testTheRecoveryCodeStillSignsIn(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        [, $codes] = $this->enrol();
        $this->client->request('GET', '/logout');
        $this->signIn('member@example.com', 'member-password-123');

        // No JavaScript needed: the switch is a native <details> and the boxes are not required with it.
        $crawler = $this->client->request('GET', '/login/mfa');
        self::assertNull($crawler->filter('input[name="mfa_code_form[code]"]')->attr('required'));
        $this->challenge(['recovery_code' => $codes[0]]);
        self::assertResponseStatusCodeSame(303);
        self::assertCount(1, $this->eventsOf(MfaRecoveryCodeUsedEvent::class));
        self::assertSame('recovery', $this->eventsOf(MfaChallengePassedEvent::class)[0]->method);
    }

    public function testTheForcedSetupConfirmsOnOtpBoxes(): void
    {
        $this->signIn('admin@example.com', 'admin-password-123');
        $this->client->request('GET', '/logout');
        $this->clock()->modify('+8 days');
        $this->signIn('admin@example.com', 'admin-password-123');
        self::assertResponseRedirects('/login/mfa/setup');

        $crawler = $this->client->request('GET', '/login/mfa/setup');
        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('form[name="mfa_code_form"] [data-testid="auth-kit-mfa-otp"] [data-nowo-otp-digit]'));
        self::assertCount(0, $crawler->filter('input[name="mfa_code_form[recovery_code]"]'));
        self::assertSame('1', $crawler->filter('[data-testid="auth-kit-mfa-otp"]')->attr('data-nowo-auth-kit-otp-autosubmit'));
        self::assertSame('text', $crawler->filter('input[name="mfa_code_form[code]"]')->attr('type'));
        self::assertNull($crawler->filter('input[name="mfa_code_form[code]"]')->attr('required'), 'A hidden required input would block the submit.');
        $secret = str_replace(' ', '', $crawler->filter('[data-testid="auth-kit-mfa-secret"]')->text());

        $this->client->submit($crawler->filter('form[name="mfa_code_form"]')->form(['mfa_code_form[code]' => '123456']));
        self::assertResponseStatusCodeSame(422);

        $crawler = $this->client->request('GET', '/login/mfa/setup');
        $page    = $this->client->submit($crawler->filter('form[name="mfa_code_form"]')->form(['mfa_code_form[code]' => $this->code($secret)]));
        self::assertResponseIsSuccessful();
        self::assertCount(10, $page->filter('[data-testid="auth-kit-mfa-codes"] li'));
    }

    public function testTheAccountPageLoadsTheOtpAssets(): void
    {
        $this->signIn('member@example.com', 'member-password-123');
        $crawler = $this->client->request('GET', '/account/security/mfa');
        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('form[name="mfa_enable"] [data-nowo-otp-digit]'));
        self::assertSame('mfa_enable_code_label', $crawler->filter('form[name="mfa_enable"] [data-testid="auth-kit-mfa-otp"]')->attr('aria-labelledby'));
        $scripts = $crawler->filter('script[src]')->each(static fn (Crawler $s): string => (string) $s->attr('src'));
        self::assertContains('/bundles/nowootpinput/otp-input.js', $scripts);
        self::assertCount(1, $crawler->filter('link[href="/bundles/nowoauthkit/css/nowo-auth-kit.css"]'));

        self::assertNull($crawler->filter('form[name="mfa_enable"] [data-testid="auth-kit-mfa-otp"]')->attr('data-nowo-auth-kit-otp-autosubmit'));
        self::assertCount(0, $crawler->filter('[autofocus]'), 'Audit QV-L3: no autofocus on the account page.');

        $this->enrol();
        $crawler = $this->client->request('GET', '/account/security/mfa');
        self::assertCount(1, $crawler->filter('form[name="mfa_disable"] [data-testid="auth-kit-mfa-otp"]'));
        self::assertCount(0, $crawler->filter('[data-nowo-auth-kit-otp-autosubmit]'), 'Audit QV-L2: disable / regenerate never submit themselves.');
        self::assertCount(0, $crawler->filter('[autofocus]'));
        self::assertSame('text', $crawler->filter('form[name="mfa_disable"] input[name="mfa_disable[recovery_code]"]')->attr('type'));
    }

    /**
     * FrankenPHP worker mode with kernel reset false: one kernel, one shared form type, alternating options never leak.
     */
    public function testTheSharedFormTypeKeepsNoStateBetweenRequests(): void
    {
        $factory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $factory);
        $on  = ['enabled' => true, 'password_reset_code' => true, 'mfa_code' => true, 'mfa_code_autosubmit' => false];
        $off = ['enabled' => false, 'password_reset_code' => true, 'mfa_code' => true, 'mfa_code_autosubmit' => false];

        foreach ([$on, $off, null, $on, $off] as $round => $otpInput) {
            $form  = $factory->create(MfaCodeFormType::class, null, ['otp_input' => $otpInput, 'digits' => $round === 3 ? 8 : 6, 'csrf_protection' => false]);
            $inner = $form->get('code')->getConfig()->getType()->getInnerType();
            if ($otpInput === $on) {
                self::assertInstanceOf(OtpType::class, $inner);
                self::assertSame($round === 3 ? 8 : 6, $form->get('code')->getConfig()->getOption('length'));
                self::assertFalse($form->createView()['code']->vars['mfa_otp_autosubmit']);
            } else {
                self::assertInstanceOf(TextType::class, $inner);
                self::assertArrayNotHasKey('id', $form->createView()['code']->vars['label_attr']);
            }
        }

        // The same kernel then serves a sign-in.
        $this->signIn('member@example.com', 'member-password-123');
        self::assertResponseRedirects('/home');
    }
}
