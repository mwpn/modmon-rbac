# Rbac

Role and permission management for ModMon hosts.

## Type

`platform`

## Compatibility

| Requirement | Constraint |
|-------------|------------|
| PHP         | `^8.3`     |
| Laravel     | `^13.0`    |
| Foundation  | `^1.0`     |

## Provides

- `authorization.permission` — resolves to the public RBAC contracts
  (`AuthorizationContract` / `RoleManagementContract`), never to
  implementation details.

## Requires

- `identity.user` — the Identity user domain, consumed through the
  public `UserQueryContract` only.

## Optional Integrations

*None* — document capabilities checked at runtime when available.

## Installation

```bash
# Copy the module into a compatible ModMon Foundation host
cp -r modmon-rbac/Modules/Rbac /path/to/host/Modules/Rbac

# Verify compatibility
php artisan module:doctor rbac

# Install and enable (migrations only — no privilege seed)
php artisan module:install rbac
```

Copying the module never runs migrations or mutates runtime state.
Migrations run only during `module:install`. Installation does **not**
grant roles or permissions to any user.

### Operational bootstrap (owner access)

RBAC admin routes and navigation require `rbac.roles.manage`. After a
fresh install no user has that permission until an operator grants it
explicitly:

```bash
php artisan module:install rbac

# Create a user if the host has none yet (Identity)
php artisan identity:user:create \
  --name="Operator" \
  --email=operator@example.com \
  --password=...

# Explicit owner bootstrap (exactly one of --email or --user)
php artisan rbac:bootstrap-owner --email=operator@example.com
# or: php artisan rbac:bootstrap-owner --user=1
# optional role name: --role=rbac-manager (default)

# Sign in, then open the admin surface
# GET /rbac/roles
```

`rbac:bootstrap-owner` ensures role `rbac-manager` (or `--role`) exists,
assigns **only** `rbac.roles.manage` to that role, and assigns the role
to the named user. It is idempotent, never auto-selects a user, never
revokes existing assignments, and is registered only while RBAC is
enabled.

## Configuration

No static configuration required.

### Environment Variables

None.

### Runtime Settings

Requires modmon-settings (not yet available in Foundation v1).

## Permissions

This module **declares** its own permission through the canonical
Foundation `ContributesPermissions` mechanism:

| Permission          | Label        | Notes                                   |
|---------------------|--------------|-----------------------------------------|
| `rbac.roles.manage` | Manage Roles | Can manage RBAC roles and assignments.  |

The module is also the *enforcer*: it consumes the Foundation
`PermissionRegistryContract` as the source of truth for which
permission ids may be assigned to roles. Only currently registered
permission ids are assignable; nothing is snapshotted into RBAC
tables, and a permission from a disabled module is never considered
registered.

## Routes

Module-owned admin surface (Phase 3), declared via `ContributesRoutes`
and loaded only while the module is enabled:

| Method | URI                          | Name                          | Purpose                |
|--------|------------------------------|-------------------------------|------------------------|
| GET    | `/rbac/roles`                | `rbac.roles.index`            | List roles            |
| GET    | `/rbac/roles/create`         | `rbac.roles.create`           | Create form           |
| POST   | `/rbac/roles`                | `rbac.roles.store`            | Create role           |
| GET    | `/rbac/roles/{role}`         | `rbac.roles.edit`             | Edit role             |
| PUT    | `/rbac/roles/{role}`         | `rbac.roles.update`           | Rename role           |
| DELETE | `/rbac/roles/{role}`         | `rbac.roles.destroy`          | Delete role           |
| POST   | `/rbac/roles/{role}/permissions` | `rbac.roles.permissions.assign`  | Assign permission |
| DELETE | `/rbac/roles/{role}/permissions` | `rbac.roles.permissions.remove` | Remove permission  |
| POST   | `/rbac/roles/{role}/users`   | `rbac.roles.users.assign`     | Assign role to user   |
| DELETE | `/rbac/roles/{role}/users`   | `rbac.roles.users.remove`     | Remove role from user |

All routes are protected by `auth` + `can:rbac.roles.manage` (the
Laravel Gate path from Phase 2 — the single authorization mechanism).
Controllers use only the public contracts; no RBAC/Identity table is
queried directly.

## Console commands

| Command | Purpose |
|---------|---------|
| `rbac:bootstrap-owner` | Explicit owner bootstrap: ensure role (default `rbac-manager`) has `rbac.roles.manage` and assign it to `--email` or `--user`. Idempotent. Not run by `module:install`. |

## Events Published

*None* — this module publishes no events.

## Events Consumed

*None* — this module consumes no external events.

## Public Contracts

- `Modules\Rbac\Domain\Contracts\AuthorizationContract`
  - `identityHasPermission(string $userId, string $permissionId): bool`
  - Checks whether the identity has the permission through any of its
    roles. Validates the user against `UserQueryContract` first.
- `Modules\Rbac\Domain\Contracts\RoleManagementContract`
  - `createRole(string $name): int`
  - `updateRole(int $roleId, string $name): void`
  - `deleteRole(int $roleId): void`
  - `all(): array` — all roles ordered by name (admin listing)
  - `find(int $roleId): ?Role` — single role or null
  - `count(): int` — total roles
  - `assignPermissionToRole(int $roleId, string $permissionId): void`
  - `removePermissionFromRole(int $roleId, string $permissionId): void`
  - `assignRoleToUser(string $userId, int $roleId): void`
  - `removeRoleFromUser(string $userId, int $roleId): void`
  - `userRoleIds(string $userId): array`
  - `userIdsWithRole(int $roleId): array` — user ids assigned to a role
  - `rolePermissionIds(int $roleId): array`
  - `registeredPermissionIds(): array`

Consumers depend on these contracts, never on
`Modules\Rbac\Application\...` or `Modules\Rbac\Domain\Models`.

### Authorization semantics (v1)

- Permissions are obtained **through roles only** — no direct
  user-permission assignment.
- No wildcards, no hierarchy, no deny rules, no tenancy/teams, no
  caching framework. Checks run against the RBAC-owned tables at
  call time.

### Laravel Gate integration (Phase 2)

While the module is enabled, RBAC wires a single `Gate::before()`
callback (`Modules\Rbac\Application\Services\RuntimeAuthorization`)
so Laravel authorization works without importing RBAC internals:

```php
$user->can('rbac.roles.manage');          // Authenticatable::can()
Gate::allows('rbac.roles.manage');        // current authenticated user
Gate::forUser($user)->allows('rbac.roles.manage');
```

- The callback answers **only** for permission ids currently
  contributed by the `rbac` module (live `PermissionRegistry` lookup —
  never a boot-time snapshot), then delegates to the public
  `AuthorizationContract` (`identityHasPermission`).
- The user is resolved through `getAuthIdentifier()` and validated
  through the Identity `UserQueryContract` — no Identity/host model
  is imported.
- The Gate `abilities` map is never polluted: the integration uses a
  `before` callback, so `Gate::has()` stays false for RBAC
  permissions.
- **Disable semantics:** the Foundation lifecycle removes the module's
  permissions from the `PermissionRegistry`, so the callback returns
  null for every ability — no active authorization contribution is
  left behind. In a fresh process a disabled module's provider never
  boots, so the callback is never registered at all. Role/assignment
  data is preserved.
- **Re-enable semantics:** contributions and the Gate behavior are
  restored; the callback is registered at most once per application
  lifecycle (no accumulation on repeated disable/enable cycles).

## Database Ownership

| Table                    | Purpose                                  |
|--------------------------|------------------------------------------|
| `rbac_roles`             | Roles (`id`, `name` unique, timestamps)  |
| `rbac_role_permission`   | Role ↔ registered-permission assignments |
| `rbac_user_role`         | User ↔ role assignments                  |

### Cross-Module References

`rbac_user_role.user_id` references the Identity user domain only
through `UserQueryContract` validation at the service layer — there is
**no foreign key** to Identity's `users` table. Disabling the module
preserves all rows; no data is deleted.

## Navigation Contributions

Phase 3 — the module contributes one navigation item via
`ContributesNavigation`:

| Item      | Label               | Route        | Permission            | Group        |
|-----------|---------------------|--------------|-----------------------|--------------|
| `rbac.roles` | Roles & Permissions | `/rbac/roles` | `rbac.roles.manage`   | Administration |

The item is registered while the module is enabled and removed by the
Foundation lifecycle on disable (re-enable restores it). The Experience
shell hides the item unless the current user is allowed
`rbac.roles.manage` through Laravel Gate. The route remains
Gate-protected as well.

## Dashboard Contributions

*None* — this module contributes no dashboard widgets.

## Testing

Module tests live under `Modules/Rbac/Tests/` (and any host-side
`tests/Feature/Rbac/` suite when present). Run with:

```bash
php artisan test Modules/Rbac/Tests
# or, when host Rbac feature tests exist:
php artisan test --filter="Rbac"
```

### Test Coverage

| Area | Status |
|------|--------|
| Manifest validation | Covered by `module:doctor` (Foundation) |
| Discovery | Covered by Foundation `module:list` |
| Installation | Covered — host `RbacLifecycleTest` / module lifecycle |
| Capability registration | Covered — host contract resolution tests |
| Routes | Covered — host admin lifecycle tests |
| Migrations | Covered — host lifecycle tests |
| Contributions | Covered — permission + admin lifecycle |
| Gate integration | Covered — host Gate integration tests |
| Owner bootstrap CLI | Covered — `RbacBootstrapOwnerCommandTest` |
| Disable/Enable | Covered — lifecycle + bootstrap fresh-boot absence |
| Data preservation | Covered — host lifecycle tests |
| Architecture boundary | Covered — host boundary/compliance tests |
| Role CRUD | Covered — host role CRUD / admin HTTP |
| Permission assignment | Covered — host assignment / admin HTTP |
| User-role assignment | Covered — host assignment / admin HTTP |
| Authorization checks | Covered — host authorization / admin HTTP |
| Portability / doctor / missing Identity | Covered — host compliance tests |

## Certification

See `docs/reports/rbac-compliance-v1.md` (module repository). Checklist
section 14: manifest, lifecycle, boundaries, documentation, owner
bootstrap, and host/module tests are **PASS** for v1.0.1.

## Version History

| Version | Foundation | Description       |
|---------|------------|-------------------|
| 1.0.1   | ^1.0       | Explicit `rbac:bootstrap-owner` CLI to break the fresh-install chicken-and-egg (no install-time privilege seed; idempotent; UserQueryContract-only user resolve). |
| 1.0.0   | ^1.0       | Phase 1: core domain + persistence + public integration. Phase 2: permission contribution (`rbac.roles.manage`) + Laravel Gate integration with disable/re-enable runtime semantics. Phase 3: module-owned admin surface (routes/controllers/Blade views for role CRUD, permission and user-role assignment) + navigation contribution, all protected by `rbac.roles.manage`. Phase 5: lifecycle/compliance hardening (doctor before/after Identity, explicit install, owned migrations, disable/re-enable proof). |
