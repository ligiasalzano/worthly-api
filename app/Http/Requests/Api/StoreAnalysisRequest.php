<?php

namespace App\Http\Requests\Api;

use App\Enums\InputType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'input_type' => [
                'required',
                Rule::in([InputType::Text->value, InputType::Image->value]),
            ],
            'query' => [
                Rule::requiredIf(fn () => $this->input('input_type') === InputType::Text->value),
                'nullable',
                'string',
                'max:1000',
            ],
            'image' => [
                Rule::requiredIf(fn () => $this->input('input_type') === InputType::Image->value),
                'nullable',
                'file',
                'image',
                'mimes:jpeg,png,webp',
                'max:8192',
            ],
        ];
    }

    public function inputTypeEnum(): InputType
    {
        return InputType::from($this->validated('input_type'));
    }
}
