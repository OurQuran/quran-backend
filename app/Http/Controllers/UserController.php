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
        $validated = $request->validate([
            'page' => 'sometimes|int|min:1',
            'per_page' => 'sometimes|int|min:1|max:100',
            'name' => 'sometimes|nullable|string',
            'username' => 'sometimes|nullable|string',
        ]);

        $page = (int)($validated['page'] ?? 1);
        $perPage = (int)($validated['per_page'] ?? 20);

        $query = User::query()->orderBy('id');

        if (isset($validated['name'])) {
            $query->where('name', $validated['name']);
        }

        // Use ILIKE for PostgreSQL case-insensitive search
        if (isset($validated['username'])) {
            $query->whereRaw('username ILIKE ?', ["%{$validated['username']}%"]);
        }

        // Get total count before pagination
        $totalCount = $query->count();
        $totalPages = ceil($totalCount / $perPage);

        // Paginate results
        $users = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return $this->apiSuccess([
            'meta' => [
                'total_count' => $totalCount,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'page_size' => $perPage
            ],
            'result' => $users
        ], 'Users retrieved successfully');
    }

    public function store(Request $request)
    {
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

        return $this->apiSuccess($user, "User created successfully", 201);
    }

    public function show(User $user)
    {
        return $this->apiSuccess($user, 'User retrieved successfully');
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'username' => 'sometimes|string|max:255|unique:users,username,' . $user->id,
            'password' => 'sometimes|string|min:6',
            'role' => 'sometimes|string|in:user,admin'
        ]);

        if ($user->role === 'superadmin' && $validated['role']) {
            return $this->apiError('This user\'s role cannot be updated', 404);
        }

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);
        return $this->apiSuccess(null, "User updated successfully");
    }

    public function destroy(User $user)
    {
        if ($user->role === 'superadmin') {
            return $this->apiError('User not found', 404);
        }

        $user->delete();
        return $this->apiSuccess(null, "User deleted successfully");
    }

    public function login(Request $request)
    {
        // Validate incoming request data
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
            'remember' => 'nullable|boolean', // Remember me checkbox
        ]);

        // Check if user exists with the provided username
        $user = User::query()->where("username", $credentials['username'])->first();

        // If the user doesn't exist or password doesn't match, return error
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return $this->apiError("Wrong credentials", 401);
        }

        // Handle "remember me" functionality
        $remember = $credentials['remember'] ?? false;

        // Use Auth::attempt to log the user in with or without remember me
        if (Auth::attempt(['username' => $credentials['username'], 'password' => $credentials['password']], $remember)) {
            // After successful login, create a new Sanctum token for the user
            $token = $user->createToken('auth_token')->plainTextToken;

            // Optionally, update the last_used_at field for the user's token in personal_access_tokens
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $user->id) // Ensure using correct user ID field (might be `id`, not `user_id`)
                ->update(['last_used_at' => now()]);

            // Return the success response with the token and user data
            return $this->apiSuccess([
                'token' => $token,
                'user' => $user,
            ], "Logged in successfully");
        }

        // If login fails via session-based authentication, return error
        return $this->apiError("Wrong credentials", 401);
    }

    public function signup(Request $request)
    {
        // 1) Prevent signing up if already authenticated
        if ($this->checkLoginToken()) {
            return $this->apiError("You must logout in order to signup", 403);
        }

        // 2) Validate
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'password' => 'required|string|min:6',
        ]);

        // 3) Create the user
        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'password' => Hash::make($validated['password']),
            'role' => 'user',
        ]);

        // 4) Log them in *directly* with the model
        Auth::login($user, true);

        // 5) Create the Sanctum token
        $tokenResult = $user->createToken('auth_token');
        $plainText = $tokenResult->plainTextToken;

        // 6) Optionally update only *that* token’s last_used_at
        DB::table('personal_access_tokens')
            ->where('id', $tokenResult->accessToken->id)
            ->update(['last_used_at' => now()]);

        // 7) Return success
        return $this->apiSuccess([
            'token' => $plainText,
            'user' => $user,
        ], "User created successfully", 201);
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return $this->apiSuccess(null, "Logged out successfully");
    }

    public function deleteAccount(Request $request)
    {
        $validated = $request->validate([
            'password' => 'required|string|min:6|confirmed',
        ]);

        if (!Hash::check($validated['password'], Auth::user()->getAuthPassword())) {
            return $this->apiError("Password doesn't match current password");
        }

        User::query()
            ->where('id', Auth::id())
            ->whereNot('role', 'superadmin')
            ->firstOrFail()
            ->delete();

        return $this->apiSuccess(null, "Account deleted successfully");
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

    public function changeUserPassword(Request $request, User $user)
    {
        try {
            $validated = $request->validate([
                'new_password' => 'required|string|min:6|confirmed'
            ]);

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
