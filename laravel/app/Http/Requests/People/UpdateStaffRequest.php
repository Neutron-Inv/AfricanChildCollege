<?php

namespace App\Http\Requests\People;

use App\Models\Staff;
use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateStaffRequest extends FormRequest
{
    protected $errorBag = 'staffEdit';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $school = Tenant::id();
        $staff = Staff::query()->with('user')->find($this->route('staff'));

        return [
            'index_number' => ['required', 'string', 'max:50', Rule::unique('staff', 'index_number')->where('school_id', $school)->ignore($staff?->getKey())],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->where('school_id', $school)->ignore($staff?->user?->getKey())],
            'phone' => ['nullable', 'string', 'max:50'],
            'gender' => ['nullable', Rule::in(['Male', 'Female'])],
            'address' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', Password::defaults()],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $staff = Staff::query()->with('user')->find($this->route('staff'));
            if ($staff?->user && blank($this->input('email'))) {
                $validator->errors()->add('email', 'This staff member has a login, so the email is required.');
            }
        });
    }
}
