<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // دنرمال‌شده از روی referral_code برای گزارش‌گیری راحت‌تر بدون
            // نیاز به join همیشگی.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // تا وقتی سفارش پرداخت نشده، مبلغ پورسانت نامشخصه (null).
            $table->unsignedBigInteger('commission_amount')->nullable();

            $table->enum('status', [
                'pending',   // سفارش هنوز پرداخت نشده
                'approved',  // سفارش پرداخت شده، پورسانت قطعی و قابل پرداخته
                'paid',      // پورسانت توسط ادمین به معرف پرداخت شده
                'cancelled', // سفارش لغو/منقضی شد، پورسانتی تعلق نمی‌گیره
            ])->default('pending');

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_commissions');
    }
};
