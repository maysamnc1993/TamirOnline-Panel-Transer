<?php

namespace Modules\Site\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\CRM\Enums\OrderStatus;
use Modules\CRM\Models\Brand;
use Modules\CRM\Models\City;
use Modules\CRM\Models\Device;
use Modules\CRM\Models\Order;
use Modules\CRM\Models\OrderReview;

class CatalogOrderRatingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $slug = ['string', 'max:120', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'];
        $scope = $request->validate([
            'device' => ['required_without:brand', ...$slug],
            'brand' => ['required_without:device', ...$slug],
            'city' => ['sometimes', ...$slug],
        ]);

        $filters = [];
        foreach (['device' => Device::class, 'brand' => Brand::class, 'city' => City::class] as $key => $model) {
            if (! isset($scope[$key])) {
                continue;
            }

            $id = $model::query()->where('slug', $scope[$key])->value('id');
            abort_if($id === null, 404);
            $filters[$key.'_id'] = (int) $id;
        }

        $cacheKey = 'site:catalog-order-rating:'.http_build_query($filters);
        $summary = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($filters) {
            $orders = Order::query()->realOrders()
                ->where('status', OrderStatus::Completed->value)
                ->where($filters);

            $result = OrderReview::query()
                ->joinSub($orders->select('id', 'customer_id'), 'rated_orders', function ($join) {
                    $join->on('rated_orders.id', '=', 'crm_order_reviews.order_id')
                        ->on('rated_orders.customer_id', '=', 'crm_order_reviews.customer_id');
                })
                ->where('crm_order_reviews.status', OrderReview::STATUS_APPROVED)
                ->whereBetween('crm_order_reviews.rating', [1, 5])
                ->selectRaw('COUNT(*) as rating_count, AVG(crm_order_reviews.rating) as rating_average')
                ->first();

            return [
                'count' => (int) $result->rating_count,
                'average' => $result->rating_average === null ? null : (float) $result->rating_average,
            ];
        });

        return response()->json(['data' => $summary]);
    }
}
