<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount\Event;

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

    public function __construct(string $profile, string $account, Request $request, public readonly ?UserInterface $from, public readonly UserInterface $to)
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
}
