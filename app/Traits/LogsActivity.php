<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * این trait رو به هر مدلی که باید تغییراتش لاگ بشه اضافه کن (بند ۵ سند:
 * «ثبت الگ تغییرات کاربران داخلی؛ هر تغییر مهم در محصول، قیمت، سفارش و
 * وضعیت سفارش باید قابل پیگیری باشد»).
 *
 * استفاده:
 *   use App\Traits\LogsActivity;
 *   class Product extends Model
 *   {
 *       use LogsActivity;
 *   }
 *
 * اگه بخوای بعضی فیلدها لاگ نشن (مثلاً چون جای دیگه‌ای قبلاً لاگ می‌شن،
 * مثل status سفارش که توی order_status_histories ثبت می‌شه)، این رو
 * توی مدل تعریف کن:
 *   protected array $activityLogExcludeFields = ['status'];
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 * @method static void created(\Closure|string $callback)
 * @method static void updated(\Closure|string $callback)
 * @method static void deleted(\Closure|string $callback)
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function (Model $model) {
            $model->recordActivity(ActivityLog::ACTION_CREATED, [
                'after' => $model->attributesForLog(),
            ]);
        });

        static::updated(function (Model $model) {
            $changes = $model->getChanges();
            unset($changes['updated_at']);

            if (empty($changes)) {
                return;
            }

            $excluded = $model->activityLogExcludeFields ?? [];
            if ($excluded && empty(array_diff(array_keys($changes), $excluded))) {
                // همه‌ی فیلدهای تغییرکرده جزو موارد مستثنی‌ان (مثلاً فقط status)
                return;
            }

            // ⚠️ برخلاف نسخه‌ی قبلی (که فقط همون فیلدهای تغییرکرده رو
            // ذخیره می‌کرد)، این‌جا کل رکورد (قبل و بعد) رو کامل ذخیره
            // می‌کنیم - تا وقتی ادمین لاگ رو باز می‌کنه، همه‌ی اطلاعات رو
            // ببینه، نه فقط همون یکی-دو فیلدی که عوض شده.
            // changed_fields هم دقیقاً می‌گه کدوم فیلدها واقعاً تغییر کردن.
            $before = collect($model->getOriginal())
                ->except(['created_at', 'updated_at'])
                ->all();

            $after = collect($model->getAttributes())
                ->except(['created_at', 'updated_at'])
                ->all();

            $model->recordActivity(ActivityLog::ACTION_UPDATED, [
                'before' => $before,
                'after' => $after,
                'changed_fields' => array_values(array_diff(array_keys($changes), $excluded)),
            ]);
        });

        static::deleted(function (Model $model) {
            $model->recordActivity(ActivityLog::ACTION_DELETED, [
                'before' => $model->attributesForLog(),
            ]);
        });
    }

    public function recordActivity(string $action, array $changes): void
    {
        /** @var Model $this */
        ActivityLog::create([
            'user_id' => Auth::id(),
            'loggable_type' => $this->getMorphClass(),
            'loggable_id' => $this->getKey(),
            'action' => $action,
            'changes' => $changes,
        ]);
    }

    /**
     * ویژگی‌های مدل، بدون created_at/updated_at (که برای audit اضافی‌ان).
     */
    protected function attributesForLog(): array
    {
        /** @var Model $this */
        return collect($this->getAttributes())
            ->except(['created_at', 'updated_at'])
            ->all();
    }

    public function activityLogs(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        /** @var Model $this */
        return $this->morphMany(ActivityLog::class, 'loggable')->latest();
    }
}