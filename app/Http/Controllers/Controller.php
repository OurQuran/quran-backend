<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Laravel\Sanctum\PersonalAccessToken;

abstract class Controller
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    public function responseApi($success = false, $message = null, $data = [], $error = null, $statusCode = 200)
    {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'error' => $error,
        ], $statusCode);
    }

    public function apiSuccess($data, $message = '', $statusCode = 200)
    {
        return $this->responseApi(true, $message, $data, null, $statusCode);
    }

    public function apiError($message, $statusCode = 400)
    {
        return response()->json(['message' => $message], $statusCode);
    }

    public function checkLoginToken(){
        if ($token = request()->bearerToken()) {
            // Find the user by the token
            $personalAccessToken = PersonalAccessToken::findToken($token);

            if ($personalAccessToken) {
                return $personalAccessToken->tokenable; // Get the user associated with the token
            }

            return null;
        }

        return null;
    }
}
