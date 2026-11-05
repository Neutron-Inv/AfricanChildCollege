<?php

namespace App\Http\Requests\People;

use App\Services\PeopleService;
use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreStaffRequest extends FormRequest
{
    protected $errorBag = 'staff';

    public function authorize(): bool
    {
        return true; // role middleware on the route
    }

    public function rules(): array
    {
        $school = Tenant::id();

        return [
            'role' => ['required', Rule::in(array_map(fn ($r) => $r->value, PeopleService::STAFF_ROLES))],
            'index_number' => ['required', 'string', 'max:50', Rule::unique('staff', 'index_number')->where('school_id', $school)],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->where('school_id', $school)],
            'password' => ['required', 'string', Password::defaults()],
            'is_teacher' => ['nullable', 'boolean'],
        ];
    }
}
