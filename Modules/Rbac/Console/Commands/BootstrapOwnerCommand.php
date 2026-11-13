<?php

declare(strict_types=1);

namespace Modules\Rbac\Console\Commands;

use Illuminate\Console\Command;
use Modules\Identity\Domain\Contracts\UserQueryContract;
use Modules\Identity\Domain\ReadModels\UserReadModel;
use Modules\Rbac\Domain\Contracts\RoleManagementContract;

/**
 * Explicit, deterministic owner bootstrap for RBAC admin access.
 *
 * Resolves the target user only through Identity's UserQueryContract,
 * ensures a named role exists with `rbac.roles.manage`, and assigns
 * that role to the user. Never auto-selects a user, never grants other
 * permissions, never revokes existing assignments.
 */
class BootstrapOwnerCommand extends Command
{
    public const BOOTSTRAP_PERMISSION = 'rbac.roles.manage';

    public const DEFAULT_ROLE = 'rbac-manager';

    protected $signature = 'rbac:bootstrap-owner
                            {--email= : Target user email (mutually exclusive with --user)}
                            {--user= : Target user id (mutually exclusive with --email)}
                            {--role=rbac-manager : Role name to ensure}';

    protected $description = 'Grant RBAC admin access (rbac.roles.manage) to an explicit user via a role';

    public function handle(
        UserQueryContract $users,
        RoleManagementContract $roles,
    ): int {
        $emailOption = $this->option('email');
        $userOption = $this->option('user');
        $roleName = $this->option('role');

        $emailProvided = is_string($emailOption) && $emailOption !== '';
        $userProvided = $userOption !== null && $userOption !== '';

        if ($emailProvided === $userProvided) {
            $this->error('Provide exactly one of --email or --user.');

            return self::FAILURE;
        }

        if (! is_string($roleName) || trim($roleName) === '') {
            $this->error('Option --role must be a non-empty role name.');

            return self::FAILURE;
        }

        $roleName = trim($roleName);

        $user = $emailProvided
            ? $users->findByEmail($emailOption)
            : $this->resolveById($users, (string) $userOption);

        if ($user === null) {
            $target = $emailProvided ? "email '{$emailOption}'" : "id '{$userOption}'";
            $this->error("User with {$target} was not found in the Identity user domain.");

            return self::FAILURE;
        }

        $roleId = $this->ensureRole($roles, $roleName);
        $roles->assignPermissionToRole($roleId, self::BOOTSTRAP_PERMISSION);
        $roles->assignRoleToUser((string) $user->id, $roleId);

        $this->info(sprintf(
            "RBAC owner bootstrap complete: user '%s' (id %d) has role '%s' with permission '%s'.",
            $user->email,
            $user->id,
            $roleName,
            self::BOOTSTRAP_PERMISSION,
        ));

        return self::SUCCESS;
    }

    private function resolveById(UserQueryContract $users, string $rawId): ?UserReadModel
    {
        if (! ctype_digit($rawId)) {
            $this->error("Invalid user id: {$rawId}");

            return null;
        }

        return $users->findById((int) $rawId);
    }

    private function ensureRole(RoleManagementContract $roles, string $name): int
    {
        foreach ($roles->all() as $role) {
            if ($role->name === $name) {
                return (int) $role->id;
            }
        }

        return $roles->createRole($name);
    }
}
