<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_discount_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('min_amount'); // حداقل مبلغ سبد برای فعال شدن این قانون
            $table->enum('type', ['percentage', 'fixed']);
            $table->unsignedInteger('value');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_discount_rules');
    }
};
