<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderReturnController extends Controller
{
    /**
     * لیست درخواست‌های مرجوعی خودِ مشتری.
     */
    public function index(Request $request)
    {
        $returns = OrderReturn::where('user_id', $request->user()->id)
            ->with(['orderItem:id,title,sku', 'order:id,status'])
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($returns);
    }

    /**
     * ثبت درخواست مرجوعی برای یه آیتم مشخص از یه سفارش - فقط اگه سفارش
     * متعلق به خودِ مشتری و پرداخت‌شده (paid) باشه.
     */
    public function store(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'این سفارش متعلق به شما نیست.'], 403);
        }

        if (! $order->isPaid()) {
            return response()->json(['message' => 'فقط سفارش‌های پرداخت‌شده قابل مرجوع کردن هستند.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'order_item_id' => 'required|exists:order_items,id',
            'quantity' => 'required|integer|min:1',
            'reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $item = $order->items()->find($request->integer('order_item_id'));

        if (! $item) {
            return response()->json(['message' => 'این آیتم متعلق به این سفارش نیست.'], 404);
        }

        $alreadyRequested = OrderReturn::where('order_item_id', $item->id)
            ->whereIn('status', [OrderReturn::STATUS_REQUESTED, OrderReturn::STATUS_APPROVED, OrderReturn::STATUS_REFUNDED])
            ->sum('quantity');

        if ($alreadyRequested + $request->integer('quantity') > $item->quantity) {
            return response()->json([
                'message' => 'تعداد درخواستی بیشتر از تعداد خریداری‌شده (با احتساب مرجوعی‌های قبلی) است.',
            ], 422);
        }

        $return = OrderReturn::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'user_id' => $request->user()->id,
            'quantity' => $request->integer('quantity'),
            'reason' => $request->input('reason'),
            'status' => OrderReturn::STATUS_REQUESTED,
        ]);

        return response()->json([
            'message' => 'درخواست مرجوعی شما ثبت شد و در انتظار بررسی است.',
            'return' => $return,
        ], 201);
    }
}
