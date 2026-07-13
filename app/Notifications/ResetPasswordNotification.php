<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('services.frontend_url', 'http://localhost:3000'), '/');

        $resetUrl = $frontendUrl . '/reset-password'
            . '?token=' . $this->token
            . '&email=' . urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('بازیابی رمز عبور')
            ->greeting('سلام ' . $notifiable->name)
            ->line('درخواستی برای بازیابی رمز عبور حساب شما ثبت شده است.')
            ->action('تنظیم رمز عبور جدید', $resetUrl)
            ->line('این لینک تا ۶۰ دقیقه معتبر است.')
            ->line('اگر شما این درخواست را نداده‌اید، این ایمیل را نادیده بگیرید.');
    }
}
