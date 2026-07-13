<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // FK به آدرس اصلی (ممکنه بعداً حذف/ویرایش بشه)؛ برای همین جزئیاتش
            // رو هم به‌صورت snapshot جدا ذخیره می‌کنیم (مثل order_items).
            $table->foreignId('shipping_address_id')->nullable()->after('referral_code_id')
                ->constrained('addresses')->nullOnDelete();

            $table->string('shipping_receiver_name')->nullable();
            $table->string('shipping_receiver_phone')->nullable();
            $table->string('shipping_city')->nullable();
            $table->text('shipping_full_address')->nullable();
            $table->string('shipping_postal_code')->nullable();
            $table->decimal('shipping_latitude', 10, 7)->nullable();
            $table->decimal('shipping_longitude', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipping_address_id');
            $table->dropColumn([
                'shipping_receiver_name',
                'shipping_receiver_phone',
                'shipping_city',
                'shipping_full_address',
                'shipping_postal_code',
                'shipping_latitude',
                'shipping_longitude',
            ]);
        });
    }
};