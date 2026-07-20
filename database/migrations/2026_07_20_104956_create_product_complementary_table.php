<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_complementary', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('complementary_product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'complementary_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_complementary');
    }
};
