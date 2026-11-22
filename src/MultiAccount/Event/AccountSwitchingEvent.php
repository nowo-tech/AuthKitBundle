<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount\Event;

use Nowo\AuthKitBundle\Port\AuthUserRefs;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Before a switch, once the target account passed the user checker (deny only): {@see self::refuse()} keeps the
 * browser on its current account and drops the target from the switcher, showing the translated reason.
 */
final class AccountSwitchingEvent extends AccountEvent
{
    private ?string $refusal = null;

    /** @var array<string, string> */
    private array $parameters = [];

    /**
     * @param UserInterface|null $from not public since 2.0: read fromRef()
     * @param UserInterface $to not public since 2.0: read toRef()
     */
    public function __construct(string $profile, string $account, Request $request, private readonly ?UserInterface $from, private readonly UserInterface $to)
    {
        parent::__construct($profile, $account, $request);
    }

    /**
     * @param string $messageKey translation key (domain NowoAuthKitBundle, else as is), rendered escaped
     * @param array<string, string> $parameters
     */
    public function refuse(string $messageKey, array $parameters = []): void
    {
        // @igor-ignore - An event object of one dispatch, not a shared service.
        $this->refusal = $messageKey;
        // @igor-ignore - An event object of one dispatch, not a shared service.
        $this->parameters = $parameters;
        $this->stopPropagation();
    }

    public function refusal(): ?string
    {
        return $this->refusal;
    }

    /**
     * @return array<string, string>
     */
    public function refusalParameters(): array
    {
        return $this->parameters;
    }

    /**
     * The account the browser leaves, framework-free (1.46.0).
     */
    public function fromRef(): ?AuthUserRef
    {
        return AuthUserRefs::fromNullableUser($this->from);
    }

    /**
     * The target account, framework-free (1.46.0).
     */
    public function toRef(): AuthUserRef
    {
        return AuthUserRefs::fromUser($this->to);
    }
}
