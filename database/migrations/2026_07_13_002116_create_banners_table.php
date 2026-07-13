<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('image');

            // بنر می‌تونه به یه محصول مشخص لینک بشه، یا فقط یه لینک آزاد
            // (مثلاً به یه دسته‌بندی یا صفحه‌ی خارجی) داشته باشه.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('link_url')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
