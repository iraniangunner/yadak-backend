<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // همیشه با حروف بزرگ ذخیره می‌شه (نرمال‌سازی توی مدل)

            $table->enum('type', ['percentage', 'fixed']);
            $table->unsignedInteger('value'); // درصد (۱ تا ۱۰۰) یا مبلغ ثابت (تومان)

            // حداقل مبلغ سبد خرید برای قابل‌استفاده بودن کد (nullable = بدون حداقل)
            $table->unsignedBigInteger('min_cart_amount')->nullable();

            // سقف مبلغ تخفیف (بیشتر برای type=percentage معنا داره، تا مثلاً
            // "۲۰٪ تخفیف، حداکثر ۵۰ هزار تومان" تعریف بشه)
            $table->unsignedBigInteger('max_discount_amount')->nullable();

            // تعداد کل دفعاتی که این کد قابل استفاده‌ست (nullable = نامحدود)
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);

            // تعداد دفعاتی که هر کاربر می‌تونه از این کد استفاده کنه (nullable = نامحدود)
            $table->unsignedInteger('usage_limit_per_user')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};