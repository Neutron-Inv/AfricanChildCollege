<?php

namespace App\Http\Requests\People;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role middleware on the route
    }

    public function rules(): array
    {
        $school = Tenant::id();

        return [
            'index_number' => ['required', 'string', 'max:50', Rule::unique('students', 'index_number')->where('school_id', $school)],
            'full_name' => ['required', 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(['Male', 'Female'])],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->where('school_id', $school)],
            'password' => ['required', 'string', Password::defaults()],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'class_id' => ['nullable', 'string'],
        ];
    }
}
