<x-foundation::app-shell>
    <x-slot:header>
        <h2 class="text-lg font-semibold text-gray-800 dark:text-white">Staff Users</h2>
    </x-slot:header>

    @include('foundation::components.page-header', [
        'title' => 'Staff Users',
        'subtitle' => 'Create and manage staff accounts and their roles.',
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

    @can('rbac.users.manage')
        <div class="mb-6 flex justify-end">
            <x-foundation::button tag="a" href="{{ route('rbac.users.create') }}">Create Staff User</x-foundation::button>
        </div>
    @endcan

    <x-foundation::card title="All Staff Users">
        @forelse ($users as $user)
            <div class="flex items-center justify-between border-b border-gray-100 py-3 dark:border-gray-700 last:border-0">
                <div>
                    <p class="font-medium text-gray-900 dark:text-white">{{ $user->name }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $user->email }}</p>
                    <p class="mt-1 text-xs text-gray-400">
                        @if (empty($roleNamesByUserId[$user->id]))
                            No roles assigned
                        @else
                            {{ implode(', ', $roleNamesByUserId[$user->id]) }}
                        @endif
                    </p>
                </div>
                <a href="{{ route('rbac.users.edit', $user->id) }}"
                   class="text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400">
                    Manage
                </a>
            </div>
        @empty
            <x-foundation::empty-state
                title="No staff users yet"
                description="Create the first staff account to get started."
            />
        @endforelse
    </x-foundation::card>
</x-foundation::app-shell>
