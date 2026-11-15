<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin;

use DateInterval;
use DateTimeImmutable;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
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
use Psr\Clock\ClockInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function bin2hex;
use function chr;
use function hash;
use function hash_equals;
use function is_string;
use function max;
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

    /** 1.50.0: public codes drawn for one challenge before giving up. */
    public const MAX_CODE_ATTEMPTS = 5;

    // 1.50.0: the EntityManagerRecovery argument is kept for compatibility (closed managers are replaced at the boundary).
    // @phpstan-ignore constructor.unusedParameter
    public function __construct(
        private readonly QrLoginChallengeRepository $repository,
        private readonly ProfileRegistry $profileRegistry,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly string $kernelSecret,
        EntityManagerRecovery $entityManagerRecovery = new EntityManagerRecovery(),
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    /**
     * @return array{challenge: QrLoginChallenge, cookie_value: string, approve_token: string}
     */
    public function create(Request $request, ?string $profileName = null): array
    {
        $profile      = $this->resolveProfile($profileName);
        $ttl          = (int) ($profile->qrLogin['challenge_ttl'] ?? 90);
        $cookieValue  = bin2hex(random_bytes(32));
        $approveToken = bin2hex(random_bytes(32));

        // 1.50.0 (Beacon spec 239): the public code is short (8 hex digits), so a taken one is drawn again (written in a
        // savepoint: a taken code never closes the entity manager nor answers 500).
        $attempt = 0;
        do {
            if (++$attempt > self::MAX_CODE_ATTEMPTS) {
                throw new RuntimeException('No free QR login public code was found; try again.');
            }
            $challenge = new QrLoginChallenge(
                id: $this->generateUuid(),
                publicCode: $this->generatePublicCode(),
                desktopCookieHash: $this->desktopCookieHash($cookieValue, $profile->name),
                desktopIpHash: $this->hashHmac($request->getClientIp() ?? 'unknown'),
                desktopUaHash: $this->hashHmac($request->headers->get('User-Agent') ?? ''),
                desktopUaLabel: $this->buildUaLabel($request->headers->get('User-Agent') ?? ''),
                approveTokenHash: $this->hashValue($approveToken),
                expiresAt: $this->now()->add(new DateInterval("PT{$ttl}S")),
            );
        } while (!$this->repository->insertNew($challenge));
        // The managed row: later writes of the request go through the unit of work.
        $challenge = $this->repository->findFresh($challenge->getId()) ?? $challenge;
        $this->eventDispatcher->dispatch(new QrLoginChallengeCreatedEvent($challenge, $profile->name));

        return [
            'challenge'     => $challenge,
            'cookie_value'  => $cookieValue,
            'approve_token' => $approveToken,
        ];
    }

    /**
     * Always reads the current row: a long-running worker may still hold an outdated copy in the identity map.
     */
    public function find(string $id): ?QrLoginChallenge
    {
        return $this->repository->findFresh($id);
    }

    public function createDesktopCookie(string $cookieValue, int $ttl): Cookie
    {
        return Cookie::create(self::DESKTOP_COOKIE_NAME)
            ->withValue($cookieValue)
            ->withExpires($this->now()->add(new DateInterval("PT{$ttl}S")))
            ->withPath('/')
            ->withHttpOnly(true)
            ->withSecure(true)
            ->withSameSite('strict');
    }

    /**
     * The desktop cookie is bound to the profile that created the challenge (SEC-004 L2): a challenge started on one
     * profile cannot be completed (logged in) through another profile's routes and firewall.
     */
    public function verifyDesktopCookie(QrLoginChallenge $challenge, Request $request, ?string $profileName = null): bool
    {
        $cookie = $request->cookies->get(self::DESKTOP_COOKIE_NAME);
        if (!is_string($cookie) || $cookie === '') {
            return false;
        }

        return hash_equals($challenge->getDesktopCookieHash(), $this->desktopCookieHash($cookie, $this->resolveProfile($profileName)->name));
    }

    private function desktopCookieHash(string $cookieValue, string $profileName): string
    {
        return $this->hashValue($profileName . "\0" . $cookieValue);
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

    /**
     * @throws QrLoginChallengeConflictException when the challenge is no longer pending in the database
     */
    public function approve(
        QrLoginChallenge $challenge,
        UserInterface $user,
        ?string $phoneHint,
        ?string $profileName = null,
    ): void {
        $profile = $this->resolveProfile($profileName);

        // 1.50.0: the single-use transition and the approval are one unit of work (never approved without a user).
        // @igor-ignore - A unit of work run at once and not kept (AuthKit's transaction runner).
        $this->repository->transactional(function () use ($challenge, $user, $phoneHint): void {
            $this->claim($challenge, QrLoginChallengeStatus::Pending, QrLoginChallengeStatus::Approved);
            $challenge->markApproved(
                userClass: $user::class,
                userId: $user->getUserIdentifier(),
                phoneHint: $phoneHint,
            );
            $this->save($challenge);
        });
        $this->eventDispatcher->dispatch(new QrLoginApprovedEvent($challenge, $user, $profile->name));
    }

    /**
     * @throws QrLoginChallengeConflictException when the challenge is no longer pending in the database
     */
    public function deny(QrLoginChallenge $challenge, ?string $profileName = null): void
    {
        $profile = $this->resolveProfile($profileName);
        // @igor-ignore - A unit of work run at once and not kept (AuthKit's transaction runner).
        $this->repository->transactional(function () use ($challenge): void {
            $this->claim($challenge, QrLoginChallengeStatus::Pending, QrLoginChallengeStatus::Denied);
            $challenge->markDenied();
            $this->save($challenge);
        });
        $this->eventDispatcher->dispatch(new QrLoginDeniedEvent($challenge, $profile->name));
    }

    /**
     * @throws QrLoginChallengeConflictException when the challenge is no longer approved in the database (already consumed)
     */
    public function consume(QrLoginChallenge $challenge, UserInterface $user, ?string $profileName = null): void
    {
        $profile = $this->resolveProfile($profileName);
        // @igor-ignore - A unit of work run at once and not kept (AuthKit's transaction runner).
        $this->repository->transactional(function () use ($challenge): void {
            $this->claim($challenge, QrLoginChallengeStatus::Approved, QrLoginChallengeStatus::Consumed);
            $challenge->markConsumed();
            $this->save($challenge);
        });
        $this->eventDispatcher->dispatch(new QrLoginCompletedEvent($challenge, $user, $profile->name));
    }

    public function isExpiredOrInvalid(QrLoginChallenge $challenge): bool
    {
        if ($challenge->isExpired($this->now()) && $challenge->getStatus() === QrLoginChallengeStatus::Pending) {
            // @igor-ignore - A unit of work run at once and not kept (AuthKit's transaction runner).
            $expired = $this->repository->transactional(function () use ($challenge): bool {
                if (!$this->transition($challenge, QrLoginChallengeStatus::Pending, QrLoginChallengeStatus::Expired)) {
                    return false;
                }
                $challenge->markExpired();
                $this->save($challenge);

                return true;
            });
            if ($expired) {
                return true;
            }

            $challenge = $this->repository->findFresh($challenge->getId()) ?? $challenge;
        }

        return $challenge->getStatus() === QrLoginChallengeStatus::Expired;
    }

    /**
     * Seconds left before the challenge expires (0 once expired), measured on the injected clock.
     */
    public function expiresIn(QrLoginChallenge $challenge): int
    {
        return max(0, $challenge->getExpiresAt()->getTimestamp() - $this->now()->getTimestamp());
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }

    private function claim(QrLoginChallenge $challenge, QrLoginChallengeStatus $from, QrLoginChallengeStatus $to): void
    {
        if (!$this->transition($challenge, $from, $to)) {
            throw QrLoginChallengeConflictException::forTransition($challenge->getId(), $from, $to);
        }
    }

    private function transition(QrLoginChallenge $challenge, QrLoginChallengeStatus $from, QrLoginChallengeStatus $to): bool
    {
        return $this->repository->transitionStatus($challenge, $from, $to);
    }

    private function save(QrLoginChallenge $challenge): void
    {
        // @igor-ignore - Not shared worker service state.
        $this->repository->save($challenge);
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
