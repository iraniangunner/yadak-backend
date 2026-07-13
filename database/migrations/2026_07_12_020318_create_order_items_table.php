<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // nullOnDelete تا اگه محصول بعداً از سیستم حذف شد، تاریخچه‌ی سفارش از بین نره
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot از لحظه‌ی ثبت سفارش - چون قیمت/عنوان محصول ممکنه بعداً عوض بشه
            // ولی فاکتور/سفارش نباید تغییر کنه.
            $table->string('title');
            $table->string('sku');
            $table->unsignedBigInteger('price'); // قیمت واحد در لحظه‌ی سفارش
            $table->unsignedInteger('quantity');

            // انبار موقع بررسی موجودی این رو مشخص می‌کنه (بند ۴ سند: حالت اول/دوم)
            $table->boolean('is_available')->default(true);

            // اگه ادمین این آیتم رو به‌خاطر نبود موجودی از سفارش حذف/کم کرده باشه
            $table->boolean('removed_by_admin')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
