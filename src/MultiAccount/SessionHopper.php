<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

use function bin2hex;
use function random_bytes;

/**
 * Moves the request's session between session ids inside one request (1.34, proven on native files / PDO / mock
 * storages and FrankenPHP worker mode by the S0 spike): the current session is written and closed, another id is
 * opened. Session ids never leave the server unsealed.
 */
final class SessionHopper
{
    /**
     * Writes and closes the current session, then opens `$id`.
     */
    public static function open(SessionInterface $session, string $id): void
    {
        self::close($session);
        $session->setId($id);
        $session->start();
    }

    /**
     * Writes and closes the current session, then opens a brand-new empty one (server-generated id).
     */
    public static function fresh(SessionInterface $session): void
    {
        self::close($session);
        $session->setId(self::newId());
        $session->start();
        $session->clear();
    }

    /**
     * Empties the session `$id` (its token and data go), then reopens `$back`.
     */
    public static function wipe(SessionInterface $session, string $id, string $back): void
    {
        self::open($session, $id);
        $session->clear();
        self::open($session, $back);
    }

    /**
     * Writes and closes the session when it is open.
     */
    public static function close(SessionInterface $session): void
    {
        if ($session->isStarted()) {
            $session->save();
        }
    }

    /**
     * 192-bit id in the default PHP charset (accepted by every storage).
     */
    public static function newId(): string
    {
        return bin2hex(random_bytes(24));
    }
}
