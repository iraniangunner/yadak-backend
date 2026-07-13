<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * استفاده توی route: ->middleware(['auth:api', 'role:admin'])
     * یا چند نقش با هم: ->middleware(['auth:api', 'role:admin,warehouse'])
     *
     * نکته: Laravel وقتی چندتا آرگومان با کاما بعد از : بیاد، اون‌ها رو
     * به‌صورت چند پارامتر جدا صدا می‌زنه (نه یه رشته‌ی تکی)، برای همین
     * اینجا از ...$roles (variadic) استفاده شده، نه یه پارامتر رشته‌ای تنها.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! $request->user() || ! in_array($request->user()->role, $roles, true)) {
            return response()->json(['message' => 'شما دسترسی لازم برای این بخش را ندارید.'], 403);
        }

        return $next($request);
    }
}
