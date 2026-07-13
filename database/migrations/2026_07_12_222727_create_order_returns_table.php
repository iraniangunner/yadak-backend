<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();

            // دنرمال‌شده از روی order برای گزارش‌گیری راحت‌تر
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('quantity'); // چند عدد از این آیتم مرجوع شده
            $table->text('reason');

            $table->enum('status', [
                'requested', // ثبت اولیه توسط مشتری
                'approved',  // ادمین تایید کرده، منتظر واریز
                'rejected',  // ادمین رد کرده
                'refunded',  // مبلغ واریز شده - نهایی
            ])->default('requested');

            $table->unsignedBigInteger('refund_amount')->nullable(); // مبلغ واقعی بازگشتی
            $table->text('admin_note')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_returns');
    }
};