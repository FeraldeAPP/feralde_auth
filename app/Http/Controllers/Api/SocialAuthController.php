<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    private const SUPPORTED = ['google', 'facebook'];

    /**
     * Return the provider's OAuth redirect URL as JSON so the SPA can redirect the browser.
     */
    public function redirect(string $provider)
    {
        if (!in_array($provider, self::SUPPORTED)) {
            return response()->json(['success' => false, 'message' => 'Unsupported provider.'], 422);
        }

        $url = Socialite::driver($provider)->stateless()->redirect()->getTargetUrl();

        return response()->json(['success' => true, 'url' => $url]);
    }

    /**
     * Handle the OAuth callback from the provider.
     * Finds or creates the user, creates a session, then redirects the browser back to the frontend.
     */
    public function callback(string $provider)
    {
        $frontendUrl = config('services.frontend_url');

        if (!in_array($provider, self::SUPPORTED)) {
            return redirect($frontendUrl . '?social_error=unsupported');
        }

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Exception $e) {
            Log::warning("Social auth callback failed for {$provider}: " . $e->getMessage());
            return redirect($frontendUrl . '?social_error=auth_failed');
        }

        // Find existing social account link
        $socialAccount = SocialAccount::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if ($socialAccount) {
            $user = $socialAccount->user;
        } else {
            // Auto-link: find existing user by email, or create a new one
            $user = User::where('email', $socialUser->getEmail())->first();

            if (!$user) {
                // Create with only fillable fields, then set the rest directly
                $user = User::create([
                    'name'     => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
                    'email'    => $socialUser->getEmail(),
                    'password' => bcrypt(Str::random(32)),
                ]);
                $user->email_verified_at = now();
                $user->is_active         = true;
                $user->save();
                Log::info("New user created via {$provider} social login: user #{$user->id}");
            } elseif (!$user->email_verified_at) {
                // Email ownership confirmed by the provider
                $user->email_verified_at = now();
                $user->save();
            }

            SocialAccount::create([
                'user_id'                => $user->id,
                'provider'               => $provider,
                'provider_id'            => $socialUser->getId(),
                'provider_token'         => $socialUser->token,
                'provider_refresh_token' => $socialUser->refreshToken,
            ]);

            Log::info("Linked {$provider} account to user #{$user->id}");
        }

        if (!$user->is_active) {
            return redirect($frontendUrl . '?social_error=account_locked');
        }

        Auth::login($user);
        $user->last_login_at = now();
        $user->save();

        Log::info("User #{$user->id} signed in via {$provider}");

        return redirect($frontendUrl . '?social_login=1');
    }
}
