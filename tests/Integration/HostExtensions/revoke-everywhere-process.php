<?php

declare(strict_types=1);

/*
 * Another device signing out everywhere while the first process holds the row (RevocationOnTheLockKernelTest).
 * Arguments: flag file to wait for, file to touch when starting, e-mail, result file.
 */

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Tests\Integration\HostExtensions\MultiAccountHandlerTestKernel;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\UserKitBundle\Sessions\UserSessionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $flag, $started, $email, $result] = $argv;
$kernel                              = new MultiAccountHandlerTestKernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
assert($container instanceof ContainerInterface);
$manager = $container->get('doctrine.orm.entity_manager');
assert($manager instanceof EntityManagerInterface);
$sessions = $container->get(UserSessionManager::class);
assert($sessions instanceof UserSessionManager);
$user = $manager->getRepository(KernelUser::class)->findOneBy(['email' => $email]);
assert($user instanceof KernelUser);
file_put_contents($result . '.ready', '1');
$deadline = microtime(true) + 60;
while (!file_exists($flag) && microtime(true) < $deadline) {
    usleep(10000);
}
touch($started);
file_put_contents($result, (string) $sessions->revokeAll($user));
