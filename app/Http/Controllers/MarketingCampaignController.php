<?php

namespace App\Http\Controllers;

use App\Models\MarketingCampaign;
use App\Services\CustomerAudienceFilter;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MarketingCampaignController extends Controller
{
    public function __construct(
        private CustomerAudienceFilter $audienceFilter,
        private SmsService $sms,
    ) {}

    /**
     * تاریخچه‌ی کمپین‌های قبلی، برای گزارش‌گیری.
     */
    public function index(Request $request)
    {
        $campaigns = MarketingCampaign::query()
            ->with('sentBy:id,name')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($campaigns);
    }

    /**
     * پیش‌نمایش: چندتا مشتری با این فیلترها مچ می‌شن، بدون ارسال واقعی.
     * برای اینکه ادمین قبل از فرستادن، تعداد رو ببینه.
     */
    public function preview(Request $request)
    {
        $filters = $this->validateFilters($request);

        $query = $this->audienceFilter->buildQuery($filters);

        return response()->json([
            'recipient_count' => $query->count(),
            'sample' => $query->limit(10)->get(['id', 'name', 'phone', 'city']),
        ]);
    }

    /**
     * ارسال واقعی پیامک گروهی به مخاطبانی که با فیلترها مچ می‌شن.
     */
    public function send(Request $request)
    {
        $filters = $this->validateFilters($request);

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $message = $request->input('message');
        $customers = $this->audienceFilter->buildQuery($filters)->get(['id', 'phone']);

        foreach ($customers as $customer) {
            $this->sms->send($customer->phone, $message);
        }

        $campaign = MarketingCampaign::create([
            'sent_by' => $request->user()->id,
            'filters' => $filters,
            'message' => $message,
            'recipient_count' => $customers->count(),
        ]);

        return response()->json([
            'message' => "پیامک برای {$customers->count()} مشتری ارسال شد.",
            'campaign' => $campaign,
        ], 201);
    }

    /**
     * اعتبارسنجی و استخراج فیلترها از درخواست. همه‌ی فیلترها اختیاری‌ان
     * (اگه هیچ‌کدوم داده نشه، یعنی همه‌ی مشتری‌ها هدف قرار می‌گیرن).
     */
    private function validateFilters(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'purchased_product_id' => 'nullable|integer|exists:products,id',
            'has_purchased' => 'nullable|boolean',
            'no_purchase_since' => 'nullable|date',
            'city' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            abort(response()->json(['errors' => $validator->errors()], 422));
        }

        return array_filter($validator->validated(), fn($value) => $value !== null);
    }
}
