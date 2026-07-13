<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('min_quantity');       // مثلاً ۱
            $table->unsignedInteger('max_quantity')->nullable(); // مثلاً ۳، یا null یعنی «به بالا»
            $table->unsignedBigInteger('price');            // قیمت واحد برای این بازه

            $table->timestamps();

            $table->index(['product_id', 'min_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_tiers');
    }
};