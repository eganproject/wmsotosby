<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class RoleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:roles.view', only: ['index']),
            new Middleware('can:roles.create', only: ['create', 'store']),
            new Middleware('can:roles.update', only: ['edit', 'update']),
            new Middleware('can:roles.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $roles = Role::query()
            ->withCount(['users', 'permissions'])
            ->when($request->string('search')->trim()->value(), function ($query, string $search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('admin.roles.index', compact('roles'));
    }

    public function create(): View
    {
        return view('admin.roles.create', [
            'permissionGroups' => $this->permissionGroups(),
            'assigned' => [],
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $role = Role::create($data);
        $role->permissions()->sync($data['permissions'] ?? []);

        return redirect()
            ->route('admin.roles.index')
            ->with('success', 'Role berhasil ditambahkan.');
    }

    public function edit(Role $role): View
    {
        return view('admin.roles.edit', [
            'role' => $role,
            'permissionGroups' => $this->permissionGroups(),
            'assigned' => $role->permissions->pluck('id')->all(),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $data = $request->validated();

        $role->update($data);
        $role->permissions()->sync($data['permissions'] ?? []);

        return redirect()
            ->route('admin.roles.index')
            ->with('success', 'Role berhasil diperbarui.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_super_admin) {
            return back()->with('error', 'Role super admin tidak dapat dihapus.');
        }

        if ($role->users()->exists()) {
            return back()->with('error', 'Role masih digunakan oleh pengguna lain.');
        }

        $role->delete();

        return redirect()
            ->route('admin.roles.index')
            ->with('success', 'Role berhasil dihapus.');
    }

    /**
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Permission>>
     */
    protected function permissionGroups()
    {
        return Permission::orderBy('id')->get()->groupBy('group');
    }
}
