<?php

namespace App\Http\Controllers;

use App\Models\SalesAlert;
use Illuminate\Http\Request;

class SalesAlertController extends Controller
{
    public function index(Request $request)
    {
        $alerts = SalesAlert::query()
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->with('product')
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return response()->json($alerts);
    }
}