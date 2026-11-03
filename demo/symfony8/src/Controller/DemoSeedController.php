<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\Totp\Totp;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Demo / e2e helpers (dev only, local demo): seed accounts for the multi-account and MFA scenarios and disable one.
 * `bob@example.com` has MFA with the fixed key below so Playwright can compute codes.
 */
final class DemoSeedController
{
    public const BOB_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MfaManager $mfa,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    #[Route('/_demo/seed', name: 'demo_seed', methods: ['POST'])]
    public function seed(): Response
    {
        $this->guard();
        foreach (['bob@example.com', 'carol@example.com', 'dave@example.com', 'erin@example.com'] as $email) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]) ?? (new User())->setEmail($email);
            $user->setPassword($this->hasher->hashPassword($user, 'demo-password-123'))->setRoles(['ROLE_USER']);
            $this->em->persist($user);
        }
        $this->em->flush();
        $erin = $this->em->getRepository(User::class)->findOneBy(['email' => 'erin@example.com']);
        if ($erin instanceof User) {
            $this->mfa->forgetUser($erin);
        }
        $bob = $this->em->getRepository(User::class)->findOneBy(['email' => 'bob@example.com']);
        if ($bob instanceof User) {
            $this->mfa->forgetUser($bob);
            $totp = new Totp();
            $this->mfa->enable($bob, self::BOB_SECRET, $totp->code(self::BOB_SECRET, $totp->step(time()) - 1));
        }

        return new JsonResponse(['seeded' => true]);
    }

    #[Route('/_demo/disable/{email}', name: 'demo_disable', methods: ['POST'])]
    public function disable(string $email): Response
    {
        $this->guard();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]) ?? throw new NotFoundHttpException();
        $user->setRoles(['ROLE_DISABLED']);
        $this->em->flush();

        return new JsonResponse(['disabled' => $email]);
    }

    private function guard(): void
    {
        if ($this->environment !== 'dev') {
            throw new NotFoundHttpException();
        }
    }
}
