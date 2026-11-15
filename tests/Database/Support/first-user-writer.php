<?php

declare(strict_types=1);

/*
 * One of two concurrent first-account writers of tests/Database/FirstUserOnlyTest (audit QG-M1): a request of a
 * FrankenPHP worker (kernel reset off) on a `registration_mode: first_user_only` profile with social sign-up.
 *
 *   first-user-writer.php <form|social> <email> <start timestamp>
 *
 * Waits for the start timestamp, then registers the e-mail address through the registration page's service or signs it
 * up through a social callback (subject = the address). With AUTH_KIT_LOAD_SLOW_PERSIST set, the unit stays open
 * before its insert ({@see SlowUserPersist}). Prints one JSON line: {outcome: created|refused|transient|<class>}.
 */

use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\Tests\Database\Support\DatabaseTestKernel;
use Nowo\TransactionContract\TransientStorageFailure;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $mode, $email, $start] = $argv + [null, 'form', 'first@example.com', '0'];
$kernel                   = new DatabaseTestKernel('test_first', true);
$kernel->boot();
$container = $kernel->getContainer();
$registrar = $container->get('load.registrar');
assert($registrar instanceof UserRegistrar);
$linker = $container->get('load.social');
assert($linker instanceof SocialAccountLinker);
$profiles = $container->get('load.profiles');
assert($profiles instanceof ProfileRegistry);
while (microtime(true) < (float) $start) {
    usleep(1000);
}

try {
    if ($mode === 'form') {
        $registrar->register(['email' => $email, 'password' => 'secret-123'], 'default');
    } else {
        $linker->linkOrCreate($profiles->getDefault(), 'google', new SocialUserProfile('subject-' . $email, $email, null, [], true), ['access_token' => 'a', 'refresh_token' => null, 'expires_in' => null]);
    }
    $outcome = 'created';
} catch (TransientStorageFailure) {
    $outcome = 'transient';
} catch (RuntimeException $refusal) {
    $outcome = 'refused: ' . $refusal->getMessage();
} catch (Throwable $failure) {
    $outcome = $failure::class . ': ' . $failure->getMessage();
}

echo json_encode(['outcome' => $outcome], \JSON_THROW_ON_ERROR), "\n";
