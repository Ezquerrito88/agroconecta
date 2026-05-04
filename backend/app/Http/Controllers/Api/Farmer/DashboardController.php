<?php

namespace App\Http\Controllers\Api\Farmer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function farmerStats(Request $request)
    {
        $user      = $request->user();
        $farmerId  = $user->farmer?->id ?? $user->id;
        $now       = Carbon::now();
        $thisYear  = $now->year;
        $prevYear  = $now->year - 1;

        $disk = config('filesystems.default', 'public');
        if ($disk === 'azure') {
            $account = config('filesystems.disks.azure.name');
            $container = config('filesystems.disks.azure.container');
            $baseUrl = "https://{$account}.blob.core.windows.net/{$container}/";
        } else {
            $baseUrl = asset('storage/') . '/';
        }


        $pedidos = DB::table('orders')
            ->where('farmer_id', $farmerId)
            ->whereIn('status', ['pending', 'processing'])
            ->whereYear('created_at',  $thisYear)
            ->whereMonth('created_at', $now->month)
            ->count();

        $ventas = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('orders',   'order_items.order_id',   '=', 'orders.id')
            ->where('products.farmer_id', $farmerId)
            ->whereNotIn('orders.status', ['cancelled'])
            ->whereYear('orders.created_at',  $thisYear)
            ->whereMonth('orders.created_at', $now->month)
            ->sum(DB::raw('order_items.quantity * order_items.price'));

        $agotados = DB::table('products')
            ->where('farmer_id', $farmerId)
            ->where('moderation_status', 'approved')
            ->where('stock_quantity', 0)
            ->count();

        $calificacion = DB::table('reviews')
            ->join('products', 'reviews.product_id', '=', 'products.id')
            ->where('products.farmer_id', $farmerId)
            ->avg('reviews.rating') ?? 0;


        $monthlyRevenue = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('orders',   'order_items.order_id',   '=', 'orders.id')
            ->where('products.farmer_id', $farmerId)
            ->whereNotIn('orders.status', ['cancelled'])
            ->whereYear('orders.created_at', $thisYear)
            ->selectRaw('
                YEAR(orders.created_at)                       AS year,
                MONTH(orders.created_at)                      AS month,
                SUM(order_items.quantity * order_items.price) AS total,
                COUNT(DISTINCT orders.id)                     AS orders_count
            ')
            ->groupByRaw('YEAR(orders.created_at), MONTH(orders.created_at)')
            ->orderByRaw('YEAR(orders.created_at), MONTH(orders.created_at)')
            ->get();


        $prevYearRevenue = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('orders',   'order_items.order_id',   '=', 'orders.id')
            ->where('products.farmer_id', $farmerId)
            ->whereNotIn('orders.status', ['cancelled'])
            ->whereYear('orders.created_at', $prevYear)
            ->selectRaw('
                MONTH(orders.created_at)                      AS month,
                SUM(order_items.quantity * order_items.price) AS total
            ')
            ->groupByRaw('MONTH(orders.created_at)')
            ->get()
            ->keyBy('month');


        $lowStock = DB::table('products')
            ->where('farmer_id', $farmerId)
            ->where('moderation_status', 'approved')
            ->where('stock_quantity', '>', 0)
            ->where('stock_quantity', '<=', 10)
            ->select(
                'id',
                'name',
                'stock_quantity as stock',
                'unit',
                DB::raw("(SELECT image_path FROM product_images WHERE product_id = products.id ORDER BY id ASC LIMIT 1) as main_image")
            )
            ->orderBy('stock_quantity')
            ->limit(8)
            ->get()
            ->map(function ($p) use ($baseUrl) {
                $p->image = $p->main_image ? $baseUrl . $p->main_image : asset('img/placeholder.png');
                return $p;
            });


        $topProducts = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('orders',   'order_items.order_id',   '=', 'orders.id')
            ->leftJoin('reviews', 'reviews.product_id', '=', 'products.id')
            ->where('products.farmer_id', $farmerId)
            ->whereNotIn('orders.status', ['cancelled'])
            ->selectRaw("
                products.id,
                products.name,
                products.price,
                products.unit,
                (SELECT image_path FROM product_images WHERE product_id = products.id ORDER BY id ASC LIMIT 1) as main_image,
                COALESCE(AVG(reviews.rating), 0) AS rating,
                SUM(order_items.quantity)         AS sold
            ")
            ->groupBy('products.id', 'products.name', 'products.price', 'products.unit')
            ->orderByDesc('sold')
            ->limit(5)
            ->get()
            ->map(function ($p) use ($baseUrl) {
                $p->image = $p->main_image ? $baseUrl . $p->main_image : asset('img/placeholder.png');
                return $p;
            });


        $heatmap = DB::table('orders')
            ->where('farmer_id', $farmerId)
            ->whereNotIn('status', ['cancelled'])
            ->whereYear('created_at', $thisYear)
            ->selectRaw('DAYOFWEEK(created_at) AS day_of_week, HOUR(created_at) AS hour, COUNT(id) AS count')
            ->groupByRaw('DAYOFWEEK(created_at), HOUR(created_at)')
            ->get();

        $scatterProducts = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('orders',   'order_items.order_id',   '=', 'orders.id')
            ->where('products.farmer_id', $farmerId)
            ->whereNotIn('orders.status', ['cancelled'])
            ->selectRaw('products.name, products.price, SUM(order_items.quantity) as sold')
            ->groupBy('products.id', 'products.name', 'products.price')
            ->get();

        $stackedByCategory = DB::table('order_items')
            ->join('products',   'order_items.product_id', '=', 'products.id')
            ->join('orders',     'order_items.order_id',   '=', 'orders.id')
            ->join('categories', 'products.category_id',   '=', 'categories.id')
            ->where('products.farmer_id', $farmerId)
            ->whereNotIn('orders.status', ['cancelled'])
            ->whereYear('orders.created_at', $thisYear)
            ->selectRaw('categories.name AS category, MONTH(orders.created_at) AS month, SUM(order_items.quantity * order_items.price) AS total')
            ->groupByRaw('categories.id, categories.name, MONTH(orders.created_at)')
            ->orderByRaw('MONTH(orders.created_at)')
            ->get();


        return response()->json([
            'kpis' => [
                'pedidos'      => $pedidos,
                'ventas'       => round((float) $ventas, 2),
                'agotados'     => $agotados,
                'calificacion' => round((float) $calificacion, 1),
            ],
            'monthly_revenue'     => $monthlyRevenue,
            'prev_year_revenue'   => $prevYearRevenue,
            'orders_by_status'    => DB::table('orders')->where('farmer_id', $farmerId)->selectRaw('status, COUNT(id) as count')->groupBy('status')->get(),
            'low_stock'           => $lowStock,
            'recent_orders'       => \App\Models\Order::with(['buyer:id,name','items.product'])->where('farmer_id', $farmerId)->latest()->limit(5)->get(),
            'top_products'        => $topProducts,
            'heatmap'             => $heatmap,
            'scatter_products'    => $scatterProducts,
            'stacked_by_category' => $stackedByCategory,
        ]);
    }
}