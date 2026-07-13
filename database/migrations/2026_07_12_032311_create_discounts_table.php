<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();

            // به‌جای سه ستون جدا برای product_id/category_id/brand_id،
            // از morph استفاده می‌کنیم تا یه ردیف بتونه به هرکدوم وصل بشه.
            // discountable_type مقادیر 'product'/'category'/'brand' رو می‌گیره
            // (نه FQCN کامل)، چون توی AppServiceProvider morphMap تعریف می‌کنیم.
            $table->string('discountable_type');
            $table->unsignedBigInteger('discountable_id');

            $table->enum('type', ['percentage', 'fixed']);
            $table->unsignedInteger('value'); // درصد (۱ تا ۱۰۰) یا مبلغ ثابت (تومان)

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['discountable_type', 'discountable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};