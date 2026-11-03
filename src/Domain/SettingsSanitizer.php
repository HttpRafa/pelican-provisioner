<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

/**
 * Turns the settings form into environment values. A stored list that cannot be parsed is rejected, because treating it as empty would mean "allow every node and port". An empty egg list allows nothing, so allowing every egg takes its own explicit setting.
 */
final class SettingsSanitizer
{
    public const int LIST_LIMIT = 100;

    public const int TAG_LENGTH = 100;

    public const int PORT_MIN = 1;

    public const int PORT_MAX = 65535;

    public const int RANGE_LIMIT = 1000;

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $stored
     * @return array<string, string>
     */
    public function toEnvironment(array $input, bool $includeSubdomain, array $stored): array
    {
        $errors = [];

        $database = $this->natural($input['database_limit'] ?? null);
        $allocation = $this->natural($input['allocation_limit'] ?? null);
        $backup = $this->natural($input['backup_limit'] ?? null);
        $subdomain = $includeSubdomain
            ? $this->natural($input['subdomain_limit'] ?? null)
            : $this->natural($stored['subdomain_limit'] ?? '0');
        $update = $this->bool($input['can_users_update_servers'] ?? null);
        $delete = $this->bool($input['can_users_delete_servers'] ?? null);
        $allowAll = $this->bool($input['allow_all_eggs'] ?? null);
        $tags = $this->tags($input['deployment_tags'] ?? []);
        $ports = $this->ports($input['deployment_ports'] ?? []);
        $eggs = $this->eggs($input['allowed_eggs'] ?? []);

        if ($database === null) {
            $errors['database_limit'] = 'invalid';
        }

        if ($allocation === null) {
            $errors['allocation_limit'] = 'invalid';
        }

        if ($backup === null) {
            $errors['backup_limit'] = 'invalid';
        }

        if ($subdomain === null) {
            $errors['subdomain_limit'] = 'invalid';
        }

        if ($update === null) {
            $errors['can_users_update_servers'] = 'invalid';
        }

        if ($delete === null) {
            $errors['can_users_delete_servers'] = 'invalid';
        }

        if ($allowAll === null) {
            $errors['allow_all_eggs'] = 'invalid';
        }

        if ($tags === null) {
            $errors['deployment_tags'] = 'invalid';
        } elseif ($tags === [] && $this->tags($stored['deployment_tags'] ?? '') === null) {
            $errors['deployment_tags'] = 'repair';
        }

        if ($ports === null) {
            $errors['deployment_ports'] = 'invalid';
        } elseif ($ports === [] && $this->ports($stored['deployment_ports'] ?? '') === null) {
            $errors['deployment_ports'] = 'repair';
        }

        if ($eggs === null) {
            $errors['allowed_eggs'] = 'invalid';
        }

        if (
            $errors !== []
            || $database === null
            || $allocation === null
            || $backup === null
            || $subdomain === null
            || $update === null
            || $delete === null
            || $allowAll === null
            || $tags === null
            || $ports === null
            || $eggs === null
        ) {
            throw new InvalidSettingsException($errors);
        }

        return [
            'PROVISIONER_DATABASE_LIMIT' => (string) $database,
            'PROVISIONER_ALLOCATION_LIMIT' => (string) $allocation,
            'PROVISIONER_BACKUP_LIMIT' => (string) $backup,
            'PROVISIONER_SUBDOMAIN_LIMIT' => (string) $subdomain,
            'PROVISIONER_CAN_USERS_UPDATE_SERVERS' => $update ? 'true' : 'false',
            'PROVISIONER_CAN_USERS_DELETE_SERVERS' => $delete ? 'true' : 'false',
            'PROVISIONER_DEPLOYMENT_TAGS' => implode(',', $tags),
            'PROVISIONER_DEPLOYMENT_PORTS' => implode(',', $ports),
            'PROVISIONER_ALLOWED_EGGS' => implode(',', $eggs),
            'PROVISIONER_ALLOW_ALL_EGGS' => $allowAll ? 'true' : 'false',
        ];
    }

    /** @param array<string, mixed> $config */
    public function fromConfig(array $config): DeploymentSettings
    {
        $database = $this->natural($config['database_limit'] ?? null);
        $allocation = $this->natural($config['allocation_limit'] ?? null);
        $backup = $this->natural($config['backup_limit'] ?? null);
        $subdomain = $this->natural($config['subdomain_limit'] ?? null);
        $update = $this->bool($config['can_users_update_servers'] ?? null);
        $delete = $this->bool($config['can_users_delete_servers'] ?? null);
        $allowAll = $this->bool($config['allow_all_eggs'] ?? null);
        $tags = $this->tags($config['deployment_tags'] ?? null);
        $ports = $this->ports($config['deployment_ports'] ?? null);
        $eggs = $this->eggs($config['allowed_eggs'] ?? null);

        $usable = $database !== null
            && $allocation !== null
            && $backup !== null
            && $subdomain !== null
            && $update !== null
            && $delete !== null
            && $allowAll !== null
            && $tags !== null
            && $ports !== null
            && $eggs !== null;

        return new DeploymentSettings(
            createUsable: $usable,
            databaseLimit: $database ?? 0,
            allocationLimit: $allocation ?? 0,
            backupLimit: $backup ?? 0,
            subdomainLimit: $subdomain ?? 0,
            usersCanUpdate: $update ?? false,
            usersCanDelete: $delete ?? false,
            tags: $tags ?? [],
            ports: $ports ?? [],
            allowedEggIds: $eggs ?? [],
            allowAllEggs: $allowAll ?? false,
        );
    }

    /** @return list<string> */
    public function displayList(mixed $value): array
    {
        $items = $this->items($value);

        return $items ?? [];
    }

    public function natural(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= ResourceAmounts::ABSOLUTE_MAX ? $value : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(0|[1-9]\d*)$/', $value) !== 1 || strlen($value) > 10) {
            return null;
        }

        $int = (int) $value;

        if ((string) $int !== $value || $int > ResourceAmounts::ABSOLUTE_MAX) {
            return null;
        }

        return $int;
    }

    public function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return match ($value) {
                1 => true,
                0 => false,
                default => null,
            };
        }

        if (!is_string($value)) {
            return null;
        }

        return filter_var(trim($value), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    /**
     * A tag is written to the environment file inside double quotes, where ${NAME} is expanded on load. Allowing $ would let the settings form show other values from that file.
     *
     * @return list<string>|null
     */
    public function tags(mixed $value): ?array
    {
        $items = $this->items($value);

        if ($items === null) {
            return null;
        }

        foreach ($items as $tag) {
            if (strlen($tag) > self::TAG_LENGTH || preg_match('/[\x00-\x1F,\x7F"\\\\$]/', $tag) === 1) {
                return null;
            }
        }

        return $items;
    }

    /** @return list<string>|null */
    public function ports(mixed $value): ?array
    {
        $items = $this->items($value);

        if ($items === null) {
            return null;
        }

        foreach ($items as $port) {
            if (!$this->validPort($port)) {
                return null;
            }
        }

        return $items;
    }

    /** @return list<int>|null */
    public function eggs(mixed $value): ?array
    {
        $items = $this->items($value);

        if ($items === null) {
            return null;
        }

        $ids = [];

        foreach ($items as $item) {
            $id = PositiveId::parse($item);

            if ($id === null) {
                return null;
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    public function validPort(string $token): bool
    {
        if (preg_match('/^\d{1,5}$/', $token) === 1) {
            $port = (int) $token;

            return $port >= self::PORT_MIN && $port <= self::PORT_MAX;
        }

        if (preg_match('/^(\d{4,5})-(\d{4,5})$/', $token, $matches) !== 1) {
            return false;
        }

        $start = (int) $matches[1];
        $end = (int) $matches[2];

        if ($start < self::PORT_MIN || $end > self::PORT_MAX || $start > $end) {
            return false;
        }

        return ($end - $start + 1) <= self::RANGE_LIMIT;
    }

    /** @return list<string>|null */
    private function items(mixed $value): ?array
    {
        if (is_string($value)) {
            if (strpbrk($value, "\r\n\0") !== false) {
                return null;
            }

            $value = $value === '' ? [] : explode(',', $value);
        }

        if (!is_array($value) || count($value) > self::LIST_LIMIT) {
            return null;
        }

        $items = [];

        foreach ($value as $item) {
            if (!is_string($item) && !is_int($item)) {
                return null;
            }

            $item = trim((string) $item);

            if ($item === '') {
                continue;
            }

            if (strpbrk($item, "\r\n\0") !== false) {
                return null;
            }

            $items[] = $item;
        }

        // Entries that all trim to nothing are corruption, not an intentionally empty list.
        if ($items === [] && $value !== []) {
            return null;
        }

        return $items;
    }
}
