<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitRunPayloadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'ticket_token' => ['required', 'string', 'min:32', 'max:128'],
            'ticks_elapsed' => ['required', 'integer', 'min:1'],
            'final_distance' => ['required', 'numeric', 'min:0'],
            'final_score' => ['required', 'integer', 'min:0'],
            'inputs' => ['present', 'array', 'max:5000'],
            'inputs.*.tick' => ['required', 'integer', 'min:0'],
            'inputs.*.action' => ['required', 'string', 'in:MOVE_LEFT,MOVE_RIGHT,JUMP,ROLL'],
            'inputs.*.x' => ['required', 'numeric'],
            'inputs.*.z' => ['required', 'numeric'],
            'inputs.*.y' => ['nullable', 'numeric'],
            'inputs.*.timestamp' => ['nullable', 'numeric'],
        ];
    }
}
