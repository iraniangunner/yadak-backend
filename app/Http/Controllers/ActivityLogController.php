<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * لیست لاگ تغییرات، قابل فیلتر بر اساس نوع (loggable_type: product/
     * category/brand/order)، آیدی مشخص، کاربر انجام‌دهنده، و نوع عملیات.
     * برای پیگیری تغییرات (بند ۵ سند).
     */
    public function index(Request $request)
    {
        $logs = ActivityLog::query()
            ->when($request->filled('loggable_type'), fn ($q) => $q->where('loggable_type', $request->string('loggable_type')))
            ->when($request->filled('loggable_id'), fn ($q) => $q->where('loggable_id', $request->integer('loggable_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->with('user:id,name,role')
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return response()->json($logs);
    }
}