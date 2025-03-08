<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $args = $request->validate([
            'page' => 'sometimes|int|min:1',
            'per_page' => 'sometimes|int|min:1',
            'name' => 'sometimes|string',
            'username' => 'sometimes|string',
        ]);

        $page = $args['page'] ?? 1;
        $perPage = $args['per_page'] ?? 20;

        $query = User::query()
            ->skip($page)
            ->limit($perPage)
            ->orderBy('id');

        if (isset($args['name'])) {
            $query->where('name', $args['name']);
        }

        if (isset($args['username'])) {
            $query->where('username', $args['username']);
        }

        $users = $query->skip(($page - 1) * $perPage)->take($perPage)->get();


        return $this->apiSuccess($users, 'Users returned successfully');
    }

    public function store(Request $request)
    {
        try {


        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'password' => 'required|string|min:6',
            'role' => 'required|string|in:user,admin'
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role']
        ]);

        return $this->apiSuccess($user, "User created successfully");
        } catch (\Exception $e) {
            return $this->apiError('Failed to create User');
        }
    }

    public function show(int $user)
    {
        $user = User::query()->findOrFail($user);

        return $this->apiSuccess($user, 'User retrieved successfully');
    }

    public function update(Request $request, int $user)
    {
        $user = User::query()->findOrFail($user);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'username' => 'sometimes|string|max:255|unique:users,username,' . $user->id,
            'password' => 'sometimes|string|min:6',
            'role' => 'sometimes|string|in:user,admin'
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);
        return $this->apiSuccess(null, "User updated successfully");
    }

    public function destroy(int $id)
    {
        $user = User::query()->whereNot('role', 'superadmin')->findOrFail($id);

        $user->delete();
        return $this->apiSuccess(null, "User deleted successfully");
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::query()->where("username", $credentials['username'])->first();

        if (!$user || !Hash::check($credentials['password'], $user['password'])) {
            return $this->apiError("Wrong credentials", 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        DB::table('personal_access_tokens')->where('tokenable_id', $user->user_id)->update(['last_used_at' => now()]);

        return $this->apiSuccess(['token' => $token, 'user' => $user], "Logged in successfully");
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return $this->apiSuccess(null, "Logged out successfully");
    }

    public function changeOwnPassword(Request $request)
    {
        try {
            $validated = $request->validate([
                'old_password' => 'required|string',
                'new_password' => 'required|string|min:6|confirmed'
            ]);

            if (!Hash::check($validated['old_password'], Auth::user()->getAuthPassword())) {
                return $this->apiError("Old password doesn't match current password");
            }

            $newPassword = Hash::make($validated['new_password']);

            $user = Auth::user();
            $user->update(['password' => $newPassword]);

            return $this->apiSuccess(null, "Password updated successfully");
        } catch (\Exception $e) {
            return $this->apiError('An error occurred while changing the password');
        }
    }

    public function changeUserPassword(Request $request, int $id)
    {
        try {
            $validated = $request->validate([
                'old_password' => 'required|string',
                'new_password' => 'required|string|min:6|confirmed'
            ]);

            $user = User::query()->findOrFail($id);

            // Check if the old password matches the current password
            if (!Hash::check($validated['old_password'], $user->getAuthPassword())) {
                return $this->apiError("Old password doesn't match current password");
            }

            $newPassword = Hash::make($validated['new_password']);

            $user->update(['password' => $newPassword]);

            return $this->apiSuccess(null, "Password updated successfully");
        } catch (\Exception $e) {
            return $this->apiError('An error occurred while changing the password');
        }

    }

    public function me()
    {
        return $this->apiSuccess(Auth::user(), "Current user retrieved successfully");
    }
}
