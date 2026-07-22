<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('vehicle_brand')->nullable()->after('brand_id');
            $table->string('vehicle_model')->nullable()->after('vehicle_brand');
            $table->string('vehicle_type')->nullable()->after('vehicle_model');

            $table->index('vehicle_brand');
            $table->index('vehicle_model');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['vehicle_brand']);
            $table->dropIndex(['vehicle_model']);
            $table->dropColumn(['vehicle_brand', 'vehicle_model', 'vehicle_type']);
        });
    }
};
