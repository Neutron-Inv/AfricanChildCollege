<?php

namespace App\Http\Requests\People;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreGuardianRequest extends FormRequest
{
    /** Keeps this form's errors apart from the create-student form. */
    protected $errorBag = 'guardian';

    public function authorize(): bool
    {
        return true; // role middleware on the route
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255', 'required_with:password'],
            'password' => ['nullable', 'string', Password::defaults()],
            'relationship' => ['nullable', 'string', 'max:50'],
        ];
    }
}
