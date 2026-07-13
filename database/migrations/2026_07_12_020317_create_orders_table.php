<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('status', [
                'pending_review',              // ثبت اولیه، در انتظار بررسی موجودی
                'needs_customer_confirmation', // بخشی ناموجود بود، منتظر تایید مشتری
                'awaiting_payment',            // لینک پرداخت زمان‌دار ارسال شده
                'paid',                        // پرداخت‌شده - نهایی
                'cancelled',                   // لغو شده (دستی یا رهاشده)
                'expired',                     // لینک پرداخت منقضی شده بدون پرداخت
            ])->default('pending_review');

            $table->unsignedBigInteger('subtotal');               // جمع قیمت آیتم‌ها قبل از تخفیف/ارسال
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('shipping_cost')->default(0);
            $table->unsignedBigInteger('total_amount');           // مبلغ نهایی قابل پرداخت

            $table->text('customer_note')->nullable();  // یادداشت مشتری هنگام ثبت سفارش
            $table->text('admin_note')->nullable();      // یادداشت داخلی ادمین/انبار

            // فیلدهای مربوط به زرین‌پال
            $table->string('payment_authority')->nullable()->unique(); // Authority که زرین‌پال موقع ساخت لینک برمی‌گردونه
            $table->string('payment_ref_id')->nullable();              // RefID که بعد از پرداخت موفق برمی‌گرده
            $table->timestamp('payment_link_expires_at')->nullable();  // انقضای لینک پرداخت زمان‌دار

            $table->timestamp('confirmed_by_customer_at')->nullable(); // وقتی سبد اصلاح‌شده رو مشتری تایید کرد
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
