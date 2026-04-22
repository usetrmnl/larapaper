<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDisplayStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'device_id' => 'required|exists:devices,id',
            'name' => 'string|max:255',
            'default_refresh_interval' => 'integer|min:1',
            'sleep_mode_enabled' => 'boolean',
            'sleep_mode_from' => 'nullable|required_if:sleep_mode_enabled,true|date_format:H:i',
            'sleep_mode_to' => 'nullable|required_if:sleep_mode_enabled,true|date_format:H:i',
            'pause_until' => 'nullable|date|after:now',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function updatableFields(): array
    {
        return $this->only([
            'name',
            'default_refresh_interval',
            'sleep_mode_enabled',
            'sleep_mode_from',
            'sleep_mode_to',
            'pause_until',
        ]);
    }
}
