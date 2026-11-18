<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    /**
     * Show the login form.
     */
    public function showLogin(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $initialMode = $request->query('tab') === 'register' || session('mode') === 'register' || old('action') === 'register' ? 'register' : 'login';

        return view('auth.login', compact('initialMode'));
    }

    /**
     * Show the registration form.
     */
    public function showRegister(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $initialMode = 'register';

        return view('auth.login', compact('initialMode'));
    }

    /**
     * Handle an incoming registration request and log the user in directly.
     */
    public function register(Request $request): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
        ]);

        Auth::login($user, $request->boolean('remember', true));
        $request->session()->regenerate();

        return redirect()->intended('/dashboard')
            ->with('success', 'Account created successfully! Welcome to Passwire.');
    }

    /**
     * Handle an incoming authentication request with RateLimiter throttling and 2FA support.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = $this->throttleKey($request);

        // 1. Enforce login rate limit (5 attempts per minute per email + IP)
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            event(new Lockout($request));

            return back()->withErrors([
                'email' => __('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ])->onlyInput('email');
        }

        // 2. Validate credentials against user records
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            return back()->withErrors([
                'email' => 'The provided credentials do not match our records.',
            ])->onlyInput('email');
        }

        // 3. If Two-Factor Authentication is enabled, transition to 2FA challenge
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put('login.2fa_user_id', $user->id);
            $request->session()->put('login.remember', $request->boolean('remember'));

            return redirect()->route('login.two-factor');
        }

        // 4. Successful standard login without 2FA
        RateLimiter::clear($throttleKey);
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    /**
     * Show the two-factor authentication challenge form.
     */
    public function showTwoFactorChallenge(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('login.2fa_user_id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    /**
     * Verify the two-factor authentication challenge (TOTP or recovery code).
     */
    public function verifyTwoFactorChallenge(Request $request): RedirectResponse
    {
        if (! $request->session()->has('login.2fa_user_id')) {
            return redirect()->route('login');
        }

        $userId = $request->session()->get('login.2fa_user_id');
        $user = User::find($userId);

        if (! $user || ! $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->forget(['login.2fa_user_id', 'login.remember']);
            return redirect()->route('login');
        }

        $twoFactorThrottleKey = '2fa|' . $user->id . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($twoFactorThrottleKey, 5)) {
            $seconds = RateLimiter::availableIn($twoFactorThrottleKey);
            event(new Lockout($request));

            return back()->withErrors([
                'code' => __('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }

        $validated = $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $isValid = false;

        if (! empty($validated['recovery_code'])) {
            $isValid = $user->verifyRecoveryCode($validated['recovery_code']);
        } elseif (! empty($validated['code'])) {
            $isValid = $user->verifyTwoFactorCode($validated['code']);
        }

        if (! $isValid) {
            RateLimiter::hit($twoFactorThrottleKey, 60);

            return back()->withErrors([
                'code' => 'The provided two-factor authentication code is invalid.',
            ]);
        }

        RateLimiter::clear($twoFactorThrottleKey);
        RateLimiter::clear($this->throttleKey($request));

        $remember = $request->session()->pull('login.remember', false);
        $request->session()->forget('login.2fa_user_id');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    /**
     * Begin 2FA setup for the authenticated admin.
     */
    public function enableTwoFactor(Request $request, TwoFactorService $twoFactorService): RedirectResponse
    {
        $user = $request->user();

        if (! $user->two_factor_secret || $user->two_factor_confirmed_at === null) {
            $user->two_factor_secret = $twoFactorService->generateSecretKey();
            $user->two_factor_confirmed_at = null;
            $user->save();
        }

        return redirect()->route('dashboard.password')
            ->with('two_factor_setup_started', true);
    }

    /**
     * Confirm 2FA setup by verifying a valid 6-digit TOTP code.
     */
    public function confirmTwoFactor(Request $request, TwoFactorService $twoFactorService): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        if (! $user->two_factor_secret || ! $twoFactorService->verify($user->two_factor_secret, $validated['code'])) {
            return back()->withErrors([
                'two_factor_code' => 'The provided code was invalid. Please check your authenticator app and try again.',
            ])->with('two_factor_setup_started', true);
        }

        $recoveryCodes = $twoFactorService->generateRecoveryCodes();

        $user->two_factor_confirmed_at = now();
        $user->two_factor_recovery_codes = $recoveryCodes;
        $user->save();

        return redirect()->route('dashboard.password')
            ->with('success', 'Two-factor authentication has been enabled successfully!')
            ->with('recovery_codes_generated', $recoveryCodes);
    }

    /**
     * Disable 2FA for the authenticated admin.
     */
    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return redirect()->route('dashboard.password')
            ->with('success', 'Two-factor authentication has been disabled.');
    }

    /**
     * Regenerate 2FA recovery codes.
     */
    public function regenerateRecoveryCodes(Request $request, TwoFactorService $twoFactorService): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('dashboard.password')
                ->withErrors(['current_password' => 'Two-factor authentication is not enabled.']);
        }

        $recoveryCodes = $twoFactorService->generateRecoveryCodes();
        $user->two_factor_recovery_codes = $recoveryCodes;
        $user->save();

        return redirect()->route('dashboard.password')
            ->with('success', 'New emergency recovery codes have been generated.')
            ->with('recovery_codes_generated', $recoveryCodes);
    }

    /**
     * Display the admin profile edit page.
     */
    public function editProfile(Request $request): View
    {
        $user = $request->user();

        return view('dashboard.profile', compact('user'));
    }

    /**
     * Update the authenticated admin's profile information.
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->save();

        return back()->with('success', 'Admin profile updated successfully.');
    }

    /**
     * Display the change password page.
     */
    public function editPassword(Request $request, TwoFactorService $twoFactorService): View
    {
        $user = $request->user();

        $twoFactorQrUri = null;
        if ($user->two_factor_secret) {
            $twoFactorQrUri = $twoFactorService->getQrCodeUri(
                config('app.name', 'Passwire'),
                $user->email,
                $user->two_factor_secret
            );
        }

        return view('dashboard.password', compact('user', 'twoFactorQrUri'));
    }

    /**
     * Update the admin's password.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->password = Hash::make($validated['password']);
        $user->save();

        return back()->with('success', 'Password updated successfully.');
    }

    /**
     * Log the user out of the application.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /**
     * Generate the throttle key for the incoming login request.
     */
    protected function throttleKey(Request $request): string
    {
        return Str::transliterate(Str::lower($request->input('email', ''))) . '|' . $request->ip();
    }
}
