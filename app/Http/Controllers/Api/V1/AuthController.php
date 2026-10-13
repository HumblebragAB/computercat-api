<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

class AuthController extends Controller
{
    public function anonymous(Request $request): JsonResponse
    {
        $displayName = $request->input('display_name');
        if ($displayName) {
            $displayName = trim(strip_tags($displayName));
            if (! preg_match('/^[\p{L}\p{N}\p{Zs}\-_.!]{1,30}$/u', $displayName)) {
                $displayName = null;
            }
        }

        $user = User::create([
            'name' => 'Player-'.Str::random(8),
            'display_name' => $displayName,
            'is_anonymous' => true,
        ]);

        $token = $user->createToken('anonymous', ['*'], $this->tokenExpiresAt())->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)],
            'display_name' => ['nullable', 'string', 'max:30', 'regex:/^[\p{L}\p{N}\p{Zs}\-_.!]+$/u'],
        ]);

        if (isset($validated['display_name'])) {
            $validated['display_name'] = trim(strip_tags($validated['display_name']));
        }

        $user = $request->user();

        if ($user && $user->is_anonymous) {
            // Upgrade anonymous account
            $user->update([
                'email' => $validated['email'],
                'password' => $validated['password'],
                'display_name' => $validated['display_name'] ?? $user->display_name,
                'is_anonymous' => false,
            ]);
        } else {
            // Create new account
            $user = User::create([
                'name' => $validated['display_name'] ?? 'Player-'.Str::random(8),
                'email' => $validated['email'],
                'password' => $validated['password'],
                'display_name' => $validated['display_name'],
                'is_anonymous' => false,
            ]);
        }

        $token = $user->createToken('auth', ['*'], $this->tokenExpiresAt())->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if ($user->is_banned) {
            return response()->json(['message' => 'Account is banned.'], 403);
        }

        $token = $user->createToken('auth', ['*'], $this->tokenExpiresAt())->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    // Byter det aktuella tokenet mot ett nytt med full livslängd. Det gamla
    // får leva högst 10 minuter till, så att parallella flikar och anrop som
    // redan är på väg inte får 401 mitt i bytet.
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();

        // Bara bearer-token kan förnyas. En sessionsinloggning (statefulApi)
        // ger ett TransientToken som inte går att korta eller kopiera.
        if (! $current instanceof PersonalAccessToken) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Samma regel som login: ett bannat konto får inga nya token.
        if ($user->is_banned) {
            return response()->json(['message' => 'Account is banned.'], 403);
        }

        $expiresAt = $this->tokenExpiresAt();
        $new = $user->createToken($current->name, $current->abilities ?? ['*'], $expiresAt);

        $grace = now()->addMinutes(10);
        if (! $current->expires_at || $current->expires_at->gt($grace)) {
            $current->forceFill(['expires_at' => $grace])->save();
        }

        return response()->json([
            'token' => $new->plainTextToken,
            'user' => new UserResource($user),
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:30', 'regex:/^[\p{L}\p{N}\p{Zs}\-_.!]+$/u'],
        ], [
            'display_name.regex' => 'Display name can only contain letters, numbers, spaces, and basic punctuation (- _ . !).',
        ]);

        // Strip any remaining HTML and trim whitespace
        $validated['display_name'] = trim(strip_tags($validated['display_name']));

        if (strlen($validated['display_name']) < 1) {
            return response()->json(['message' => 'Display name cannot be empty.'], 422);
        }

        $request->user()->update($validated);

        return response()->json([
            'user' => new UserResource($request->user()->fresh()),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Account deleted.']);
    }

    // Varje token får ett uttryckligt expires_at med livslängden ur
    // sanctum.expiration. Guarden prövar både created_at mot den gränsen och
    // tokenets expires_at (Laravel\Sanctum\Guard::isValidAccessToken), så
    // värdet syns i databasen i stället för att vara implicit.
    private function tokenExpiresAt(): Carbon
    {
        $minutes = (int) config('sanctum.expiration');

        // 0 eller tomt betyder i Sanctum att token aldrig går ut. Det ska
        // aldrig hända av misstag via en tom miljövariabel.
        if ($minutes <= 0) {
            throw new RuntimeException('sanctum.expiration måste vara ett positivt antal minuter.');
        }

        return now()->addMinutes($minutes);
    }
}
