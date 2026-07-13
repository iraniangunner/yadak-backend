<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->string('sku')->unique();
            $table->string('thumbnail')->nullable(); // عکس کاور/اصلی که توی لیست محصولات نشون داده می‌شه
            $table->text('description')->nullable();

            $table->unsignedBigInteger('price');                  // به تومان یا ریال، عدد صحیح
            $table->unsignedBigInteger('compare_price')->nullable(); // قیمت قبل از تخفیف

            $table->enum('stock_status', [
                'available',    // موجود
                'stopped',      // توقف فروش
                'out_of_stock', // ناموجود
                'incoming',     // در حال تأمین
            ])->default('available');

            // مشخصات فیزیکی - فقط ادمین/انبار می‌بینن (کنترلش توی Resource/Policy انجام می‌شه)
            $table->decimal('weight_kg', 8, 3)->nullable();
            $table->string('dimensions')->nullable();  // مثلاً "20x10x5 cm"
            $table->string('package_type')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};