<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceLogRequest extends FormRequest
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
            'logs' => 'sometimes|array',
            'log' => 'sometimes|array',
            'log.logs_array' => 'sometimes|array',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function logs(): array
    {
        if ($this->has('logs')) {
            return (array) $this->json('logs', []);
        }

        if ($this->has('log.logs_array')) {
            return (array) $this->json('log.logs_array', []);
        }

        return [];
    }
}
