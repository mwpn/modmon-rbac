<x-foundation::app-shell>
    <x-slot:header>
        <h2 class="text-lg font-semibold text-gray-800 dark:text-white">Pengguna Staf</h2>
    </x-slot:header>

    @include('foundation::components.page-header', [
        'title' => "Edit Pengguna Staf: {$user->name}",
        'subtitle' => 'Perbarui data akun dan peran yang dimiliki.',
    ])

    @if (session('status'))
        <x-foundation::alert type="success" class="mb-4">{{ session('status') }}</x-foundation::alert>
    @endif

    @if ($errors->any())
        <x-foundation::alert type="error" class="mb-4">
            <ul class="list-disc pl-4">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-foundation::alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">

        <x-foundation::card title="Data Akun">
            <form method="POST" action="{{ route('rbac.users.update', $user->id) }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nama</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required
                           class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required
                           class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Password Baru</label>
                    <input id="password" name="password" type="password" minlength="8"
                           class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
                    <p class="mt-1 text-xs text-gray-400">Biarkan kosong untuk tetap memakai password yang lama.</p>
                </div>

                <div class="flex gap-3">
                    <x-foundation::button tag="button" type="submit">Simpan</x-foundation::button>
                </div>
            </form>

            <div class="mt-4">
                <x-foundation::confirm-delete
                    :action="route('rbac.users.destroy', $user->id)"
                    title="Hapus pengguna staf ini?"
                    message="Tindakan ini tidak bisa dibatalkan."
                    label="Hapus"
                />
            </div>
        </x-foundation::card>

        <x-foundation::card title="Peran">
            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-400">Sudah Diberikan</h4>
            @php $hasAssignedRole = false; @endphp
            @foreach ($roles as $role)
                @continue (! in_array((int) $role->id, $assignedRoleIds, true))
                @php $hasAssignedRole = true; @endphp
                <div class="mb-2 flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ $role->name }}</span>
                    <form method="POST" action="{{ route('rbac.users.roles.remove', $user->id) }}">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="role_id" value="{{ $role->id }}" />
                        <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700 dark:text-red-400">Hapus</button>
                    </form>
                </div>
            @endforeach
            @unless ($hasAssignedRole)
                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">Belum ada peran yang diberikan.</p>
            @endunless

            @php $hasUnassignedRole = false; @endphp
            @foreach ($roles as $role)
                @if (! in_array((int) $role->id, $assignedRoleIds, true))
                    @php $hasUnassignedRole = true; @endphp
                @endif
            @endforeach

            @if (empty($roles))
                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">Belum ada peran yang dibuat.</p>
            @elseif ($hasUnassignedRole)
                <h4 class="mb-2 mt-4 text-xs font-semibold uppercase tracking-wider text-gray-400">Berikan Peran</h4>
                <form method="POST" action="{{ route('rbac.users.roles.assign', $user->id) }}" class="flex gap-2">
                    @csrf
                    <select name="role_id" required
                            class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                        @foreach ($roles as $role)
                            @if (! in_array((int) $role->id, $assignedRoleIds, true))
                                <option value="{{ $role->id }}">{{ $role->name }}</option>
                            @endif
                        @endforeach
                    </select>
                    <x-foundation::button tag="button" type="submit" size="sm">Berikan</x-foundation::button>
                </form>
            @endif
        </x-foundation::card>

    </div>
</x-foundation::app-shell>
