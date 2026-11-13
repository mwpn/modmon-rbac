<?php

declare(strict_types=1);

namespace Modules\Rbac\Tests\Feature;

use App\Foundation\Runtime\ModuleManager;
use App\Foundation\SDK\Contracts\CapabilityRegistryContract;
use App\Foundation\SDK\Contracts\ModuleRegistrarContract;
use App\Foundation\SDK\Contracts\NavigationRegistryContract;
use App\Foundation\SDK\Contracts\PermissionRegistryContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Identity\Models\User;
use Modules\Rbac\Domain\Contracts\RoleManagementContract;
use Tests\TestCase;

/**
 * Staff user management UI (ADR-0042) — the web replacement for the
 * `identity:user:create` CLI command. Lives in RBAC (not Identity, see
 * the controller's docblock); writes go through Identity's
 * `UserManagementContract`, role assignment through RBAC's own
 * `RoleManagementContract`.
 *
 * RBAC's own Gate::before() callback (RuntimeAuthorization) intercepts
 * every registered permission id once RBAC is installed, so — unlike
 * other modules' HTTP-guard tests — `Gate::define()` can't stand in for
 * a permission here; the acting user is granted real roles instead.
 */
class StaffUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $modulesJsonPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modulesJsonPath = storage_path('framework/testing/modules.json');
        if (file_exists($this->modulesJsonPath)) {
            unlink($this->modulesJsonPath);
        }

        $this->app->forgetInstance(ModuleRegistrarContract::class);
        $this->app->forgetInstance(ModuleManager::class);
        $this->app->forgetInstance(CapabilityRegistryContract::class);
        $this->app->forgetInstance(NavigationRegistryContract::class);
        $this->app->forgetInstance(PermissionRegistryContract::class);
        $this->app->forgetInstance(RoleManagementContract::class);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->modulesJsonPath)) {
            unlink($this->modulesJsonPath);
        }

        parent::tearDown();
    }

    private function installIdentityAndRbac(): void
    {
        $manager = app(ModuleManager::class);

        foreach (['identity', 'rbac'] as $code) {
            $result = $manager->install($code);
            if (! $result['success']) {
                $this->fail("{$code} could not be installed: ".implode(' | ', $result['messages']));
            }
        }
    }

    /**
     * @param  string[]  $permissionIds
     */
    private function grant(User $user, array $permissionIds): void
    {
        $roles = app(RoleManagementContract::class);
        $roleId = $roles->createRole('Role for '.$user->id.'-'.implode('-', $permissionIds));

        foreach ($permissionIds as $permissionId) {
            $roles->assignPermissionToRole($roleId, $permissionId);
        }

        $roles->assignRoleToUser((string) $user->id, $roleId);
    }

    public function test_index_and_create_routes_require_the_matching_permission(): void
    {
        $this->installIdentityAndRbac();
        $acting = User::query()->create(['name' => 'Acting', 'email' => 'acting@example.com', 'password' => 'secret-password']);

        $this->actingAs($acting)->get('/rbac/users')->assertForbidden();

        $this->grant($acting, ['rbac.users.view']);
        $this->actingAs($acting)->get('/rbac/users')->assertOk();
        $this->actingAs($acting)->get('/rbac/users/create')->assertForbidden();
    }

    public function test_staff_user_can_be_created_through_the_form(): void
    {
        $this->installIdentityAndRbac();
        $acting = User::query()->create(['name' => 'Acting', 'email' => 'acting@example.com', 'password' => 'secret-password']);
        $this->grant($acting, ['rbac.users.view', 'rbac.users.manage']);

        $this->actingAs($acting)->post('/rbac/users', [
            'name' => 'Budi Teknisi',
            'email' => 'budi@example.com',
            'password' => 'password123',
        ])->assertRedirect();

        $created = User::query()->where('email', 'budi@example.com')->first();
        $this->assertNotNull($created);
        $this->assertSame('Budi Teknisi', $created->name);
        $this->assertTrue(Hash::check('password123', $created->password));
    }

    public function test_create_rejects_duplicate_email_and_short_password(): void
    {
        $this->installIdentityAndRbac();
        $acting = User::query()->create(['name' => 'Acting', 'email' => 'acting@example.com', 'password' => 'secret-password']);
        $this->grant($acting, ['rbac.users.view', 'rbac.users.manage']);

        $this->actingAs($acting)->post('/rbac/users', [
            'name' => 'Dup', 'email' => 'acting@example.com', 'password' => 'password123',
        ])->assertSessionHasErrors('email');

        $this->actingAs($acting)->post('/rbac/users', [
            'name' => 'Short', 'email' => 'short@example.com', 'password' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertSame(1, User::query()->count());
    }

    public function test_staff_user_can_be_updated_and_a_blank_password_keeps_the_old_one(): void
    {
        $this->installIdentityAndRbac();
        $acting = User::query()->create(['name' => 'Acting', 'email' => 'acting@example.com', 'password' => 'secret-password']);
        $target = User::query()->create(['name' => 'Target', 'email' => 'target@example.com', 'password' => 'old-password']);
        $this->grant($acting, ['rbac.users.view', 'rbac.users.manage']);

        $this->actingAs($acting)->put("/rbac/users/{$target->id}", [
            'name' => 'Target Renamed', 'email' => 'target@example.com', 'password' => '',
        ])->assertRedirect(route('rbac.users.edit', $target->id));

        $target->refresh();
        $this->assertSame('Target Renamed', $target->name);
        $this->assertTrue(Hash::check('old-password', $target->password));
    }

    public function test_staff_user_can_be_deleted_but_not_their_own_account(): void
    {
        $this->installIdentityAndRbac();
        $acting = User::query()->create(['name' => 'Acting', 'email' => 'acting@example.com', 'password' => 'secret-password']);
        $target = User::query()->create(['name' => 'Target', 'email' => 'target@example.com', 'password' => 'secret-password']);
        $this->grant($acting, ['rbac.users.view', 'rbac.users.manage']);

        $this->actingAs($acting)->delete("/rbac/users/{$acting->id}")
            ->assertRedirect(route('rbac.users.index'))
            ->assertSessionHasErrors('user');
        $this->assertNotNull(User::query()->find($acting->id));

        $this->actingAs($acting)->delete("/rbac/users/{$target->id}")
            ->assertRedirect(route('rbac.users.index'));
        $this->assertNull(User::query()->find($target->id));
    }

    public function test_role_can_be_assigned_and_removed_from_the_staff_user_edit_page(): void
    {
        $this->installIdentityAndRbac();
        $acting = User::query()->create(['name' => 'Acting', 'email' => 'acting@example.com', 'password' => 'secret-password']);
        $target = User::query()->create(['name' => 'Target', 'email' => 'target@example.com', 'password' => 'secret-password']);
        $this->grant($acting, ['rbac.users.view', 'rbac.users.manage']);

        $roles = app(RoleManagementContract::class);
        $roleId = $roles->createRole('Teknisi');

        $this->actingAs($acting)->post("/rbac/users/{$target->id}/roles", ['role_id' => $roleId])
            ->assertRedirect(route('rbac.users.edit', $target->id));
        $this->assertContains($roleId, $roles->userRoleIds((string) $target->id));

        $this->actingAs($acting)->get("/rbac/users/{$target->id}")
            ->assertOk()
            ->assertSee('Teknisi');

        $this->actingAs($acting)->delete("/rbac/users/{$target->id}/roles", ['role_id' => $roleId])
            ->assertRedirect(route('rbac.users.edit', $target->id));
        $this->assertNotContains($roleId, $roles->userRoleIds((string) $target->id));
    }

    public function test_index_lists_each_users_assigned_role_names(): void
    {
        $this->installIdentityAndRbac();
        $acting = User::query()->create(['name' => 'Acting', 'email' => 'acting@example.com', 'password' => 'secret-password']);
        $target = User::query()->create(['name' => 'Target', 'email' => 'target@example.com', 'password' => 'secret-password']);
        $this->grant($acting, ['rbac.users.view', 'rbac.users.manage']);

        $roles = app(RoleManagementContract::class);
        $roleId = $roles->createRole('Teknisi');
        $roles->assignRoleToUser((string) $target->id, $roleId);

        $this->actingAs($acting)->get('/rbac/users')
            ->assertOk()
            ->assertSee('Teknisi');
    }

    public function test_rbac_contributes_the_staff_user_permissions(): void
    {
        $this->installIdentityAndRbac();

        $permissionIds = array_map(
            fn ($permission) => $permission->id,
            app(PermissionRegistryContract::class)->all(),
        );

        $this->assertContains('rbac.users.view', $permissionIds);
        $this->assertContains('rbac.users.manage', $permissionIds);
    }
}
