<?php

namespace App\Http\Controllers;

use App\Support\StoreAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class StaffUserController extends Controller
{
    public function handle(Request $r)
    {
        abort_unless(StoreAccess::allows(Auth::user(), 'users.manage'), 403, 'User management permission required.');
        if ($r->isMethod('GET')) return response()->json(['users' => DB::table('users')->orderBy('id')->get(['id', 'name', 'email', 'role', 'active', 'permissions', 'created_at', 'last_login_at'])->map(function ($u) { $u->effective_permissions = StoreAccess::permissions($u); return $u; }), 'roles' => StoreAccess::ROLES, 'permissions' => StoreAccess::PERMISSIONS]);
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        if ($r->isMethod('DELETE')) {
            $r->validate(['id' => 'required|integer|exists:users,id']);
        } else {
            $r->validate(['id' => $r->isMethod('PUT') ? 'required|integer|exists:users,id' : 'prohibited', 'name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($r->input('id'))], 'role' => ['required', Rule::in([...StoreAccess::ROLES, 'customer'])], 'active' => 'required|boolean', 'password' => ($r->isMethod('POST') ? 'required' : 'nullable').'|string|min:12|max:128', 'permissions' => 'nullable|array', 'permissions.*' => [Rule::in(StoreAccess::PERMISSIONS)]]);
        }
        return DB::transaction(function () use ($r) {
            // Serialize privileged-account changes and prevent locking out the last owner.
            DB::table('users')->where('role', 'super_admin')->orderBy('id')->lockForUpdate()->get();
            $old = $r->id ? DB::table('users')->where('id', $r->id)->lockForUpdate()->first() : null;
            abort_if($old?->role === 'vendor', 422, 'Manage vendor accounts in Vendor Inventory.');
            if ($old && $old->id === Auth::id()) abort_if($r->isMethod('DELETE') || ! $r->boolean('active') || $r->role !== 'super_admin', 422, 'You cannot delete, disable or demote your own account.');
            if ($old && $old->role === 'super_admin' && $old->active && ($r->isMethod('DELETE') || ! $r->boolean('active') || $r->role !== 'super_admin')) {
                abort_if(DB::table('users')->where('role', 'super_admin')->where('active', true)->count() <= 1, 422, 'Keep at least one active Super Admin.');
            }
            if ($r->isMethod('DELETE')) {
                DB::table('users')->where('id', $r->id)->delete();
            } else {
                $v = $r->only(['name', 'email', 'role', 'active']);
                $v['permissions'] = $r->input('permissions') === null ? null : json_encode($r->permissions);
                if ($r->filled('password')) $v['password_hash'] = Hash::make($r->password);
                if ($old) {
                    $v['auth_version'] = $old->auth_version + 1;
                    DB::table('users')->where('id', $old->id)->update($v);
                    if ($old->id === Auth::id()) $r->session()->put('auth_version', $v['auth_version']);
                } else DB::table('users')->insert([...$v, 'created_at' => now()]);
            }
            return response()->json(['ok' => true]);
        });
    }
}
