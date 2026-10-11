<?php

namespace App\Website\Publishing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class WebsitePublicationNotReady extends ValidationException
{
    public function __construct(public readonly array $diagnostics)
    {
        $validator = Validator::make([], []);
        $validator->errors()->add('publication', 'The Website Project is not ready to publish.');
        parent::__construct($validator);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'The Website Project is not ready to publish.',
            'errors' => $this->errors(),
            'diagnostics' => $this->diagnostics,
        ], 422);
    }
}
