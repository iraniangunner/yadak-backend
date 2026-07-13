<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // کاربری که تغییر رو انجام داده؛ null یعنی سیستمی/خودکار بوده
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('loggable_type'); // 'product', 'category', 'brand', 'order' (از morphMap)
            $table->unsignedBigInteger('loggable_id');

            $table->string('action'); // created / updated / deleted
            $table->json('changes')->nullable(); // {"before": {...}, "after": {...}}

            $table->timestamps();

            $table->index(['loggable_type', 'loggable_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};