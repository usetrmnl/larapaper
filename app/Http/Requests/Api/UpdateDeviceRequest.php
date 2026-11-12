<?php

namespace App\Http\Requests\Api;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * The device settings of TRMNL's Account API (PATCH /api/devices/{id}) that LaraPaper has.
 */
class UpdateDeviceRequest extends FormRequest
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
            'name' => 'string|max:255',
            'refresh_interval' => 'integer|min:1',
            'sleep_mode_enabled' => 'boolean',
            'sleep_start_time' => 'integer|between:0,1439',
            'sleep_end_time' => 'integer|between:0,1439',
            'sleep_until' => 'nullable|date',
        ];
    }

    /**
     * The given settings as device attributes. Times are minutes after midnight, and
     * sleep_until without an offset is in the user's time zone, as on TRMNL.
     *
     * @return array<string, mixed>
     */
    public function deviceAttributes(): array
    {
        return array_filter([
            'name' => $this->input('name'),
            'default_refresh_interval' => $this->has('refresh_interval') ? $this->integer('refresh_interval') : null,
            'sleep_mode_enabled' => $this->has('sleep_mode_enabled') ? $this->boolean('sleep_mode_enabled') : null,
            'sleep_mode_from' => $this->time('sleep_start_time') ?? $this->defaultTimeWhenTurningSleepOn('sleep_mode_from', Device::DEFAULT_SLEEP_MODE_FROM),
            'sleep_mode_to' => $this->time('sleep_end_time') ?? $this->defaultTimeWhenTurningSleepOn('sleep_mode_to', Device::DEFAULT_SLEEP_MODE_TO),
        ], fn (mixed $value): bool => $value !== null) + ($this->has('sleep_until') ? [
            'pause_until' => $this->filled('sleep_until')
                ? Carbon::parse($this->string('sleep_until'), $this->user()->preferredTimezone())->setTimezone(config('app.timezone'))
                : null,
        ] : []);
    }

    private function defaultTimeWhenTurningSleepOn(string $attribute, string $default): ?string
    {
        return $this->boolean('sleep_mode_enabled') && $this->route('device')->{$attribute} === null ? $default : null;
    }

    private function time(string $key): ?string
    {
        if (! $this->has($key)) {
            return null;
        }

        $minutes = $this->integer($key);

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
