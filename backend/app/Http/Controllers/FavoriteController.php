<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Product;

class FavoriteController extends Controller
{
    public function toggle($id)
    {
        
        /** @var \App\Models\User $user */
        $user = Auth::user(); 

        $product = Product::findOrFail($id);

        $changes = $user->favorites()->toggle($id);

        $esFavorito = count($changes['attached']) > 0;

        return response()->json([
            'status' => 'success',
            'message' => $esFavorito ? 'Añadido a favoritos' : 'Eliminado de favoritos',
            'is_favorite' => $esFavorito
        ]);
    }

    public function index()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $favorites = $user->favorites()->with(['images', 'farmer.user'])->get();

        return response()->json($favorites);
    }
}