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
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ];
    }

    public function validatedWithDefaults(): array
    {
        $validated = $this->validated();
        $validated['text_edition'] = $validated['text_edition'] ?? 1;
        $validated['audio_edition'] = $validated['audio_edition'] ?? 106;
        $validated['page'] = $validated['page'] ?? 1;
        $validated['per_page'] = $validated['per_page'] ?? 20;

        return $validated;
    }
}
