<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Farmer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class FarmerProfileController extends Controller
{
    public function show()
    {
        $user = Auth::user();

        if ($user->role !== 'farmer') {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $profile = Farmer::where('user_id', $user->id)->first();

        return response()->json([
            'profile' => $profile ?? null
        ]);
    }

    public function update(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->role !== 'farmer') {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'farm_name' => 'nullable|string|max:255',
            'city'      => 'nullable|string|max:100',
            'bio'       => 'nullable|string',
            'phone'     => 'nullable|string|max:20',
        ]);

        // phone va en tabla users
        if (array_key_exists('phone', $validated)) {
            $user->phone = $validated['phone'];
            $user->save();
            unset($validated['phone']);
        }

        // farm_name, city, bio van en farmer_profiles
        Farmer::updateOrCreate(
            ['user_id' => $user->id],
            $validated
        );

        return response()->json(['message' => 'Perfil actualizado correctamente']);
    }
}
