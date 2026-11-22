<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database\Support\HostEntity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;

/**
 * 2.0 (W1-2): a host entity related to an AuthKit row and to the host's user, mapped by the application's manager
 * only. The application points it at an invitation through `$em->getReference()` and flushes its own manager.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_invitation_note')]
class HostInvitationNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Invitation::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Invitation $invitation,
        #[ORM\ManyToOne(targetEntity: KernelUser::class)]
        #[ORM\JoinColumn(name: 'author_email', referencedColumnName: 'email', nullable: true)]
        private ?KernelUser $author = null,
        #[ORM\Column(length: 120)]
        private string $text = '',
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvitation(): Invitation
    {
        return $this->invitation;
    }

    public function getAuthor(): ?KernelUser
    {
        return $this->author;
    }

    public function getText(): string
    {
        return $this->text;
    }
}
