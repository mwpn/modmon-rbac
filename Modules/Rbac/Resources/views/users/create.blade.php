<x-foundation::app-shell>
    <x-slot:header>
        <h2 class="text-lg font-semibold text-gray-800 dark:text-white">Pengguna Staf</h2>
    </x-slot:header>

    @include('foundation::components.page-header', [
        'title' => 'Tambah Pengguna Staf',
        'subtitle' => 'Buat akun staf baru.',
    ])

    @if ($errors->any())
        <x-foundation::alert type="error" class="mb-4">
            <ul class="list-disc pl-4">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-foundation::alert>
    @endif

    <x-foundation::card>
        <form method="POST" action="{{ route('rbac.users.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nama</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required
                       class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required
                       class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Password</label>
                <input id="password" name="password" type="password" required minlength="8"
                       class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
                <p class="mt-1 text-xs text-gray-400">Minimal 8 karakter.</p>
            </div>

            <div class="flex gap-3">
                <x-foundation::button tag="button" type="submit">Buat</x-foundation::button>
                <x-foundation::button tag="a" href="{{ route('rbac.users.index') }}" type="secondary">Batal</x-foundation::button>
            </div>
        </form>
    </x-foundation::card>
</x-foundation::app-shell>
