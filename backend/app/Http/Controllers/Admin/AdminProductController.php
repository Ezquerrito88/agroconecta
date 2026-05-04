<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminProductController extends Controller
{
    // ── Helpers ───────────────────────────────────
    private function normalizeImages($images)
    {
        return $images->transform(function ($img) {
            $path = $img->image_path;

            if (!$path) {
                $img->url = asset('img/default-product.png');
            } elseif (filter_var($path, FILTER_VALIDATE_URL)) {
                $img->url = $path;
            } else {
                $disk = config('filesystems.default', 'public');

                if ($disk === 'azure') {
                    $account   = config('filesystems.disks.azure.name');
                    $container = config('filesystems.disks.azure.container');
                    $img->url = "https://{$account}.blob.core.windows.net/{$container}/{$path}";
                } else {
                    $img->url = asset('storage/' . $path);
                }
            }
            return $img;
        });
    }

    // ── Index ─────────────────────────────────────
    public function index(Request $request)
    {
        $query = Product::with(['farmer.user', 'category', 'images'])->latest();

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        if ($request->filled('status')) {
            $query->where('moderation_status', $request->status);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->paginate($request->get('per_page', 15));

        $products->getCollection()->transform(function ($product) {
            $this->normalizeImages($product->images);
            return $product;
        });

        return response()->json($products);
    }

    // ── Show ──────────────────────────────────────
    public function show($id)
    {
        $product = Product::with(['images', 'category', 'farmer.user'])
            ->findOrFail($id);

        $this->normalizeImages($product->images);

        return response()->json($product);
    }

    // ── Update ────────────────────────────────────
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $product->update($request->only([
            'name',
            'description',
            'short_description',
            'price',
            'unit',
            'stock_quantity',
            'season_end',
            'category_id',
            'moderation_status',
            'rejection_reason',
        ]));

        // Limpiar rejection_reason si se aprueba
        if ($request->moderation_status === 'approved') {
            $product->update(['rejection_reason' => null]);
        }

        $product->load(['images', 'category', 'farmer.user']);
        $this->normalizeImages($product->images);

        return response()->json($product);
    }

    // ── Upload Images ─────────────────────────────
    public function uploadImages(Request $request, $id)
    {
        $product = Product::findOrFail($id);
        $disk = config('filesystems.default', 'public');

        $images = [];
        foreach ($request->file('images', []) as $file) {
            $path = $file->store("products/{$id}", $disk);

            $image = $product->images()->create([
                'image_path' => $path,
                'order'      => $product->images()->count(),
            ]);

            $image->url = ($disk === 'azure')
                ? "https://" . config('filesystems.disks.azure.name') . ".blob.core.windows.net/" . config('filesystems.disks.azure.container') . "/{$path}"
                : asset('storage/' . $path);

            $images[] = $image;
        }

        return response()->json(['images' => $images]);
    }

    // ── Destroy Image ─────────────────────────────
    public function destroyImage($productId, $imageId)
    {
        $product = Product::findOrFail($productId);
        $image   = $product->images()->findOrFail($imageId);

        Storage::disk('public')->delete($image->image_path);
        $image->delete();

        return response()->json(['message' => 'Imagen eliminada']);
    }

    // ── Approve ───────────────────────────────────
    public function approve($id)
    {
        $product = Product::findOrFail($id);
        $product->update([
            'moderation_status' => 'approved',
            'rejection_reason'  => null,
        ]);

        return response()->json([
            'message' => 'Producto aprobado correctamente.',
            'product' => $product,
        ]);
    }

    // ── Reject ────────────────────────────────────
    public function reject(Request $request, $id)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $product = Product::findOrFail($id);
        $product->update([
            'moderation_status' => 'rejected',
            'rejection_reason'  => $request->reason,
        ]);

        return response()->json([
            'message' => 'Producto rechazado.',
            'product' => $product,
        ]);
    }

    // ── Destroy ───────────────────────────────────
    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return response()->json(['message' => 'Producto eliminado correctamente.']);
    }
}
