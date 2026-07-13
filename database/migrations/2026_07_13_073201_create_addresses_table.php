<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title')->nullable(); // برچسب مثل "خانه"، "محل کار"
            $table->string('receiver_name');
            $table->string('receiver_phone');
            $table->string('province')->nullable();
            $table->string('city');
            $table->text('full_address');
            $table->string('postal_code')->nullable();

            // لوکیشن دقیق برای محاسبه‌ی هزینه‌ی ارسال (بند ۷ سند)
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->boolean('is_default')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};