<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->decimal('average_quantity', 10, 2); // میانگین فروش روزانه توی بازه‌ی مبنا
            $table->unsignedInteger('actual_quantity');  // فروش واقعی امروز
            $table->unsignedInteger('tolerance_percent'); // تلورانسی که در اون لحظه تنظیم بوده
            $table->unsignedInteger('period_days');       // بازه‌ی مبنا (مثلاً ۷ روز)

            $table->timestamps();

            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_alerts');
    }
};