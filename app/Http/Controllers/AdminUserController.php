<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    /**
     * جستجوی سریع کاربران (برای فرم‌هایی مثل انتخاب صاحب کد معرف که نیاز
     * به انتخاب یه کاربر از بین همه‌ی کاربران دارن، نه فقط کارمندان).
     * روی name/phone/email جستجو می‌کنه، حداکثر ۱۰ نتیجه برمی‌گردونه.
     */
    public function search(Request $request)
    {
        $query = $request->string('query')->toString();

        $users = User::query()
            ->when($query, function ($q) use ($query) {
                $q->where(function ($q2) use ($query) {
                    $q2->where('name', 'like', "%{$query}%")
                        ->orWhere('phone', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%");
                });
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'phone', 'email', 'role']);

        return response()->json(['data' => $users]);
    }
}
