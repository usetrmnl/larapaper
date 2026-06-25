<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->isConfirmed()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('status', 'Your account is awaiting admin approval.');
        }

        return $next($request);
    }
}
