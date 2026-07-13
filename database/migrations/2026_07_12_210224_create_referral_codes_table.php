<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // بزرگ‌حرف ذخیره می‌شه (نرمال‌سازی توی مدل)

            // صاحب کد: می‌تونه فروشنده‌ی داخلی (role=sales) یا هر کاربر دیگه‌ای
            // باشه که قراره پورسانت معرفی بگیره.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('commission_type', ['percentage', 'fixed']);
            $table->unsignedInteger('commission_value'); // درصد (۱-۱۰۰) یا مبلغ ثابت

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_codes');
    }
};