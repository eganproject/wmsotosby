<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class PermissionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:permissions.view', only: ['index']),
            new Middleware('can:permissions.update', only: ['update']),
        ];
    }

    /**
     * Permission matrix: every permission as a row, every role as a column.
     */
    public function index(): View
    {
        $roles = Role::with('permissions')->orderBy('name')->get();

        $permissionGroups = Permission::orderBy('id')->get()->groupBy('group');

        $matrix = $roles->mapWithKeys(fn (Role $role) => [
            $role->id => $role->permissions->pluck('id')->all(),
        ]);

        return view('admin.permissions.index', compact('roles', 'permissionGroups', 'matrix'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'matrix' => ['array'],
            'matrix.*' => ['array'],
            'matrix.*.*' => ['integer', 'exists:permissions,id'],
        ]);

        $matrix = $validated['matrix'] ?? [];

        Role::where('is_super_admin', false)->get()->each(function (Role $role) use ($matrix) {
            $role->permissions()->sync($matrix[$role->id] ?? []);
        });

        return redirect()
            ->route('admin.permissions.index')
            ->with('success', 'Hak akses berhasil disimpan.');
    }
}
