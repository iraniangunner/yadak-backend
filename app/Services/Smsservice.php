<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    private function apiKey(): string
    {
        return config('services.kavenegar.api_key', env('KAVENEGAR_API_KEY'));
    }

    /**
     * ارسال پیامک ساده (بدون الگو) - مستقیم از طریق REST API کاوه‌نگار.
     */
    public function send(string $mobile, string $message): bool
    {
        try {
            $response = Http::get("https://api.kavenegar.com/v1/{$this->apiKey()}/sms/send.json", [
                'receptor' => $mobile,
                'sender' => '200057817',
                'message' => $message,
            ]);

            $data = $response->json();

            if (($data['return']['status'] ?? null) == 200) {
                Log::info('SMS sent', ['mobile' => $mobile, 'response' => $data]);

                return true;
            }

            Log::error('SMS send failed', ['mobile' => $mobile, 'response' => $data]);

            return false;
        } catch (\Exception $e) {
            Log::error('SMS Exception: '.$e->getMessage(), ['mobile' => $mobile]);

            return false;
        }
    }

    /**
     * ارسال پیامک تراکنشی با الگوی از پیش تعریف‌شده (Verify Lookup) -
     * مستقیم از طریق REST API کاوه‌نگار، نه از طریق پکیج kavenegar/laravel
     * (اون پکیج قدیمیه و متد lookup توش با خطای 404 مواجه می‌شه).
     *
     * @param  string  $template  دقیقاً همون نامی که توی پنل کاوه‌نگار برای الگو گذاشتی
     * @param  array<int, string|null>  $tokens  حداکثر ۳ مقدار، به ترتیب token, token2, token3
     */
    public function sendByTemplate(string $mobile, string $template, array $tokens): bool
    {
        try {
            $params = [
                'receptor' => $mobile,
                'template' => $template,
                'type' => 'sms',
            ];

            if (isset($tokens[0])) {
                $params['token'] = $tokens[0];
            }
            if (isset($tokens[1])) {
                $params['token2'] = $tokens[1];
            }
            if (isset($tokens[2])) {
                $params['token3'] = $tokens[2];
            }

            $response = Http::get("https://api.kavenegar.com/v1/{$this->apiKey()}/verify/lookup.json", $params);

            $data = $response->json();

            if (($data['return']['status'] ?? null) == 200) {
                Log::info('SMS template sent', [
                    'mobile' => $mobile,
                    'template' => $template,
                    'response' => $data,
                ]);

                return true;
            }

            Log::error('SMS template failed', [
                'mobile' => $mobile,
                'template' => $template,
                'response' => $data,
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('SMS Template Exception: '.$e->getMessage(), [
                'mobile' => $mobile,
                'template' => $template,
            ]);

            return false;
        }
    }
}