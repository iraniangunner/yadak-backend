<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralCode;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| اجرا: php artisan db:seed --class=AdminTestDataSeeder
|--------------------------------------------------------------------------
| فقط برای محیط توسعه/تست - داده‌ی واقعی‌نما برای تست کامل پنل ادمین می‌سازه:
| دسته‌بندی (با زیرمجموعه)، برند، خودرو، محصول (با قیمت پلکانی)، مشتری،
| سفارش (با وضعیت‌های مختلف)، کد تخفیف، کد معرف+پورسانت، مرجوعی، مقاله،
| بنر، و یه ادمین برای لاگین.
*/
class AdminTestDataSeeder extends Seeder
{
    public function run(): void
    {
        // ------------------------------------------------------------------
        // ۰. ادمین برای لاگین (اگه از قبل نساخته باشید)
        // ------------------------------------------------------------------
        $admin = User::firstOrCreate(
            ['email' => 'admin@yadaki.test'],
            [
                'name' => 'ادمین تست',
                'password' => Hash::make('Password123!'),
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ]
        );

        $warehouse = User::firstOrCreate(
            ['email' => 'warehouse@yadaki.test'],
            [
                'name' => 'کاربر انبار تست',
                'password' => Hash::make('Password123!'),
                'role' => User::ROLE_WAREHOUSE,
                'is_active' => true,
            ]
        );

        // ------------------------------------------------------------------
        // ۱. دسته‌بندی (با یه زیرمجموعه)
        // ------------------------------------------------------------------
        $categoryNames = ['لوازم موتوری', 'سیستم ترمز', 'برق خودرو', 'تعلیق و فرمان', 'بدنه و تزئینی'];
        $categories = collect($categoryNames)->map(
            fn ($name) => Category::factory()->create(['name' => $name, 'is_active' => true])
        );

        /** @var \App\Models\Category $subCategory */
        $subCategory = Category::factory()->create([
            'name' => 'لنت ترمز',
            'parent_id' => $categories->firstWhere('name', 'سیستم ترمز')->id,
            'is_active' => true,
        ]);

        // ------------------------------------------------------------------
        // ۲. برند
        // ------------------------------------------------------------------
        $brandNames = ['بوش', 'ساژم', 'اطلس بی‌ایکس', 'رینگ خودرو', 'ایساکو'];
        $brands = collect($brandNames)->map(
            fn ($name) => Brand::factory()->create(['name' => $name, 'is_active' => true])
        );

        // ------------------------------------------------------------------
        // ۳. خودرو
        // ------------------------------------------------------------------
        $vehicles = collect([
            ['brand' => 'پراید', 'model' => '111', 'year_from' => 1390, 'year_to' => 1398],
            ['brand' => 'پژو', 'model' => '206', 'year_from' => 1385, 'year_to' => 1401],
            ['brand' => 'سمند', 'model' => 'LX', 'year_from' => 1388, 'year_to' => 1400],
            ['brand' => 'تیبا', 'model' => '2', 'year_from' => 1394, 'year_to' => 1402],
        ])->map(fn ($v) => Vehicle::factory()->create(array_merge($v, ['is_active' => true])));

        // ------------------------------------------------------------------
        // ۴. محصول (چندتا فعال با موجودی‌های مختلف، چندتا غیرفعال، چندتا با قیمت پلکانی)
        // ------------------------------------------------------------------
        $stockStatuses = ['available', 'available', 'available', 'stopped', 'out_of_stock', 'incoming'];

        $products = collect();
        $productTitles = [
            'لنت ترمز جلو', 'فیلتر روغن', 'فیلتر هوا', 'شمع موتور', 'باتری خودرو',
            'دیسک ترمز', 'کمک فنر جلو', 'تسمه تایم', 'واشر سرسیلندر', 'رادیاتور آب',
        ];

        foreach ($productTitles as $i => $title) {
            /** @var \App\Models\Product $product */
            $product = Product::factory()->create([
                'title' => $title,
                'category_id' => $i < 5 ? $subCategory->id : $categories->random()->id,
                'brand_id' => $brands->random()->id,
                'price' => rand(50, 2000) * 1000,
                'stock_status' => $stockStatuses[$i % count($stockStatuses)],
                'is_active' => $i !== 3, // یکی رو عمداً غیرفعال می‌کنیم
                'weight_kg' => rand(1, 20) / 10,
            ]);

            // نصف محصولات به یکی-دو خودرو وصل بشن
            if ($i % 2 === 0) {
                $product->vehicles()->attach($vehicles->random(rand(1, 2))->pluck('id'));
            }

            // سه‌تا محصول اول قیمت پلکانی هم داشته باشن
            if ($i < 3) {
                $product->priceTiers()->create(['min_quantity' => 5, 'max_quantity' => 9, 'price' => $product->price * 0.95]);
                $product->priceTiers()->create(['min_quantity' => 10, 'max_quantity' => null, 'price' => $product->price * 0.9]);
            }

            $products->push($product);
        }

        // ------------------------------------------------------------------
        // ۵. مشتری + سفارش (وضعیت‌های مختلف، برای گزارش‌ها و هشدارها)
        // ------------------------------------------------------------------
        $customers = User::factory()->count(8)->create([
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
        ]);

        // چندتا شهر متفاوت برای گزارش فروش شهرها
        $cities = ['تهران', 'مشهد', 'اصفهان', 'شیراز', 'تبریز'];
        $customers->each(fn ($c, $i) => $c->update(['city' => $cities[$i % count($cities)]]));

        foreach ($customers as $i => $customer) {
            // سفارش پرداخت‌شده (برای گزارش‌ها)
            /** @var \App\Models\Order $paidOrder */
            $paidOrder = Order::factory()->create([
                'user_id' => $customer->id,
                'status' => Order::STATUS_PAID,
                'paid_at' => now()->subDays(rand(1, 60)),
            ]);
            $product = $products->random();
            $paidOrder->items()->create([
                'product_id' => $product->id,
                'title' => $product->title,
                'sku' => $product->sku,
                'price' => $product->price,
                'quantity' => rand(1, 3),
            ]);
            $paidOrder->update(['subtotal' => $paidOrder->items->sum(fn ($it) => $it->price * $it->quantity)]);
            $paidOrder->update(['total_amount' => $paidOrder->subtotal]);

            // چون سفارش مستقیم با factory ساخته شده (نه از مسیر واقعی
            // transitionTo)، خودمون تاریخچه‌ی وضعیتش رو دستی می‌سازیم -
            // وگرنه توی پنل ادمین بخش «تاریخچه وضعیت» همیشه خالی می‌مونه.
            $paidOrder->statusHistories()->createMany([
                ['from_status' => null, 'to_status' => Order::STATUS_PENDING_REVIEW, 'changed_by' => null, 'created_at' => $paidOrder->created_at],
                ['from_status' => Order::STATUS_PENDING_REVIEW, 'to_status' => Order::STATUS_AWAITING_PAYMENT, 'changed_by' => $admin->id, 'created_at' => $paidOrder->created_at->addHour()],
                ['from_status' => Order::STATUS_AWAITING_PAYMENT, 'to_status' => Order::STATUS_PAID, 'changed_by' => null, 'created_at' => $paidOrder->paid_at],
            ]);

            // یه سفارش «در انتظار بررسی» (برای تست تأیید/رد توی پنل)
            if ($i < 3) {
                /** @var \App\Models\Order $pendingOrder */
                $pendingOrder = Order::factory()->create([
                    'user_id' => $customer->id,
                    'status' => Order::STATUS_PENDING_REVIEW,
                ]);
                $pendingOrder->statusHistories()->create([
                    'from_status' => null,
                    'to_status' => Order::STATUS_PENDING_REVIEW,
                    'changed_by' => null,
                ]);
            }

            // یه سفارش راکد (تاریخ قدیمی، هنوز در انتظار بررسی - برای هشدار موجودی)
            if ($i === 0) {
                /** @var \App\Models\Order $staleOrder */
                $staleOrder = Order::factory()->create([
                    'user_id' => $customer->id,
                    'status' => Order::STATUS_PENDING_REVIEW,
                    'created_at' => now()->subHours(48),
                ]);
                $staleOrder->statusHistories()->create([
                    'from_status' => null,
                    'to_status' => Order::STATUS_PENDING_REVIEW,
                    'changed_by' => null,
                    'created_at' => now()->subHours(48),
                ]);
            }
        }

        // ------------------------------------------------------------------
        // ۶. کد تخفیف
        // ------------------------------------------------------------------
        Coupon::factory()->create([
            'code' => 'WELCOME10',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ]);
        Coupon::factory()->create([
            'code' => 'FIX50000',
            'type' => 'fixed',
            'value' => 50000,
            'is_active' => true,
        ]);
        Coupon::factory()->create([
            'code' => 'EXPIRED',
            'type' => 'percentage',
            'value' => 20,
            'ends_at' => now()->subDays(5),
            'is_active' => false,
        ]);

        // ------------------------------------------------------------------
        // ۷. کد معرف
        // ------------------------------------------------------------------
        ReferralCode::factory()->create([
            'code' => 'REF-' . $customers->first()->id,
            'user_id' => $customers->first()->id,
            'commission_type' => 'percentage',
            'commission_value' => 5,
            'is_active' => true,
        ]);

        // ------------------------------------------------------------------
        // ۸. مقاله
        // ------------------------------------------------------------------
        Article::factory()->create([
            'title' => 'راهنمای تعویض لنت ترمز',
            'author_id' => $admin->id,
            'is_published' => true,
            'published_at' => now()->subDays(3),
        ]);
        Article::factory()->create([
            'title' => 'پیش‌نویس: نکات نگهداری باتری',
            'author_id' => $admin->id,
            'is_published' => false,
        ]);

        // ------------------------------------------------------------------
        // ۹. بنر
        // ------------------------------------------------------------------
        Banner::factory()->create([
            'title' => 'حراج ویژه لنت ترمز',
            'product_id' => $products->first()->id,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        Banner::factory()->create([
            'title' => 'بنر غیرفعال (تست)',
            'sort_order' => 2,
            'is_active' => false,
        ]);

        $this->command->info('✅ داده‌ی تست پنل ادمین با موفقیت ساخته شد.');
        $this->command->info('   ادمین: admin@yadaki.test / Password123!');
        $this->command->info('   انبار: warehouse@yadaki.test / Password123!');
    }
}