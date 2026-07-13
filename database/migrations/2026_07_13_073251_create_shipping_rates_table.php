<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();

            // city=null یعنی نرخ پیش‌فرض (برای شهرهایی که نرخ اختصاصی ندارن)
            $table->string('city')->nullable()->unique();

            $table->unsignedBigInteger('base_price');    // هزینه‌ی پایه
            $table->unsignedBigInteger('price_per_kg');  // هزینه‌ی هر کیلوگرم اضافه

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
    }
};