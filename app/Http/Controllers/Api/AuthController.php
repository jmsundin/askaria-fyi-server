<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = JWTAuth::fromUser($user);

        $response = response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 201);

        return $response->withCookie(cookie(
            name: config('session.cookie'),
            value: $token,
            minutes: config('session.lifetime'),
            path: config('session.path', '/'),
            domain: config('session.domain'),
            secure: (bool) config('session.secure'),
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            if (! $token = JWTAuth::attempt($credentials)) {
                return response()
                    ->json(['message' => 'Invalid credentials'], 401)
                    ->withoutCookie(config('session.cookie'));
            }
        } catch (\Throwable $exception) {
            report($exception);

            return response()
                ->json(['message' => 'Unable to process login request'], 500)
                ->withoutCookie(config('session.cookie'));
        }

        /** @var User $user */
        $user = Auth::guard('api')->user();

        $response = response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);

        return $response->withCookie(cookie(
            name: config('session.cookie'),
            value: $token,
            minutes: config('session.lifetime'),
            path: config('session.path', '/'),
            domain: config('session.domain'),
            secure: (bool) config('session.secure'),
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    public function me(Request $request)
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'agent_profile' => $user->agentProfile,
        ]);
    }
}
