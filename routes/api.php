<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CustomerVehicleController;
use App\Http\Controllers\ProductStockSubscriptionController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\AdminOrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\ProductPriceTierController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\ReferralCodeController;
use App\Http\Controllers\ReferralCommissionController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\OrderReturnController;
use App\Http\Controllers\AdminOrderReturnController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesAlertController;
use App\Http\Controllers\InventoryAlertController;
use App\Http\Controllers\MarketingCampaignController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\ShippingRateController;
use App\Http\Controllers\ShippingEstimateController;
use App\Http\Controllers\ShippingOptionsController;
use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\MyReferralController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\ProductReviewController;
use App\Http\Controllers\ProductAttributeController;
use App\Http\Controllers\AdminProductReviewController;
use App\Http\Controllers\CartDiscountRuleController;

/*
|--------------------------------------------------------------------------
| Auth (عمومی)
|--------------------------------------------------------------------------
*/

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:10,1');
Route::post('/send-otp', [AuthController::class, 'sendOtp'])->middleware('throttle:3,10');
Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,10');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,10');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,10');

Route::middleware(['auth:api', 'throttle:30,1'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [AuthController::class, 'updateProfile']);
});

/*
|--------------------------------------------------------------------------
| مدیریت staff (فقط ادمین)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::get('/staff', [StaffController::class, 'index']);
    Route::post('/staff', [StaffController::class, 'store']);
    Route::put('/staff/{user}', [StaffController::class, 'update']);
    Route::delete('/staff/{user}', [StaffController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| محصول / برند / دسته‌بندی / خودرو - عمومی (بدون نیاز به لاگین)
|--------------------------------------------------------------------------
*/

Route::get('/brands', [BrandController::class, 'index']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/vehicles', [VehicleController::class, 'index']);

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/filterable-attributes', [ProductController::class, 'filterableAttributes']);
Route::get('/products/complementary-suggestions', [ProductController::class, 'complementarySuggestions']);
Route::get('/products/{product:slug}', [ProductController::class, 'show']);

Route::post('/products/{product}/stock-subscribe', [ProductStockSubscriptionController::class, 'store'])
    ->middleware('throttle:5,10');


// عمومی - محاسبه‌ی قیمت بر اساس تعداد (برای سبد خرید فرانت)
Route::get('/products/{product}/price-for-quantity', [ProductController::class, 'priceForQuantity']);


Route::get('/articles', [ArticleController::class, 'index']);
Route::get('/articles/{slug}', [ArticleController::class, 'show']);
Route::get('/banners', [BannerController::class, 'index']);

/*
|--------------------------------------------------------------------------
| خودروهای مشتری (کاربر لاگین‌شده)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api', 'throttle:30,1'])->group(function () {
    Route::get('/my-vehicles', [CustomerVehicleController::class, 'index']);
    Route::post('/my-vehicles', [CustomerVehicleController::class, 'store']);
    Route::delete('/my-vehicles/{vehicle}', [CustomerVehicleController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| مدیریت محصول/برند/دسته/خودرو (ادمین و انبار)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api', 'role:admin,warehouse'])->group(function () {
    Route::post('/admin/brands', [BrandController::class, 'store']);
    Route::put('/admin/brands/{brand}', [BrandController::class, 'update']);
    Route::delete('/admin/brands/{brand}', [BrandController::class, 'destroy']);

    Route::post('/admin/categories', [CategoryController::class, 'store']);
    Route::put('/admin/categories/{category}', [CategoryController::class, 'update']);
    Route::delete('/admin/categories/{category}', [CategoryController::class, 'destroy']);

    Route::post('/admin/vehicles', [VehicleController::class, 'store']);
    Route::put('/admin/vehicles/{vehicle}', [VehicleController::class, 'update']);
    Route::delete('/admin/vehicles/{vehicle}', [VehicleController::class, 'destroy']);

    Route::post('/admin/products', [ProductController::class, 'store']);
    Route::get('/admin/products/{product}', [ProductController::class, 'show']);
    Route::put('/admin/products/{product}', [ProductController::class, 'update']);
    Route::delete('/admin/products/{product}', [ProductController::class, 'destroy']);
    Route::delete('/admin/products/{product}/images/{image}', [ProductController::class, 'destroyImage']);
});

/*
|--------------------------------------------------------------------------
| سفارش‌های مشتری (کاربر لاگین‌شده)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api', 'throttle:30,1'])->group(function () {
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);

    // ساخت لینک پرداخت زمان‌دار (زرین‌پال) برای سفارشی که تایید شده
    Route::post('/orders/{order}/pay', [PaymentController::class, 'initiate']);
});

/*
|--------------------------------------------------------------------------
| مدیریت سفارش‌ها (ادمین و انبار)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api', 'role:admin,warehouse,sales,support'])->group(function () {
    Route::get('/admin/orders', [AdminOrderController::class, 'index']);
    Route::get('/admin/orders/{order}', [AdminOrderController::class, 'show']);
});

Route::middleware(['auth:api', 'role:admin,warehouse'])->group(function () {
    Route::post('/admin/orders/{order}/approve', [AdminOrderController::class, 'approve']);
    Route::put('/admin/orders/{order}/items', [AdminOrderController::class, 'updateItems']);
    Route::post('/admin/orders/{order}/cancel', [AdminOrderController::class, 'cancel']);
});

/*
|--------------------------------------------------------------------------
| بازگشت از درگاه پرداخت (عمومی - زرین‌پال مستقیم مرورگر کاربر رو می‌فرسته)
|--------------------------------------------------------------------------
*/

Route::get('/payment/callback', [PaymentController::class, 'callback'])->name('payment.callback');





/* تخفیف ها */


Route::middleware(['auth:api', 'role:admin,warehouse'])->group(function () {
    Route::get('/admin/discounts', [DiscountController::class, 'index']);
    Route::post('/admin/discounts', [DiscountController::class, 'store']);
    Route::put('/admin/discounts/{discount}', [DiscountController::class, 'update']);
    Route::delete('/admin/discounts/{discount}', [DiscountController::class, 'destroy']);
});


// مدیریت قیمت پلکانی (ادمین و انبار)
Route::middleware(['auth:api', 'role:admin,warehouse'])->group(function () {
    Route::get('/admin/products', [ProductController::class, 'adminIndex']);
    Route::post('/admin/products/{product}/price-tiers', [ProductPriceTierController::class, 'store']);
    Route::put('/admin/products/{product}/price-tiers/{tier}', [ProductPriceTierController::class, 'update']);
    Route::delete('/admin/products/{product}/price-tiers/{tier}', [ProductPriceTierController::class, 'destroy']);
});


// کد تخفیف

Route::middleware(['auth:api', 'role:admin,warehouse'])->group(function () {
    Route::get('/admin/coupons', [CouponController::class, 'index']);
    Route::post('/admin/coupons', [CouponController::class, 'store']);
    Route::put('/admin/coupons/{coupon}', [CouponController::class, 'update']);
    Route::delete('/admin/coupons/{coupon}', [CouponController::class, 'destroy']);
});


// کد معرف


Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::get('/admin/referral-codes', [ReferralCodeController::class, 'index']);
    Route::post('/admin/referral-codes', [ReferralCodeController::class, 'store']);
    Route::put('/admin/referral-codes/{referralCode}', [ReferralCodeController::class, 'update']);
    Route::delete('/admin/referral-codes/{referralCode}', [ReferralCodeController::class, 'destroy']);

    Route::get('/admin/referral-commissions', [ReferralCommissionController::class, 'index']);
    Route::post('/admin/referral-commissions/{commission}/mark-paid', [ReferralCommissionController::class, 'markPaid']);
});



// لاگ تغییرات

Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::get('/admin/activity-logs', [ActivityLogController::class, 'index']);
});



// ---------- مرجوعی (کاربر لاگین‌شده) ----------

Route::middleware(['auth:api', 'throttle:30,1'])->group(function () {
    Route::get('/returns', [OrderReturnController::class, 'index']);
    Route::post('/orders/{order}/returns', [OrderReturnController::class, 'store']);
});

// ---------- مدیریت مرجوعی (ادمین و انبار) ----------

Route::middleware(['auth:api', 'role:admin,warehouse,support'])->group(function () {
    Route::get('/admin/returns', [AdminOrderReturnController::class, 'index']);
    Route::post('/admin/returns/{return}/approve', [AdminOrderReturnController::class, 'approve']);
    Route::post('/admin/returns/{return}/reject', [AdminOrderReturnController::class, 'reject']);
    Route::post('/admin/returns/{return}/mark-refunded', [AdminOrderReturnController::class, 'markRefunded']);
});

// ---------- گزارش‌گیری فروش (فقط ادمین) ----------

Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::get('/admin/reports/product-sales', [ReportController::class, 'productSales']);
    Route::get('/admin/reports/customer-sales', [ReportController::class, 'customerSales']);
    Route::get('/admin/reports/city-sales', [ReportController::class, 'citySales']);
    Route::get('/admin/reports/returns', [ReportController::class, 'returns']);
});


// آلارم های کمبود کالا و ...

Route::middleware(['auth:api', 'role:admin,warehouse'])->group(function () {
    Route::get('/admin/sales-alerts', [SalesAlertController::class, 'index']);
    Route::get('/admin/inventory-alerts', [InventoryAlertController::class, 'index']);
});



Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::get('/admin/marketing/campaigns', [MarketingCampaignController::class, 'index']);
    Route::post('/admin/marketing/preview', [MarketingCampaignController::class, 'preview']);
    Route::post('/admin/marketing/send', [MarketingCampaignController::class, 'send'])
        ->middleware('throttle:5,10'); // جلوگیری از ارسال تصادفی/تکراری زیاد
});



Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::get('/admin/articles', [ArticleController::class, 'adminIndex']);
    Route::get('/admin/articles/{article}', [ArticleController::class, 'adminShow']);
    Route::post('/admin/articles', [ArticleController::class, 'store']);
    Route::put('/admin/articles/{article}', [ArticleController::class, 'update']);
    Route::delete('/admin/articles/{article}', [ArticleController::class, 'destroy']);

    Route::post('/admin/banners', [BannerController::class, 'store']);
    Route::put('/admin/banners/{banner}', [BannerController::class, 'update']);
    Route::delete('/admin/banners/{banner}', [BannerController::class, 'destroy']);
});





// ---------- آدرس‌های مشتری (کاربر لاگین‌شده) ----------

Route::middleware(['auth:api', 'throttle:30,1'])->group(function () {
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::put('/addresses/{address}', [AddressController::class, 'update']);
    Route::delete('/addresses/{address}', [AddressController::class, 'destroy']);
});

// ---------- تخمین هزینه‌ی ارسال (عمومی، قبل از ثبت سفارش) ----------

Route::post('/shipping/estimate', [ShippingEstimateController::class, 'estimate']);

// ---------- مدیریت نرخ‌های ارسال (فقط ادمین) ----------

Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::get('/admin/shipping-rates', [ShippingRateController::class, 'index']);
    Route::post('/admin/shipping-rates', [ShippingRateController::class, 'store']);
    Route::put('/admin/shipping-rates/{rate}', [ShippingRateController::class, 'update']);
    Route::delete('/admin/shipping-rates/{rate}', [ShippingRateController::class, 'destroy']);
});


// عمومی - لیست گزینه‌های حمل قبل از ثبت سفارش
Route::post('/shipping/options', [ShippingOptionsController::class, 'index']);


Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::post('/admin/orders/{order}/issue-invoice', [AccountingController::class, 'issueInvoice']);
});


Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::get('/admin/users/search', [AdminUserController::class, 'search']);
});


Route::middleware(['auth:api'])->group(function () {
    Route::get('/my/referral-code', [MyReferralController::class, 'code']);
    Route::get('/my/referral-commissions', [MyReferralController::class, 'commissions']);
});

Route::middleware(['auth:api', 'throttle:20,1'])->group(function () {
    Route::post('/coupons/check', [CouponController::class, 'check']);
    Route::post('/referral-codes/check', [MyReferralController::class, 'check']);
});




// ---------- علاقه‌مندی‌ها (کاربر لاگین‌شده) ----------
Route::middleware(['auth:api', 'throttle:30,1'])->group(function () {
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/products/{product}/favorite', [FavoriteController::class, 'store']);
    Route::delete('/products/{product}/favorite', [FavoriteController::class, 'destroy']);
});

// ---------- نظرات محصول ----------
// مشاهده عمومیه (نیازی به لاگین نداره)
Route::get('/products/{product}/reviews', [ProductReviewController::class, 'index']);

// ثبت/حذف نظر فقط کاربر لاگین‌شده
Route::middleware(['auth:api', 'throttle:10,1'])->group(function () {
    Route::post('/products/{product}/reviews', [ProductReviewController::class, 'store']);
    Route::delete('/products/{product}/reviews', [ProductReviewController::class, 'destroy']);
});

// ---------- ویژگی‌های محصول (فقط ادمین/انبار) ----------
Route::middleware(['auth:api', 'role:admin,warehouse'])->group(function () {
    Route::post('/admin/products/{product}/attributes', [ProductAttributeController::class, 'store']);
    Route::put('/admin/products/{product}/attributes/{attribute}', [ProductAttributeController::class, 'update']);
    Route::delete('/admin/products/{product}/attributes/{attribute}', [ProductAttributeController::class, 'destroy']);
});



Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::get('/admin/reviews', [AdminProductReviewController::class, 'index']);
    Route::post('/admin/reviews/{review}/approve', [AdminProductReviewController::class, 'approve']);
    Route::post('/admin/reviews/{review}/reject', [AdminProductReviewController::class, 'reject']);
});




// عمومی - برای پیش‌نمایش توی تسویه‌حساب
Route::get('/cart-discount-rules/active', [CartDiscountRuleController::class, 'activeRules']);

// ادمین
Route::middleware(['auth:api', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/cart-discount-rules', [CartDiscountRuleController::class, 'index']);
    Route::post('/cart-discount-rules', [CartDiscountRuleController::class, 'store']);
    Route::post('/cart-discount-rules/{cartDiscountRule}', [CartDiscountRuleController::class, 'update']);
    Route::delete('/cart-discount-rules/{cartDiscountRule}', [CartDiscountRuleController::class, 'destroy']);
});
