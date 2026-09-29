<?php

namespace App\Http\Controllers\Users;

use App\Actions\Users\CreateUser;
use App\Actions\Users\UpdateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', User::class);

        return Inertia::render('users/Index', [
            'users' => User::query()
                ->with('roles:id,name')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->orderBy('id')
                ->paginate(15)
                ->through(fn (User $user): array => $this->summary($user))
                ->withQueryString(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('users/Create', ['roles' => $this->roleOptions()]);
    }

    public function store(StoreUserRequest $request, CreateUser $createUser): RedirectResponse
    {
        $user = $createUser->handle($request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El usuario fue creado. Deberá cambiar su contraseña temporal al iniciar sesión.']);

        return redirect()->route('users.show', $user);
    }

    public function show(User $user): Response
    {
        Gate::authorize('view', $user);

        return Inertia::render('users/Show', [
            'user' => $this->summary($user->load('roles:id,name')),
            // Options for the role form: only sent to those who may assign roles.
            'roles' => Gate::allows('assignRoles', $user) ? $this->roleOptions() : [],
        ]);
    }

    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('users/Edit', ['user' => $this->summary($user)]);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $updateUser): RedirectResponse
    {
        $updateUser->handle($user, $request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'Los datos del usuario fueron actualizados.']);

        return redirect()->route('users.show', $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(User $user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'must_change_password' => $user->must_change_password,
            'roles' => $user->relationLoaded('roles')
                ? $user->roles->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name])->all()
                : [],
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function roleOptions(): array
    {
        return array_values(Role::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name])
            ->all());
    }
}
