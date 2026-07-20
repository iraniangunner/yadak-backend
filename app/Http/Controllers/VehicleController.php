<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VehicleController extends Controller
{
    /**
     * لیست/جستجوی خودروها. برای صفحه‌ی «خودروی خودت رو انتخاب کن».
     * ?search=پژو برای جستجو روی brand یا model.
     */
    public function index(Request $request)
    {
        $vehicles = Vehicle::query()
            ->where('is_active', true)
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search')->toString();
                $q->where(function ($q) use ($search) {
                    $q->where('brand', 'like', "%{$search}%")
                        ->orWhere('model', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('category_id'), function ($q) use ($request) {
                $categoryIds = array_filter(explode(',', $request->string('category_id')->toString()));
                $q->whereHas('products', function ($q) use ($categoryIds) {
                    $q->whereIn('category_id', $categoryIds)->where('is_active', true);
                });
            })
            ->orderBy('brand')
            ->orderBy('model')
            ->paginate($request->integer('per_page', 50));

        return response()->json($vehicles);
    }

    public function store(Request $request)
    {
        $validated = $this->validateVehicle($request);

        $vehicle = Vehicle::create($validated);

        return response()->json(['vehicle' => $vehicle], 201);
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $validated = $this->validateVehicle($request, sometimes: true);

        $vehicle->update($validated);

        return response()->json(['vehicle' => $vehicle->fresh()]);
    }

    public function destroy(Vehicle $vehicle)
    {
        $vehicle->delete();

        return response()->json(['message' => 'خودرو با موفقیت حذف شد.']);
    }

    private function validateVehicle(Request $request, bool $sometimes = false): array
    {
        $rule = $sometimes ? 'sometimes' : 'required';

        $validator = Validator::make($request->all(), [
            'brand' => "{$rule}|string|max:255",
            'model' => "{$rule}|string|max:255",
            'generation' => 'nullable|string|max:255',
            'year_from' => 'nullable|integer|min:1300|max:1500',
            'year_to' => 'nullable|integer|gte:year_from|max:1500',
            'is_active' => 'sometimes|boolean',
        ], [
            'year_to.gte' => 'سال پایان باید بزرگ‌تر یا مساوی سال شروع باشد.',
        ]);

        if ($validator->fails()) {
            abort(response()->json(['errors' => $validator->errors()], 422));
        }

        return $validator->validated();
    }
}
