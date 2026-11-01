<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Invitation;

use Nowo\AuthKitBundle\Config\FieldConfigNormalizer;
use Nowo\AuthKitBundle\DependencyInjection\InvitationsConfiguration;
use Nowo\AuthKitBundle\Invitation\Ui\InvitationAdminPages;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Nowo\UiKitBundle\View\Label;
use PHPUnit\Framework\TestCase;

/**
 * Profile helpers of the invitations (e-mail field resolution, enabled flag), the administration templates and CSRF
 * token ids (bound to the action and the row) and the form-less auth notice page.
 */
final class InvitationPagesTest extends TestCase
{
    public function testEmailFieldResolution(): void
    {
        $fields = FieldConfigNormalizer::normalizeRegistrationFields(['login', ['name' => 'work_email', 'type' => 'email', 'property' => 'email'], 'email', 'password']);

        $byProperty = ProfileRegistryFactory::single(TestUser::class, ['registration_fields' => $fields])->getDefault();
        self::assertSame('work_email', $byProperty->invitationEmailField(), 'The field mapped to user_identifier_field wins.');
        self::assertFalse($byProperty->invitationsEnabled());

        $configured = ProfileRegistryFactory::single(TestUser::class, ['registration_fields' => $fields, 'invitations' => ['mode' => 'enabled', 'email_field' => 'login']])->getDefault();
        self::assertSame('login', $configured->invitationEmailField());
        self::assertTrue($configured->invitationsEnabled());

        $byName = ProfileRegistryFactory::single(TestUser::class, ['user_identifier_field' => 'username', 'registration_fields' => FieldConfigNormalizer::normalizeRegistrationFields(['email', 'password'])])->getDefault();
        self::assertSame('email', $byName->invitationEmailField());
    }

    public function testTemplatesAndTokenIds(): void
    {
        $default  = ProfileRegistryFactory::single(TestUser::class, ['invitations' => ['admin' => ['templates' => ['index' => '']]]])->getDefault();
        $override = ProfileRegistryFactory::single(TestUser::class, ['invitations' => ['admin' => ['templates' => ['index' => 'admin/invitations.html.twig']]]])->getDefault();

        self::assertSame(InvitationsConfiguration::ADMIN_TEMPLATES['index'], InvitationAdminPages::template($default, 'index'));
        self::assertSame(InvitationsConfiguration::ADMIN_TEMPLATES['link'], InvitationAdminPages::template($default, 'link'));
        self::assertSame('admin/invitations.html.twig', InvitationAdminPages::template($override, 'index'));
        self::assertSame('nowo_auth_kit_invitation_resend/default/abc', InvitationAdminPages::resendTokenId('default', 'abc'));
        self::assertSame('nowo_auth_kit_invitation_revoke/default/abc', InvitationAdminPages::revokeTokenId('default', 'abc'));
        self::assertSame('NowoAuthKitBundle', InvitationAdminPages::label('x')->domain);
    }

    public function testNoticePage(): void
    {
        $profile = ProfileRegistryFactory::single(TestUser::class, ['web_ui' => ['brand' => 'Acme']])->getDefault();
        $page    = (new AuthPageFactory())->notice('invitation_invalid', $profile, AuthPageFactory::label('invitation.page.expired_title'), null, [], ['data-x' => 'y']);

        self::assertNull($page->form);
        self::assertSame('Acme', $page->brand);
        self::assertSame('invitation.page.expired_title', $page->title instanceof Label ? $page->title->key : null);
        self::assertSame('y', $page->attributes['data-x']);
        self::assertSame('auth-kit-invitation_invalid', $page->attributes['data-testid']);

        $plain = (new AuthPageFactory())->notice('invitation_invalid', ProfileRegistryFactory::single(TestUser::class)->getDefault(), AuthPageFactory::label('t'), null);
        self::assertNull($plain->brand);
    }
}
