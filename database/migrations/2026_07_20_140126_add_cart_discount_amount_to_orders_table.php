<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // جدا از discount_amount (که مال کد تخفیفه) - این مال تخفیف
            // خودکاریه که فقط بر اساس عبور از یه سقف مبلغ سبد فعال می‌شه؛
            // هر دو می‌تونن هم‌زمان روی یه سفارش اعمال بشن.
            $table->unsignedInteger('cart_discount_amount')->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('cart_discount_amount');
        });
    }
};
