<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgentProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'business_phone_number' => ['nullable', 'string', 'max:50'],
            'business_overview' => ['required', 'string'],
            'core_services' => ['nullable', 'array'],
            'core_services.*' => ['string', 'max:255'],
            'faq' => ['nullable', 'array'],
            'faq.*.question' => ['nullable', 'string', 'max:255'],
            'faq.*.answer' => ['nullable', 'string'],
        ];
    }
}
