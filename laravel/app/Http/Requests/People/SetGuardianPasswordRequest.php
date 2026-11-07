<?php

namespace App\Http\Requests\People;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class SetGuardianPasswordRequest extends FormRequest
{
    protected $errorBag = 'guardianPassword';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', Password::defaults()],
            'q' => ['nullable', 'string', 'max:255'],
        ];
    }
}
