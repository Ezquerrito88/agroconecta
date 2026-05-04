<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Farmer;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('farmer')->latest();

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name',  'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            });
        }

        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $users = $query->paginate($request->get('per_page', 15));

        return response()->json($users);
    }

    public function show($id)
    {
        $user = User::with('farmer')->findOrFail($id);
        return response()->json($user);
    }

    public function updateRole(Request $request, $id)
    {
        $request->validate([
            'role' => 'required|in:admin,farmer,buyer',
        ]);

        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'No puedes cambiar tu propio rol.'], 422);
        }

        $user->update(['role' => $request->role]);

        return response()->json([
            'message' => 'Rol actualizado correctamente.',
            'user'    => $user,
        ]);
    }

    public function toggleActive($id)
    {
        $user = User::findOrFail($id);
        $user->is_active = !$user->is_active;
        $user->save();

        return response()->json(['is_active' => $user->is_active]);
    }

    public function destroy(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta.'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'Usuario eliminado correctamente.']);
    }

    public function updateUser(Request $request, $id)
    {
        $user = User::with('farmer')->findOrFail($id);

        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|unique:users,email,' . $id,
            'role'      => 'required|in:admin,farmer,buyer',
            'phone'     => 'nullable|string|max:20',
            'address'   => 'nullable|string|max:500',
            'is_active' => 'required|boolean',
            'password'  => 'nullable|string|min:8',
            'farm_name'   => 'nullable|string|max:255',
            'city'        => 'nullable|string|max:255',
            'is_verified' => 'nullable|boolean',
            'bio'         => 'nullable|string|max:1000',
        ]);

        // Actualizar campos del user
        $user->name      = $validated['name'];
        $user->email     = $validated['email'];
        $user->role      = $validated['role'];
        $user->phone     = $validated['phone'] ?? null;
        $user->address   = $validated['address'] ?? null;
        $user->is_active = $validated['is_active'];

        if (!empty($validated['password'])) {
            $user->password = bcrypt($validated['password']);
        }

        $user->save();

        // Actualizar datos del farmer si el rol es farmer
        if ($validated['role'] === 'farmer') {
            Farmer::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'farm_name'   => $validated['farm_name']   ?? null,
                    'city'        => $validated['city']        ?? null,
                    'is_verified' => $validated['is_verified'] ?? false,
                    'bio'         => $validated['bio']         ?? null,
                ]
            );
        }

        return response()->json([
            'message' => 'Usuario actualizado',
            'user'    => $user->load('farmer'),
        ]);
    }
}