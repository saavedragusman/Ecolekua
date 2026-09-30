<?php

namespace App\Http\Controllers\Roles;

use App\Actions\Roles\CreateRole;
use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\UpdateRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Role::class);

        return Inertia::render('roles/Index', [
            'roles' => Role::query()
                ->withCount(['users', 'permissions'])
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role): array => $this->summary($role))
                ->all(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Role::class);

        return Inertia::render('roles/Create');
    }

    public function store(StoreRoleRequest $request, CreateRole $createRole): RedirectResponse
    {
        $role = $createRole->handle($request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El rol fue creado.']);

        return redirect()->route('roles.show', $role);
    }

    public function show(Role $role): Response
    {
        Gate::authorize('view', $role);

        $role->loadCount('users')->load('permissions:id,name,description');

        return Inertia::render('roles/Show', [
            'role' => [
                ...$this->summary($role),
                'permissions' => $role->permissions->sortBy('name')->values()
                    ->map(fn (Permission $permission): array => $this->permission($permission))->all(),
            ],
            // Catalog for the permissions form: only sent to those who may manage roles.
            'permissions' => Gate::allows('update', $role)
                ? Permission::query()->orderBy('name')->get()->map(fn (Permission $permission): array => $this->permission($permission))->all()
                : [],
        ]);
    }

    public function edit(Role $role): Response
    {
        Gate::authorize('update', $role);

        return Inertia::render('roles/Edit', ['role' => $this->summary($role)]);
    }

    public function update(UpdateRoleRequest $request, Role $role, UpdateRole $updateRole): RedirectResponse
    {
        $updateRole->handle($role, $request->validated(), $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'Los datos del rol fueron actualizados.']);

        return redirect()->route('roles.show', $role);
    }

    public function destroy(Request $request, Role $role, DeleteRole $deleteRole): RedirectResponse
    {
        Gate::authorize('delete', $role);

        $deleteRole->handle($role, $request->user());

        Inertia::flash(['type' => 'success', 'message' => 'El rol fue eliminado.']);

        return redirect()->route('roles.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'is_protected' => $role->is_protected,
            'users_count' => $role->users_count ?? null,
            'permissions_count' => $role->permissions_count ?? null,
        ];
    }

    /**
     * @return array{id: int, name: string, description: string}
     */
    private function permission(Permission $permission): array
    {
        return ['id' => $permission->id, 'name' => $permission->name, 'description' => $permission->description];
    }
}
