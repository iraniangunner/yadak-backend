<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('from_status')->nullable(); // null یعنی این اولین وضعیت (ثبت اولیه) بوده
            $table->string('to_status');

            // کاربر داخلی که تغییر رو انجام داده؛ null یعنی خودکار/سیستمی بوده
            // (مثلاً expire شدن خودکار لینک پرداخت توسط scheduled job)
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
