<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use Nowo\AuthKitBundle\Enum\QrLoginDesktopBinding;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginApprovedEvent;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginChallengeCreatedEvent;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginCompletedEvent;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginDeniedEvent;
use Nowo\AuthKitBundle\Repository\QrLoginChallengeRepository;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function bin2hex;
use function chr;
use function hash;
use function hash_equals;
use function is_string;
use function ord;
use function random_bytes;
use function strtoupper;
use function substr;

/**
 * Creates, verifies, and transitions QR login challenges.
 */
class QrLoginChallengeManager
{
    public const DESKTOP_COOKIE_NAME = 'ak_qr_desk';

    public function __construct(
        private readonly QrLoginChallengeRepository $repository,
        private readonly ProfileRegistry $profileRegistry,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly string $kernelSecret,
    ) {
    }

    /**
     * @return array{challenge: QrLoginChallenge, cookie_value: string, approve_token: string}
     */
    public function create(Request $request, ?string $profileName = null): array
    {
        $profile      = $this->resolveProfile($profileName);
        $ttl          = (int) ($profile->qrLogin['challenge_ttl'] ?? 90);
        $id           = $this->generateUuid();
        $publicCode   = $this->generatePublicCode();
        $cookieValue  = bin2hex(random_bytes(32));
        $approveToken = bin2hex(random_bytes(32));

        $challenge = new QrLoginChallenge(
            id: $id,
            publicCode: $publicCode,
            desktopCookieHash: $this->hashValue($cookieValue),
            desktopIpHash: $this->hashHmac($request->getClientIp() ?? 'unknown'),
            desktopUaHash: $this->hashHmac($request->headers->get('User-Agent') ?? ''),
            desktopUaLabel: $this->buildUaLabel($request->headers->get('User-Agent') ?? ''),
            approveTokenHash: $this->hashValue($approveToken),
            expiresAt: new DateTimeImmutable("+{$ttl} seconds"),
        );

        $this->repository->save($challenge);
        $this->eventDispatcher->dispatch(new QrLoginChallengeCreatedEvent($challenge, $profile->name));

        return [
            'challenge'     => $challenge,
            'cookie_value'  => $cookieValue,
            'approve_token' => $approveToken,
        ];
    }

    public function find(string $id): ?QrLoginChallenge
    {
        return $this->repository->find($id);
    }

    public function createDesktopCookie(string $cookieValue, int $ttl): Cookie
    {
        return Cookie::create(self::DESKTOP_COOKIE_NAME)
            ->withValue($cookieValue)
            ->withExpires(new DateTimeImmutable("+{$ttl} seconds"))
            ->withPath('/')
            ->withHttpOnly(true)
            ->withSecure(true)
            ->withSameSite('strict');
    }

    public function verifyDesktopCookie(QrLoginChallenge $challenge, Request $request): bool
    {
        $cookie = $request->cookies->get(self::DESKTOP_COOKIE_NAME);
        if (!is_string($cookie) || $cookie === '') {
            return false;
        }

        return hash_equals($challenge->getDesktopCookieHash(), $this->hashValue($cookie));
    }

    public function verifyDesktopBinding(QrLoginChallenge $challenge, Request $request, ?string $profileName = null): bool
    {
        $profile = $this->resolveProfile($profileName);
        $binding = QrLoginDesktopBinding::from($profile->qrLogin['desktop_binding'] ?? 'strict');

        if ($binding === QrLoginDesktopBinding::Off) {
            return true;
        }

        $ipMatch = hash_equals(
            $challenge->getDesktopIpHash(),
            $this->hashHmac($request->getClientIp() ?? 'unknown'),
        );
        $uaMatch = hash_equals(
            $challenge->getDesktopUaHash(),
            $this->hashHmac($request->headers->get('User-Agent') ?? ''),
        );

        if ($binding === QrLoginDesktopBinding::Strict) {
            return $ipMatch && $uaMatch;
        }

        return true;
    }

    public function verifyApproveToken(QrLoginChallenge $challenge, string $token): bool
    {
        if ($challenge->getApproveTokenUsedAt() instanceof DateTimeImmutable) {
            return false;
        }

        return hash_equals($challenge->getApproveTokenHash(), $this->hashValue($token));
    }

    public function approve(
        QrLoginChallenge $challenge,
        UserInterface $user,
        ?string $phoneHint,
        ?string $profileName = null,
    ): void {
        $profile = $this->resolveProfile($profileName);

        $challenge->markApproved(
            userClass: $user::class,
            userId: $user->getUserIdentifier(),
            phoneHint: $phoneHint,
        );

        $this->repository->save($challenge);
        $this->eventDispatcher->dispatch(new QrLoginApprovedEvent($challenge, $user, $profile->name));
    }

    public function deny(QrLoginChallenge $challenge, ?string $profileName = null): void
    {
        $profile = $this->resolveProfile($profileName);
        $challenge->markDenied();
        $this->repository->save($challenge);
        $this->eventDispatcher->dispatch(new QrLoginDeniedEvent($challenge, $profile->name));
    }

    public function consume(QrLoginChallenge $challenge, UserInterface $user, ?string $profileName = null): void
    {
        $profile = $this->resolveProfile($profileName);
        $challenge->markConsumed();
        $this->repository->save($challenge);
        $this->eventDispatcher->dispatch(new QrLoginCompletedEvent($challenge, $user, $profile->name));
    }

    public function isExpiredOrInvalid(QrLoginChallenge $challenge): bool
    {
        if ($challenge->isExpired() && $challenge->getStatus() === QrLoginChallengeStatus::Pending) {
            $challenge->markExpired();
            $this->repository->save($challenge);

            return true;
        }

        return $challenge->getStatus() === QrLoginChallengeStatus::Expired;
    }

    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function generatePublicCode(): string
    {
        return strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    private function hashValue(string $value): string
    {
        return hash('sha256', $value);
    }

    private function hashHmac(string $value): string
    {
        return hash_hmac('sha256', $value, $this->kernelSecret);
    }

    private function buildUaLabel(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Unknown browser';
        }

        $browser = 'Browser';
        $os      = '';

        if (str_contains($userAgent, 'Firefox')) {
            $browser = 'Firefox';
        } elseif (str_contains($userAgent, 'Edg')) {
            $browser = 'Edge';
        } elseif (str_contains($userAgent, 'Chrome')) {
            $browser = 'Chrome';
        } elseif (str_contains($userAgent, 'Safari')) {
            $browser = 'Safari';
        }

        if (str_contains($userAgent, 'Android')) {
            $os = 'Android';
        } elseif (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) {
            $os = 'iOS';
        } elseif (str_contains($userAgent, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains($userAgent, 'Mac OS')) {
            $os = 'macOS';
        } elseif (str_contains($userAgent, 'Linux')) {
            $os = 'Linux';
        }

        return $os !== '' ? "{$browser} · {$os}" : $browser;
    }

    private function resolveProfile(?string $profileName): ProfileSettings
    {
        return $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();
    }
}
