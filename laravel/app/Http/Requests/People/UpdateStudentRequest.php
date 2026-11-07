<?php

namespace App\Http\Requests\People;

use App\Enums\Role;
use App\Models\Student;
use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateStudentRequest extends FormRequest
{
    protected $errorBag = 'student';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $school = Tenant::id();
        $student = Student::query()->with('user')->find($this->route('student'));

        return [
            'index_number' => ['required', 'string', 'max:50', Rule::unique('students', 'index_number')->where('school_id', $school)->ignore($student?->getKey())],
            'full_name' => ['required', 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(['Male', 'Female'])],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->where('school_id', $school)->ignore($student?->user?->getKey())],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::in(['ACTIVE', 'LEFT', 'GRADUATED'])],
        ] + ($this->user()?->hasRole(Role::ADMIN, Role::PLATFORM_OWNER) ? [
            'password' => ['nullable', 'string', Password::defaults()],
        ] : []);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $student = Student::query()->with('user')->find($this->route('student'));
            if ($student?->user && blank($this->input('email'))) {
                $validator->errors()->add('email', 'This student has a login, so the email is required.');
            }
        });
    }
}
