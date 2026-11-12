<?php

declare(strict_types=1);

// Source mutation check of the 1.46.0 port equivalence matrix (tests/Unit/Port/PortEquivalenceTest.php): applies one
// mutation of the port dispatch / gates / user references at a time, runs the matrix, restores the file. Every
// mutation must be "killed". Usage (bundle root, in the PHP container): php scripts/mutation-check-port-equivalence.php
$m = [
    ['src/Port/Ports.php', "\$service instanceof MfaPolicyInterface            => \$service,", "\$service instanceof MfaRequirementPolicyInterface && \$service instanceof MfaPolicyInterface => new MfaRequirementPolicyGate(\$service),\n            \$service instanceof MfaPolicyInterface            => \$service,"],
    ['src/Port/Ports.php', "\$service instanceof MfaRequirementPolicyInterface => new MfaRequirementPolicyGate(\$service),", "\$service instanceof MfaRequirementPolicyInterface => null,"],
    ['src/Port/Ports.php', "\$service instanceof InvitationsAccessPolicyInterface  => new InvitationsAccessPolicyGate(\$service),", "\$service instanceof InvitationsAccessPolicyInterface  => null,"],
    ['src/Port/Ports.php', "\$service instanceof EnrolmentPolicyInterface => new EnrolmentPolicyGate(\$service),", "\$service instanceof EnrolmentPolicyInterface => null,"],
    ['src/Port/Ports.php', "\$service instanceof AccountPresentationProviderInterface => new AccountPresentationGate(\$service),", "\$service instanceof AccountPresentationProviderInterface => null,"],
    ['src/Port/Ports.php', "\$service instanceof InvitationActionPolicyInterface     => new InvitationActionPolicyGate(\$service, \$now),", "\$service instanceof InvitationActionPolicyInterface     => new class implements InvitationActionAuthorizerInterface { public function isGranted(?\\Symfony\\Component\\Security\\Core\\User\\UserInterface \$a, string \$b, \\Nowo\\AuthKitBundle\\Entity\\Invitation|\\Nowo\\AuthKitBundle\\Invitation\\InvitationRequest \$c, ?\\Nowo\\AuthKitBundle\\Invitation\\InvitationRequest \$d = null): bool { return true; } },"],
    ['src/Port/Ports.php', "\$service instanceof InvitationGrantHandlerInterface => new InvitationGrantHandlerGate(\$service),", "\$service instanceof InvitationGrantHandlerInterface => new class implements InvitationGrantInterface { public function grant(\\Nowo\\AuthKitBundle\\Invitation\\InvitationSnapshot \$i, \\Symfony\\Component\\Security\\Core\\User\\UserInterface \$u): void {} },"],
    ['src/Port/InvitationsAccessPolicyGate.php', 'return $this->policy->allowsManage(AuthUserRefs::fromUser($user), $profile) === true;', 'return $this->policy->allowsManage(AuthUserRefs::fromUser($user), \'default\') === true;'],
    ['src/Port/InvitationActionPolicyGate.php', '$subject instanceof Invitation ? InvitationSnapshot::of($subject, $this->now) : $subject,', 'new InvitationRequest(\'ok@example.com\'),'],
    ['src/Port/InvitationActionPolicyGate.php', "            \$action,\n", "            'invite',\n"],
    ['src/Port/MfaRequirementPolicyGate.php', 'return $this->policy->requirementFor(AuthUserRefs::fromUser($user), $profile);', 'return $this->policy->requirementFor(AuthUserRefs::fromUser($user), \'other\');'],
    ['src/Port/EnrolmentPolicyGate.php', '$token instanceof SwitchUserToken,', 'false,'],
    ['src/Port/EnrolmentPolicyGate.php', '$token->getRoleNames(),', '[],'],
    ['src/Port/EnrolmentPolicyGate.php', '$user instanceof UserInterface ? AuthUserRefs::fromUser($user) : null,', 'null,'],
    ['src/Port/AuthUserRefs.php', '? new AuthUserRef(UserIds::className($user), $id, $user->getUserIdentifier(), $user->getRoles(), $user)', '? new AuthUserRef(UserIds::className($user), $id, $user->getUserIdentifier(), [], $user)'],
    ['src/Port/AuthUserRefs.php', '? new AuthUserRef(UserIds::className($user), $id, $user->getUserIdentifier(), $user->getRoles(), $user)', '? new AuthUserRef(UserIds::className($user), $id, \'alice@example.com\', $user->getRoles(), $user)'],
    ['src/Port/SocialCallbackStepGate.php', 'return $this->step->supports($this->context($request), $provider) === true;', 'return $this->step->supports($this->context($request), \'google\') === true;'],
    ['src/Port/AuthRequestContexts.php', "static fn (string \$name): bool => \$request->hasSession() && \$request->getSession()->has(\$name),", "static fn (string \$name): bool => true,"],
    ['src/Mfa/MfaManager.php', '$policy = Ports::mfaPolicy($this->policies->get($profile->name));', '$policy = Ports::mfaPolicy($this->policies->get(\'default\') instanceof \\Nowo\\AuthKitBundle\\Mfa\\MfaPolicyInterface ? null : $this->policies->get($profile->name));'],
    ['src/Invitation/InvitationManager.php', 'if (!Ports::invitationActionAuthorizer($authorizer, $this->now())->isGranted($actor, $action, $subject, $request)) {', 'if (!Ports::invitationActionAuthorizer($authorizer, $this->now())->isGranted($actor, $action, $subject, $request) && $actor !== null) {'],
    ['src/Controller/SocialLoginCheckController.php', "if (\$handler instanceof SocialCallbackStepInterface && !\$handler instanceof SocialCallbackHandlerInterface) {\n                \$handler = new SocialCallbackStepGate(", "if (false) {\n                \$handler = new SocialCallbackStepGate("],
];
$killed = 0;
foreach ($m as $i => [$file, $from, $to]) {
    $orig = file_get_contents($file);
    if (substr_count($orig, $from) !== 1) {
        echo "M{$i} NOT APPLICABLE ({$file})\n";
        continue;
    }
    file_put_contents($file, str_replace($from, $to, $orig));
    exec('vendor/bin/phpunit --no-progress --no-coverage tests/Unit/Port/PortEquivalenceTest.php 2>&1', $out, $code);
    file_put_contents($file, $orig);
    $status = $code === 0 ? 'SURVIVED' : 'killed';
    if ($code !== 0) {
        ++$killed;
    }
    echo "M{$i} {$status}  {$file}\n";
    $out = [];
}
echo "killed {$killed} / " . count($m) . "\n";
exit($killed === count($m) ? 0 : 1);
