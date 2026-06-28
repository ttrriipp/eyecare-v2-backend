<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterCustomerRequest;
use App\Http\Requests\Api\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function register(RegisterCustomerRequest $request): JsonResponse
    {
        $customerRole = Role::query()->where('name', 'customer')->firstOrFail();

        $user = User::query()->create([
            ...$request->safe()->only(['name', 'email', 'phone', 'password']),
            'role_id' => $customerRole->id,
        ]);

        $user->load('role');

        return response()->json([
            'data' => [
                'token' => $user->createToken('mobile')->plainTextToken,
                'user' => UserResource::make($user),
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $request->authenticate();
        $user->load('role');

        return response()->json([
            'data' => [
                'token' => $user->createToken('mobile')->plainTextToken,
                'user' => UserResource::make($user),
            ],
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('role');

        return response()->json([
            'data' => UserResource::make($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json();
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());
        $user->load('role');

        return response()->json([
            'data' => UserResource::make($user),
        ]);
    }
}
