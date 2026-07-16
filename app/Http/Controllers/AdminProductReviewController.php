<?php

namespace App\Http\Controllers;

use App\Models\ProductReview;
use Illuminate\Http\Request;

class AdminProductReviewController extends Controller
{
    /**
     * لیست نظرات - پیش‌فرض فقط pending (تأییدنشده) رو نشون می‌ده، مگه
     * اینکه status=all یا status=approved صریحاً بخواد.
     */
    public function index(Request $request)
    {
        $status = $request->string('status')->toString() ?: 'pending';

        $reviews = ProductReview::query()
            ->when($status === 'pending', fn($q) => $q->where('is_approved', false))
            ->when($status === 'approved', fn($q) => $q->where('is_approved', true))
            ->with(['user:id,name', 'product:id,title,slug'])
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($reviews);
    }

    public function approve(ProductReview $review)
    {
        $review->update(['is_approved' => true]);

        return response()->json(['message' => 'نظر تأیید شد.', 'review' => $review]);
    }

    /**
     * رد نظر = حذف کامل (نه فقط علامت‌گذاری) - چون نظر ردشده دلیلی برای
     * نگه‌داشتن نداره.
     */
    public function reject(ProductReview $review)
    {
        $review->delete();

        return response()->json(['message' => 'نظر رد و حذف شد.']);
    }
}
