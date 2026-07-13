<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * سرویس متمرکز برای کار با عکس‌ها (برند، دسته‌بندی، محصول، گالری محصول).
 * به‌جای اینکه هر Controller مستقیم با Storage کار کنه، همه از این سرویس
 * استفاده می‌کنن تا منطق نام‌گذاری فایل، مسیر ذخیره‌سازی و حذف فایل قدیمی
 * یک‌جا مدیریت بشه.
 */
class ImageService
{
    /**
     * دیسکی که عکس‌ها روش ذخیره می‌شن. توی config/filesystems.php تعریف شده
     * و باید public باشه تا از طریق /storage قابل دسترسی باشه
     * (نیاز به `php artisan storage:link`).
     */
    protected string $disk = 'public';

    /**
     * ذخیره‌ی یه فایل آپلودشده توی یه پوشه‌ی مشخص (مثلاً 'brands', 'products', 'products/gallery').
     * اسم فایل با uuid تولید می‌شه تا تداخل اسمی پیش نیاد.
     *
     * @return string مسیر نسبی ذخیره‌شده (همونی که توی دیتابیس ذخیره می‌شه)
     */
    public function store(UploadedFile $file, string $folder): string
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();

        return $file->storeAs($folder, $filename, $this->disk);
    }

    /**
     * حذف یه فایل از دیسک، اگه مسیرش null نباشه و فایل واقعاً وجود داشته باشه.
     * برای وقتی که کاربر عکس رو عوض می‌کنه یا رکورد حذف می‌شه استفاده می‌شه.
     */
    public function delete(?string $path): void
    {
        if ($path && Storage::disk($this->disk)->exists($path)) {
            Storage::disk($this->disk)->delete($path);
        }
    }

    /**
     * جایگزینی عکس قدیمی با جدید: اول قدیمی حذف می‌شه، بعد جدید ذخیره می‌شه.
     * برای متد update توی Controller ها (مثلاً وقتی ادمین عکس محصول رو عوض می‌کنه).
     */
    public function replace(?string $oldPath, UploadedFile $newFile, string $folder): string
    {
        $this->delete($oldPath);

        return $this->store($newFile, $folder);
    }

    /**
     * گرفتن آدرس کامل قابل نمایش یه عکس از روی مسیر ذخیره‌شده‌ش.
     * اگه مسیر خالی باشه (عکس تنظیم نشده)، null برمی‌گردونه تا فرانت
     * خودش placeholder نشون بده.
     */
    public function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($this->disk);

        return $disk->url($path);
    }
}