<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Profile;

use function array_key_exists;

final class ProfileRegistry
{
    /** @var array<string, ProfileSettings> */
    private array $byName = [];

    /** @var array<class-string, ProfileSettings> */
    private array $byExactClass;

    /** @var array<class-string, ProfileSettings|null> */
    private array $resolveCache = [];

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    public function __construct(array $profiles, private readonly string $defaultProfileName)
    {
        /** @var array<class-string, ProfileSettings> $byExactClass */
        $byExactClass = [];

        foreach ($profiles as $name => $config) {
            $settings = ProfileSettings::fromConfig($name, $config);

            $this->byName[$name]                = $settings;
            $byExactClass[$settings->userClass] = $settings;
        }

        $this->byExactClass = $byExactClass;
    }

    public function getByName(string $name): ProfileSettings
    {
        if (!isset($this->byName[$name])) {
            throw new UnknownProfileException($name);
        }

        return $this->byName[$name];
    }

    public function getDefault(): ProfileSettings
    {
        return $this->getByName($this->defaultProfileName);
    }

    /**
     * @return array<string, ProfileSettings>
     */
    public function all(): array
    {
        return $this->byName;
    }

    public function resolveForObject(object $object): ?ProfileSettings
    {
        $class = $object::class;

        if (array_key_exists($class, $this->resolveCache)) {
            return $this->resolveCache[$class];
        }

        if (isset($this->byExactClass[$class])) {
            return $this->resolveCache[$class] = $this->byExactClass[$class];
        }

        foreach ($this->byExactClass as $userClass => $profile) {
            if ($object instanceof $userClass) {
                return $this->resolveCache[$class] = $profile;
            }
        }

        return $this->resolveCache[$class] = null;
    }

    public function hasPasswordResetEnabled(): bool
    {
        foreach ($this->byName as $profile) {
            if ($profile->passwordReset['mode'] !== 'disabled') {
                return true;
            }
        }

        return false;
    }

    public function hasRegistrationEnabled(): bool
    {
        foreach ($this->byName as $profile) {
            if ($profile->registrationMode !== 'disabled') {
                return true;
            }
        }

        return false;
    }
}
