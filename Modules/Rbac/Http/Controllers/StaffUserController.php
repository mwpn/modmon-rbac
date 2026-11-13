<?php

declare(strict_types=1);

namespace Modules\Rbac\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Identity\Domain\Contracts\UserManagementContract;
use Modules\Rbac\Domain\Contracts\RoleManagementContract;

/**
 * Staff account management — the web-UI replacement for the
 * `identity:user:create` CLI command (ADR-0042). Lives in RBAC, not
 * Identity: Identity contributes no permissions/navigation/UI of its
 * own (enforced by IdentityBoundaryTest, by design — it must install
 * standalone, below RBAC, with no authorization concept). RBAC already
 * requires `identity.user` and is where every other permission-gated
 * admin screen in this Foundation lives, so it is the natural home for
 * a screen that creates/edits/deletes Identity users and assigns them
 * RBAC roles in one place.
 *
 * Writes go through the public `UserManagementContract` only — never
 * Identity's Eloquent `User` model.
 */
final class StaffUserController extends RbacController
{
    public function __construct(
        private readonly UserManagementContract $users,
        private readonly RoleManagementContract $roles,
    ) {}

    public function index(): View
    {
        $users = $this->users->all();
        $roleNames = $this->roleNamesById();

        $roleNamesByUserId = [];
        foreach ($users as $user) {
            $roleIds = $this->roles->userRoleIds((string) $user->id);
            $roleNamesByUserId[$user->id] = array_values(array_filter(
                array_map(fn ($roleId) => $roleNames[(int) $roleId] ?? null, $roleIds),
            ));
        }

        return view('rbac::users.index', [
            'users' => $users,
            'roleNamesByUserId' => $roleNamesByUserId,
        ]);
    }

    public function create(): View
    {
        return view('rbac::users.create');
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $user = $this->users->create($request->only(['name', 'email', 'password']));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('rbac.users.edit', $user->id)
            ->with('status', "User '{$user->email}' created.");
    }

    public function edit(int $user): View|RedirectResponse
    {
        $record = $this->users->find($user);

        if ($record === null) {
            return redirect()->route('rbac.users.index')->withErrors(['user' => "User '{$user}' was not found."]);
        }

        return view('rbac::users.edit', [
            'user' => $record,
            'roles' => $this->roles->all(),
            'assignedRoleIds' => array_map('intval', $this->roles->userRoleIds((string) $record->id)),
        ]);
    }

    public function update(Request $request, int $user): RedirectResponse
    {
        try {
            $this->users->update($user, $request->only(['name', 'email', 'password']));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('rbac.users.edit', $user)
            ->with('status', 'User updated.');
    }

    public function destroy(Request $request, int $user): RedirectResponse
    {
        if ($user === (int) $request->user()?->getAuthIdentifier()) {
            return redirect()
                ->route('rbac.users.index')
                ->withErrors(['user' => 'You cannot delete your own account.']);
        }

        try {
            $this->users->delete($user);
        } catch (ValidationException $e) {
            return redirect()->route('rbac.users.index')->withErrors($e->errors());
        }

        return redirect()->route('rbac.users.index')->with('status', 'User deleted.');
    }

    public function assignRole(Request $request, int $user): RedirectResponse
    {
        $validated = $request->validate(['role_id' => ['required', 'integer']]);

        $this->roles->assignRoleToUser((string) $user, (int) $validated['role_id']);

        return redirect()->route('rbac.users.edit', $user)->with('status', 'Role assigned.');
    }

    public function removeRole(Request $request, int $user): RedirectResponse
    {
        $validated = $request->validate(['role_id' => ['required', 'integer']]);

        $this->roles->removeRoleFromUser((string) $user, (int) $validated['role_id']);

        return redirect()->route('rbac.users.edit', $user)->with('status', 'Role removed.');
    }

    /**
     * @return array<int, string>
     */
    private function roleNamesById(): array
    {
        $names = [];
        foreach ($this->roles->all() as $role) {
            $names[(int) $role->id] = $role->name;
        }

        return $names;
    }
}
