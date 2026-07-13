<?php

namespace App\Http\Controllers;

use App\Models\OrderReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminOrderReturnController extends Controller
{
    public function index(Request $request)
    {
        $returns = OrderReturn::query()
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('user_id'), fn($q) => $q->where('user_id', $request->integer('user_id')))
            ->with(['user:id,name,phone', 'orderItem:id,title,sku', 'order:id,status'])
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($returns);
    }

    public function approve(Request $request, OrderReturn $return)
    {
        if ($return->status !== OrderReturn::STATUS_REQUESTED) {
            return response()->json(['message' => 'این درخواست در وضعیتی نیست که قابل تایید باشد.'], 422);
        }

        $return->update([
            'status' => OrderReturn::STATUS_APPROVED,
            'admin_note' => $request->input('admin_note', $return->admin_note),
        ]);

        return response()->json(['return' => $return->fresh()]);
    }

    public function reject(Request $request, OrderReturn $return)
    {
        if ($return->status !== OrderReturn::STATUS_REQUESTED) {
            return response()->json(['message' => 'این درخواست در وضعیتی نیست که قابل رد باشد.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'admin_note' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $return->update([
            'status' => OrderReturn::STATUS_REJECTED,
            'admin_note' => $request->input('admin_note'),
        ]);

        return response()->json(['return' => $return->fresh()]);
    }

    /**
     * ثبت واریز واقعی مبلغ مرجوعی (خارج از سیستم، مثلاً کارت‌به‌کارت یا
     * از طریق درگاه). فقط از حالت approved قابل انجامه.
     */
    public function markRefunded(Request $request, OrderReturn $return)
    {
        if ($return->status !== OrderReturn::STATUS_APPROVED) {
            return response()->json(['message' => 'فقط مرجوعی‌های تاییدشده قابل ثبت واریز هستند.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'refund_amount' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $return->update([
            'status' => OrderReturn::STATUS_REFUNDED,
            'refund_amount' => $request->integer('refund_amount'),
        ]);

        return response()->json(['return' => $return->fresh()]);
    }
}
