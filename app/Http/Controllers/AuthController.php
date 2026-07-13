<?php

namespace App\Http\Controllers;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * ثبت‌نام با ایمیل/پسورد. عمداً phone اینجا نیست - اگه بعداً لازم شد
     * لاگین با OTP، اون مسیر (sendOtp/verifyOtp) کاملاً مستقله و خودش
     * موقع اولین ورود، mobile رو از کاربر می‌گیره و ثبت می‌کنه.
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password, // خودش هش می‌شه چون cast('password' => 'hashed') داریم
            'role' => User::ROLE_CUSTOMER,
        ]);

        return response()->json([
            'message' => 'ثبت‌نام با موفقیت انجام شد.',
            'user' => $user,
        ], 201);
    }

    /**
     * ورود با ایمیل/پسورد. از Password Grant خودِ اپ (درخواست داخلی به
     * /oauth/token) استفاده می‌کنه تا access_token + refresh_token بگیره.
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        

        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'ایمیل یا رمز عبور اشتباه است.'], 401);
        }

      

        /** @var User $user */
        $user = User::where('email', $request->email)->first();

        if (! $user->is_active) {
            return response()->json(['message' => 'حساب کاربری شما غیرفعال شده است.'], 403);
        }

        Log::info('Passport Config', [
            'client_id' => config('services.passport.password_client_id'),
            'client_secret' => config('services.passport.password_client_secret'),
        ]);

        $tokenResponse = Http::asForm()->post(url('/oauth/token'), [
            'grant_type' => 'password',
            'client_id' => config('services.passport.password_client_id'),
            'client_secret' => config('services.passport.password_client_secret'),
            'username' => $request->email,
            'password' => $request->password,
            'scope' => '',
        ]);


        Log::info('Passport Response', [
            'status' => $tokenResponse->status(),
            'body' => $tokenResponse->body(),
        ]);
        if (! $tokenResponse->successful()) {
            return response()->json(['message' => 'خطا در دریافت توکن. تنظیمات Passport Client رو چک کن.'], 500);
        }

        return response()->json(array_merge($tokenResponse->json(), ['user' => $user]));
    }

    /**
     * گرفتن access_token جدید با استفاده از refresh_token.
     */
    public function refresh(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

       

        $tokenResponse = Http::asForm()->post(url('/oauth/token'), [
            'grant_type' => 'refresh_token',
            'refresh_token' => $request->refresh_token,
            'client_id' => config('services.passport.password_client_id'),
            'client_secret' => config('services.passport.password_client_secret'),
            'scope' => '',
        ]);

       

        if (! $tokenResponse->successful()) {
            return response()->json(['message' => 'refresh token نامعتبر یا منقضی‌شده است.'], 401);
        }

        $data = $tokenResponse->json();

        // decode کردن payload توکن جدید برای پیدا کردن صاحبش (بدون نیاز به verify امضا،
        // چون خودمون همین الان از /oauth/token گرفتیمش و قابل اعتماده)
        $payloadRaw = explode('.', $data['access_token'])[1] ?? null;
        $payload = $payloadRaw ? json_decode(base64_decode(strtr($payloadRaw, '-_', '+/')), true) : null;

        $userId = $payload['sub'] ?? null;
        $user = $userId ? User::find($userId) : null;

        if (! $user || ! $user->is_active) {
            // کاربر غیرفعاله؛ توکن تازه‌صادرشده رو فوراً باطل می‌کنیم
            if ($user && isset($payload['jti'])) {
                \Laravel\Passport\Token::where('id', $payload['jti'])->update(['revoked' => true]);
            }

            return response()->json(['message' => 'حساب کاربری شما غیرفعال شده است.'], 403);
        }

        return response()->json($data);
    }

    /**
     * ارسال کد یک‌بارمصرف به موبایل. حداکثر هر ۲ دقیقه یک‌بار قابل درخواسته.
     */
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => ['required', 'regex:/^09[0-9]{9}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $mobile = $request->mobile;

        $recentOtp = OtpCode::where('mobile', $mobile)
            ->where('created_at', '>', now()->subMinutes(2))
            ->first();

        if ($recentOtp) {
            return response()->json([
                'message' => 'لطفاً ۲ دقیقه صبر کنید و دوباره تلاش کنید.',
            ], 429);
        }

        $code = (string) random_int(100000, 999999);

        OtpCode::create([
            'mobile' => $mobile,
            // 'code' => $code,
            'code' => Hash::make($code),
            'used' => false,
            'expires_at' => now()->addMinutes(5),
        ]);

        try {
            app(SmsService::class)->sendByTemplate($mobile, 'yadak-otp', [$code]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('خطا در ارسال پیامک OTP', ['error' => $e->getMessage()]);
        }

        return response()->json(['message' => 'کد تأیید ارسال شد.']);
    }

    /**
     * تأیید کد و ورود. اگه کاربری با این موبایل نبود، خودکار ساخته می‌شه.
     */
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => ['required', 'regex:/^09[0-9]{9}$/'],
            'code' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $mobile = $request->mobile;

        $otpRecord = OtpCode::where('mobile', $mobile)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $otpRecord) {
            return response()->json(['message' => 'کد وارد شده اشتباه یا منقضی‌شده است.'], 401);
        }

        if ($otpRecord->attempts >= 5) {
            return response()->json(['message' => 'تعداد تلاش‌های مجاز به پایان رسیده. کد جدید درخواست کنید.'], 429);
        }

        if (! Hash::check($request->code, $otpRecord->code)) {
            $otpRecord->increment('attempts');
            return response()->json(['message' => 'کد وارد شده اشتباه یا منقضی‌شده است.'], 401);
        }


        $otpRecord->update(['used' => true]);

        $user = User::where('phone', $mobile)->first();
        $isNewUser = false;

        if (! $user) {
            $user = User::create([
                'name' => 'کاربر ' . substr($mobile, -4),
                'phone' => $mobile,
                'phone_verified' => true,
                'is_active' => true,
                'email' => null,
                'password' => bin2hex(random_bytes(16)), // پسورد تصادفی، چون این کاربر با OTP لاگین می‌کنه نه پسورد
                'role' => User::ROLE_CUSTOMER,
            ]);
            $isNewUser = true;
        } elseif (! $user->phone_verified) {
            $user->update(['phone_verified' => true]);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'حساب کاربری شما غیرفعال شده است.'], 403);
        }

        // یه پسورد تصادفی و یک‌بارمصرف توی ستون جداگانه‌ی otp_password می‌سازیم
        // (نه ستون password اصلی!) تا بتونیم از مسیر Password Grant توکن بگیریم،
        // بدون اینکه پسورد واقعی کاربر (اگه با ایمیل/پسورد هم ثبت‌نام کرده باشه) خراب بشه.
        $tempPassword = bin2hex(random_bytes(16));
        $user->update(['otp_password' => $tempPassword]);

        $tokenResponse = Http::asForm()->post(url('/oauth/token'), [
            'grant_type' => 'password',
            'client_id' => config('services.passport.password_client_id'),
            'client_secret' => config('services.passport.password_client_secret'),
            'username' => $mobile,
            'password' => $tempPassword,
            'scope' => '',
        ]);

        if (! $tokenResponse->successful()) {
            return response()->json(['message' => 'خطا در دریافت توکن. تنظیمات Passport Client رو چک کن.'], 500);
        }

        return response()->json(array_merge($tokenResponse->json(), [
            'user' => $user,
            'is_new_user' => $isNewUser,
        ]));
    }

    /**
     * درخواست ارسال لینک بازیابی رمز عبور.
     * عمداً پیام یکسان برمی‌گردونیم چه ایمیل وجود داشته باشه چه نه،
     * تا کسی نتونه با این endpoint بفهمه چه ایمیل‌هایی توی سیستم ثبت شدن.
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'اگر این ایمیل در سیستم ثبت شده باشد، لینک بازیابی رمز عبور برایش ارسال شد.',
        ]);
    }

    /**
     * تنظیم رمز عبور جدید با استفاده از token ای که از ایمیل گرفته شده.
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:6|confirmed',
        ], [
            'password.confirmed' => 'رمز عبور و تکرار آن مطابقت ندارند.',
            'password.min' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->update(['password' => $password]); // خام بده، cast خودش هش می‌کنه
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'لینک بازیابی نامعتبر یا منقضی‌شده است.'], 400);
        }

        return response()->json(['message' => 'رمز عبور با موفقیت تغییر کرد.']);
    }

    /**
     * لاگ‌اوت و باطل کردن توکن فعلی.
     */
    public function logout(Request $request)
    {
        $request->user()->token()->revoke();

        return response()->json(['message' => 'خروج با موفقیت انجام شد.']);
    }

    /**
     * گرفتن اطلاعات کاربر لاگین‌شده.
     */
    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}
