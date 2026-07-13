<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StaffController extends Controller
{
    /**
     * لیست همه‌ی کارمندهای داخلی (admin/warehouse/sales/support).
     * مشتری‌ها (role=customer) اینجا نمایش داده نمی‌شن.
     */
    public function index(Request $request)
    {
        $staff = User::whereIn('role', User::STAFF_ROLES)
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return response()->json($staff);
    }

    /**
     * ساخت کاربر staff جدید توسط ادمین.
     * فقط نقش‌های داخل STAFF_ROLES قابل واگذاریه؛ customer از این مسیر ساخته نمی‌شه
     * (customer از /register یا /verify-otp میاد).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'role' => ['required', 'string', 'in:' . implode(',', User::STAFF_ROLES)],
        ], [
            'role.in' => 'نقش انتخاب‌شده معتبر نیست.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password, // با cast('password' => 'hashed') خودکار هش می‌شه
            'role' => $request->role,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'کاربر staff با موفقیت ساخته شد.',
            'user' => $user,
        ], 201);
    }

    /**
     * ویرایش نقش یا وضعیت فعال/غیرفعال یک کاربر staff.
     * تغییر role مشتری‌ها از این مسیر مجاز نیست (باید از مسیر دیگه‌ای مدیریت بشه).
     */
    public function update(Request $request, User $user)
    {
        if (! $user->isStaff()) {
            return response()->json(['message' => 'این کاربر staff نیست.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'role' => ['sometimes', 'string', 'in:' . implode(',', User::STAFF_ROLES)],
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->update($validator->validated());

        return response()->json([
            'message' => 'اطلاعات کاربر staff بروزرسانی شد.',
            'user' => $user->fresh(),
        ]);
    }

    /**
     * غیرفعال کردن (نه حذف) یک کاربر staff.
     * حذف واقعی عمداً پیاده‌سازی نشده تا سابقه‌ی لاگ تغییرات (بند ۵ سند) از بین نره.
     */
    public function destroy(User $user)
    {
        if (! $user->isStaff()) {
            return response()->json(['message' => 'این کاربر staff نیست.'], 404);
        }

        $user->update(['is_active' => false]);

        return response()->json(['message' => 'کاربر staff غیرفعال شد.']);
    }
}
