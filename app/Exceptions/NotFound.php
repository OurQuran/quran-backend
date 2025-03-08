<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
class NotFound extends Exception
{

    protected $message;

    public function __construct($source = 'wanted source')
    {
        $this->message = $source.' not found';

        parent::__construct($this->message, 404);
    }

    public function render($request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $this->message,
        ], 404);
    }

}
