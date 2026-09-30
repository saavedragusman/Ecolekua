<?php

namespace App\Http\Requests\Users;

use App\Models\User;
use App\Support\Users\UserRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');

        return UserRules::identity(ignoring: $target);
    }
}
