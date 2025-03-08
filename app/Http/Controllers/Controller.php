<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;

abstract class Controller
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    // TODO: make status code here

    public function responseApi($success = false, $message = null, $data = [], $error = null)
    {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'error' => $error,
        ]);
    }

    public function apiSuccess($data, $message = '')
    {
        return $this->responseApi(true, $message, $data, null);
    }

    public function apiFail($message, $error = null)
    {
        return $this->responseApi(false, $message, null, $error);
    }

    public function apiError($message, $statusCode = 400)
    {
        return response()->json(['message' => $message], $statusCode);
    }
}
