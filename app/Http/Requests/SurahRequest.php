<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SurahRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'verse' => 'sometimes|integer|min:1',
            'text_edition' => [
                'sometimes', 'integer', 'min:1', 'nullable',
                Rule::exists('editions', 'id')->where('format', 'text')
            ],
            'audio_edition' => [
                'sometimes', 'integer', 'min:1', 'nullable',
                Rule::exists('editions', 'id')->where('format', 'audio')
            ],
        ];
    }

    public function validatedWithDefaults(): array
    {
        $validated = $this->validated();
        $validated['text_edition'] = $validated['text_edition'] ?? 1;
        $validated['audio_edition'] = $validated['audio_edition'] ?? 106;

        return $validated;
    }
}
