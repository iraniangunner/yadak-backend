<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // برخلاف is_active (که کل دسته رو مخفی می‌کنه)، این فقط جلوی
            // خرید محصولات این دسته رو می‌گیره؛ دسته و محصولاتش همچنان
            // قابل مشاهده‌ان (بند ۵ سند: «توقف فروش موردی یا دسته‌ای»).
            $table->boolean('sales_stopped')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('sales_stopped');
        });
    }
};