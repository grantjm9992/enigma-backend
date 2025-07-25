<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => 'required|email|unique:users,email',
            'role' => 'required|in:admin,trainer,student',
            'subscription_plan' => 'nullable|in:basic,premium,elite,trial',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'email.required' => 'El email es requerido.',
            'email.email' => 'El email debe ser una dirección válida.',
            'email.unique' => 'Este email ya está registrado.',
            'role.required' => 'El rol es requerido.',
            'role.in' => 'El rol debe ser admin, trainer o student.',
            'first_name.string' => 'El nombre debe ser texto.',
            'first_name.max' => 'El nombre no puede tener más de 255 caracteres.',
            'last_name.string' => 'El apellido debe ser texto.',
            'last_name.max' => 'El apellido no puede tener más de 255 caracteres.',
            'phone.string' => 'El teléfono debe ser texto.',
            'phone.max' => 'El teléfono no puede tener más de 20 caracteres.',
        ];
    }
}

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $targetUser = $this->route('user');

        // Admins can update anyone, trainers can update students, users can update themselves
        return $user && (
                $user->isAdmin() ||
                ($user->isTrainer() && $targetUser->isStudent()) ||
                $user->id === $targetUser->id
            );
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'email' => ['sometimes', 'email', Rule::unique('users')->ignore($userId)],
            'role' => 'sometimes|in:admin,trainer,student',
            'subscription_plan' => 'nullable|in:basic,premium,elite,trial',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'email.email' => 'El email debe ser una dirección válida.',
            'email.unique' => 'Este email ya está registrado.',
            'role.in' => 'El rol debe ser admin, trainer o student.',
            'first_name.string' => 'El nombre debe ser texto.',
            'first_name.max' => 'El nombre no puede tener más de 255 caracteres.',
            'last_name.string' => 'El apellido debe ser texto.',
            'last_name.max' => 'El apellido no puede tener más de 255 caracteres.',
            'phone.string' => 'El teléfono debe ser texto.',
            'phone.max' => 'El teléfono no puede tener más de 20 caracteres.',
            'is_active.boolean' => 'El estado activo debe ser verdadero o falso.',
        ];
    }
}

class UpdateStudentProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $student = $this->route('student');

        // Admins and trainers can update any student, students can update themselves
        return $user && (
                $user->isAdmin() ||
                $user->isTrainer() ||
                ($user->isStudent() && $user->id === $student->id)
            );
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'age' => 'nullable|integer|min:1|max:100',
            'height' => 'nullable|numeric|min:50|max:250',
            'weight' => 'nullable|numeric|min:20|max:200',
            'level' => 'sometimes|in:principiante,intermedio,avanzado,competidor,elite',
            'strengths' => 'nullable|array',
            'strengths.*' => 'string|max:255',
            'weaknesses' => 'nullable|array',
            'weaknesses.*' => 'string|max:255',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'age.integer' => 'La edad debe ser un número entero.',
            'age.min' => 'La edad debe ser al menos 1.',
            'age.max' => 'La edad no puede ser mayor a 100.',
            'height.numeric' => 'La altura debe ser un número.',
            'height.min' => 'La altura mínima es 50cm.',
            'height.max' => 'La altura máxima es 250cm.',
            'weight.numeric' => 'El peso debe ser un número.',
            'weight.min' => 'El peso mínimo es 20kg.',
            'weight.max' => 'El peso máximo es 200kg.',
            'level.in' => 'El nivel debe ser: principiante, intermedio, avanzado, competidor o elite.',
            'strengths.array' => 'Las fortalezas deben ser un array.',
            'strengths.*.string' => 'Cada fortaleza debe ser texto.',
            'strengths.*.max' => 'Cada fortaleza no puede tener más de 255 caracteres.',
            'weaknesses.array' => 'Las debilidades deben ser un array.',
            'weaknesses.*.string' => 'Cada debilidad debe ser texto.',
            'weaknesses.*.max' => 'Cada debilidad no puede tener más de 255 caracteres.',
            'notes.string' => 'Las notas deben ser texto.',
            'notes.max' => 'Las notas no pueden tener más de 2000 caracteres.',
        ];
    }
}
