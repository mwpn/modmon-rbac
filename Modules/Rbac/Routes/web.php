<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Rbac\Http\Controllers\RoleController;
use Modules\Rbac\Http\Controllers\StaffUserController;
use Modules\Rbac\Http\Controllers\UserRoleController;

/*
|--------------------------------------------------------------------------
| RBAC admin surface
|--------------------------------------------------------------------------
|
| All management routes are protected by the `rbac.roles.manage`
| permission through the Laravel Gate (Phase 2 integration) — the
| single authorization path. No role CRUD is exposed to unauthenticated
| or unauthorized users.
*/

Route::prefix('rbac')->middleware(['auth', 'can:rbac.roles.manage'])->group(function () {
    Route::get('/roles', [RoleController::class, 'index'])->name('rbac.roles.index');
    Route::get('/roles/create', [RoleController::class, 'create'])->name('rbac.roles.create');
    Route::post('/roles', [RoleController::class, 'store'])->name('rbac.roles.store');
    Route::get('/roles/{role}', [RoleController::class, 'edit'])->name('rbac.roles.edit');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->name('rbac.roles.update');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('rbac.roles.destroy');

    Route::post('/roles/{role}/permissions', [RoleController::class, 'assignPermission'])
        ->name('rbac.roles.permissions.assign');
    Route::delete('/roles/{role}/permissions', [RoleController::class, 'removePermission'])
        ->name('rbac.roles.permissions.remove');

    Route::post('/roles/{role}/users', [UserRoleController::class, 'assign'])
        ->name('rbac.roles.users.assign');
    Route::delete('/roles/{role}/users', [UserRoleController::class, 'remove'])
        ->name('rbac.roles.users.remove');
});

/*
|--------------------------------------------------------------------------
| Staff user management (ADR-0042)
|--------------------------------------------------------------------------
|
| `rbac.users.view`/`.manage` are separate permissions from
| `rbac.roles.manage` so a host can grant "see/edit staff accounts"
| without also granting "redefine every role's permissions".
*/
Route::prefix('rbac/users')->middleware(['auth', 'can:rbac.users.view'])->group(function () {
    Route::get('/', [StaffUserController::class, 'index'])->name('rbac.users.index');
    Route::get('/create', [StaffUserController::class, 'create'])
        ->middleware('can:rbac.users.manage')
        ->name('rbac.users.create');
    Route::post('/', [StaffUserController::class, 'store'])
        ->middleware('can:rbac.users.manage')
        ->name('rbac.users.store');
    Route::get('/{user}', [StaffUserController::class, 'edit'])->name('rbac.users.edit');
    Route::put('/{user}', [StaffUserController::class, 'update'])
        ->middleware('can:rbac.users.manage')
        ->name('rbac.users.update');
    Route::delete('/{user}', [StaffUserController::class, 'destroy'])
        ->middleware('can:rbac.users.manage')
        ->name('rbac.users.destroy');
    Route::post('/{user}/roles', [StaffUserController::class, 'assignRole'])
        ->middleware('can:rbac.users.manage')
        ->name('rbac.users.roles.assign');
    Route::delete('/{user}/roles', [StaffUserController::class, 'removeRole'])
        ->middleware('can:rbac.users.manage')
        ->name('rbac.users.roles.remove');
});
