<?php

declare(strict_types=1);

namespace Modules\Rbac\Tests\Feature;

use App\Foundation\Runtime\ModuleManager;
use App\Foundation\SDK\Contracts\CapabilityRegistryContract;
use App\Foundation\SDK\Contracts\ModuleRegistrarContract;
use App\Foundation\SDK\Contracts\PermissionRegistryContract;
use App\Foundation\SDK\ModuleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Models\User;
use Modules\Rbac\Console\Commands\BootstrapOwnerCommand;
use Modules\Rbac\Domain\Contracts\RoleManagementContract;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Tests\TestCase;

class RbacBootstrapOwnerCommandTest extends TestCase
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

        $identity = $manager->install('identity');
        if (! $identity['success']) {
            $this->fail('Identity install failed: '.implode(' | ', $identity['messages']));
        }

        $rbac = $manager->install('rbac');
        if (! $rbac['success']) {
            $this->fail('RBAC install failed: '.implode(' | ', $rbac['messages']));
        }
    }

    private function createUser(string $email, string $name = 'Bootstrap Target'): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'secret-password',
        ]);
    }

    public function test_grant_by_email_creates_role_permission_and_assignment(): void
    {
        $this->installIdentityAndRbac();
        $user = $this->createUser('owner@example.com');

        $this->assertSame(0, DB::table('rbac_roles')->count());
        $this->assertSame(0, DB::table('rbac_user_role')->count());

        $this->artisan('rbac:bootstrap-owner', [
            '--email' => 'owner@example.com',
        ])
            ->expectsOutputToContain('RBAC owner bootstrap complete')
            ->assertSuccessful();

        $role = DB::table('rbac_roles')->where('name', 'rbac-manager')->first();
        $this->assertNotNull($role);

        $this->assertDatabaseHas('rbac_role_permission', [
            'role_id' => $role->id,
            'permission_id' => BootstrapOwnerCommand::BOOTSTRAP_PERMISSION,
        ]);

        $this->assertDatabaseHas('rbac_user_role', [
            'user_id' => (string) $user->id,
            'role_id' => $role->id,
        ]);

        $permissionIds = DB::table('rbac_role_permission')
            ->where('role_id', $role->id)
            ->pluck('permission_id')
            ->all();
        $this->assertSame([BootstrapOwnerCommand::BOOTSTRAP_PERMISSION], $permissionIds);
    }

    public function test_grant_by_user_id(): void
    {
        $this->installIdentityAndRbac();
        $user = $this->createUser('by-id@example.com');

        $this->artisan('rbac:bootstrap-owner', [
            '--user' => (string) $user->id,
        ])->assertSuccessful();

        $roleId = (int) DB::table('rbac_roles')->where('name', 'rbac-manager')->value('id');
        $this->assertDatabaseHas('rbac_user_role', [
            'user_id' => (string) $user->id,
            'role_id' => $roleId,
        ]);
    }

    public function test_missing_target_fails(): void
    {
        $this->installIdentityAndRbac();

        $this->artisan('rbac:bootstrap-owner')
            ->expectsOutputToContain('Provide exactly one of --email or --user.')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('rbac_roles')->count());
        $this->assertSame(0, DB::table('rbac_user_role')->count());
    }

    public function test_email_and_user_together_fails(): void
    {
        $this->installIdentityAndRbac();
        $user = $this->createUser('both@example.com');

        $this->artisan('rbac:bootstrap-owner', [
            '--email' => 'both@example.com',
            '--user' => (string) $user->id,
        ])
            ->expectsOutputToContain('Provide exactly one of --email or --user.')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('rbac_roles')->count());
    }

    public function test_unknown_user_fails(): void
    {
        $this->installIdentityAndRbac();

        $this->artisan('rbac:bootstrap-owner', [
            '--email' => 'missing@example.com',
        ])
            ->expectsOutputToContain("email 'missing@example.com'")
            ->assertExitCode(1);

        $this->artisan('rbac:bootstrap-owner', [
            '--user' => '99999',
        ])
            ->expectsOutputToContain("id '99999'")
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('rbac_roles')->count());
    }

    public function test_creates_or_reuses_role_and_is_idempotent(): void
    {
        $this->installIdentityAndRbac();
        $user = $this->createUser('idempotent@example.com');

        $this->artisan('rbac:bootstrap-owner', [
            '--email' => 'idempotent@example.com',
            '--role' => 'ops-manager',
        ])->assertSuccessful();

        $roleId = (int) DB::table('rbac_roles')->where('name', 'ops-manager')->value('id');
        $this->assertSame(1, DB::table('rbac_roles')->count());

        $this->artisan('rbac:bootstrap-owner', [
            '--email' => 'idempotent@example.com',
            '--role' => 'ops-manager',
        ])->assertSuccessful();

        $this->assertSame(1, DB::table('rbac_roles')->count());
        $this->assertSame(1, DB::table('rbac_role_permission')->where('role_id', $roleId)->count());
        $this->assertSame(1, DB::table('rbac_user_role')->where('user_id', (string) $user->id)->count());
        $this->assertSame($roleId, (int) DB::table('rbac_roles')->where('name', 'ops-manager')->value('id'));
    }

    public function test_another_user_receives_nothing(): void
    {
        $this->installIdentityAndRbac();
        $owner = $this->createUser('owner-only@example.com', 'Owner');
        $other = $this->createUser('other@example.com', 'Other');

        $this->artisan('rbac:bootstrap-owner', [
            '--email' => 'owner-only@example.com',
        ])->assertSuccessful();

        $roleId = (int) DB::table('rbac_roles')->where('name', 'rbac-manager')->value('id');

        $this->assertDatabaseHas('rbac_user_role', [
            'user_id' => (string) $owner->id,
            'role_id' => $roleId,
        ]);
        $this->assertDatabaseMissing('rbac_user_role', [
            'user_id' => (string) $other->id,
            'role_id' => $roleId,
        ]);
        $this->assertSame(0, DB::table('rbac_user_role')->where('user_id', (string) $other->id)->count());
    }

    public function test_does_not_grant_unrelated_permissions(): void
    {
        $this->installIdentityAndRbac();
        $this->createUser('perms@example.com');

        $registered = app(RoleManagementContract::class)->registeredPermissionIds();
        $this->assertContains(BootstrapOwnerCommand::BOOTSTRAP_PERMISSION, $registered);
        $this->assertNotEmpty($registered);

        $this->artisan('rbac:bootstrap-owner', [
            '--email' => 'perms@example.com',
        ])->assertSuccessful();

        $assigned = DB::table('rbac_role_permission')->pluck('permission_id')->all();
        $this->assertSame([BootstrapOwnerCommand::BOOTSTRAP_PERMISSION], $assigned);

        foreach ($registered as $permissionId) {
            if ($permissionId === BootstrapOwnerCommand::BOOTSTRAP_PERMISSION) {
                continue;
            }

            $this->assertNotContains($permissionId, $assigned);
        }
    }

    public function test_install_does_not_seed_privileges(): void
    {
        $this->installIdentityAndRbac();

        $this->assertSame(0, DB::table('rbac_roles')->count());
        $this->assertSame(0, DB::table('rbac_role_permission')->count());
        $this->assertSame(0, DB::table('rbac_user_role')->count());
    }

    public function test_command_does_not_exist_when_rbac_not_enabled(): void
    {
        try {
            Artisan::call('rbac:bootstrap-owner', ['--email' => 'x@example.com']);
            $this->fail('rbac:bootstrap-owner must not exist when RBAC is not enabled');
        } catch (CommandNotFoundException $e) {
            $this->assertStringContainsString('rbac:bootstrap-owner', $e->getMessage());
        }
    }

    public function test_disable_removes_contributions_and_command_on_fresh_boot(): void
    {
        $this->installIdentityAndRbac();
        $this->createUser('later@example.com');

        $this->artisan('rbac:bootstrap-owner', [
            '--email' => 'later@example.com',
        ])->assertSuccessful();

        $disable = app(ModuleManager::class)->disable('rbac');
        $this->assertTrue($disable['success'], implode(' | ', $disable['messages']));
        $this->assertEquals(ModuleState::Disabled, app(ModuleRegistrarContract::class)->getState('rbac'));
        $this->assertFalse(app(CapabilityRegistryContract::class)->has('authorization.permission'));
        $this->assertNull(
            collect(app(PermissionRegistryContract::class)->all())
                ->first(fn ($p) => $p->id === BootstrapOwnerCommand::BOOTSTRAP_PERMISSION),
        );

        // Fresh process semantics: clear static Artisan::starting()
        // bootstrappers accumulated in this PHPUnit process, then reboot
        // so only enabled modules re-contribute commands.
        \Illuminate\Console\Application::forgetBootstrappers();
        $this->refreshApplication();

        $this->assertEquals(
            ModuleState::Disabled,
            app(ModuleRegistrarContract::class)->getState('rbac'),
        );
        $this->assertFalse(app(CapabilityRegistryContract::class)->has('authorization.permission'));
        $this->assertFalse(
            array_key_exists('rbac:bootstrap-owner', Artisan::all()),
            'rbac:bootstrap-owner must not be registered when RBAC is disabled on a fresh boot',
        );

        try {
            Artisan::call('rbac:bootstrap-owner', ['--email' => 'later@example.com']);
            $this->fail('rbac:bootstrap-owner must not exist after RBAC is disabled (fresh boot)');
        } catch (CommandNotFoundException $e) {
            $this->assertStringContainsString('rbac:bootstrap-owner', $e->getMessage());
        }
    }
}
