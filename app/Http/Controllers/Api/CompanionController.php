<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use App\Models\User;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MessagePack\MessagePack;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CompanionController extends Controller
{
    public const int COMPANION_PLUGIN_TYPE_ID = 37;

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        [$firstName, $lastName] = $this->splitName($user->name);
        $iana = $user->preferredTimezone();
        $zone = new DateTimeZone($iana);
        $utcOffset = $zone->getOffset(now($iana));

        return response()->json([
            'data' => [
                'name' => $user->name,
                'email' => $user->email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'locale' => (string) config('app.locale'),
                'time_zone' => $iana,
                'time_zone_iana' => $iana,
                'utc_offset' => $utcOffset,
            ],
        ]);
    }

    public function pluginSettings(Request $request): JsonResponse
    {
        $plugins = Plugin::query()
            ->where('user_id', $request->user()->id)
            ->where('plugin_type', 'recipe')
            ->where('data_strategy', 'webhook')
            ->orderBy('name')
            ->get()
            ->map(fn (Plugin $plugin): array => [
                'id' => $plugin->id,
                'name' => $plugin->name,
                'plugin_id' => self::COMPANION_PLUGIN_TYPE_ID,
                'strategy' => 'webhook',
                'read_only?' => false,
            ]);

        return response()->json(['data' => $plugins->values()]);
    }

    public function storeData(Request $request, Plugin $plugin): JsonResponse
    {
        if (! $this->pluginAcceptsCompanionUpload($request, $plugin)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $payload = $this->decodeRequestPayload($request);
        if ($payload === null) {
            return response()->json(['error' => 'Invalid request body'], Response::HTTP_BAD_REQUEST);
        }

        if (! is_array($payload) || ! array_key_exists('merge_variables', $payload)) {
            return response()->json(['error' => 'Request must contain merge_variables key'], Response::HTTP_BAD_REQUEST);
        }

        $mergeVariables = $payload['merge_variables'];
        if (! is_array($mergeVariables)) {
            return response()->json(['error' => 'merge_variables must be an object'], Response::HTTP_BAD_REQUEST);
        }

        if (! Plugin::dataPayloadWithinWireLimit($mergeVariables)) {
            return response()->json(Plugin::oversizedDataPayloadErrorPayload(), Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $plugin->update([
            'data_payload' => $mergeVariables,
            'data_payload_updated_at' => now(),
        ]);

        return response()->json(['message' => 'Data accepted']);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $trimmed = mb_trim($name);
        if ($trimmed === '') {
            return ['', ''];
        }

        $parts = explode(' ', $trimmed, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    private function pluginAcceptsCompanionUpload(Request $request, Plugin $plugin): bool
    {
        return $plugin->user_id === $request->user()->id
            && $plugin->plugin_type === 'recipe'
            && $plugin->data_strategy === 'webhook';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeRequestPayload(Request $request): ?array
    {
        $contentType = mb_strtolower($request->header('Content-Type', ''));

        if (str_contains($contentType, 'application/msgpack')) {
            $raw = $request->getContent();
            if ($raw === '' || $raw === false) {
                return null;
            }

            try {
                $decoded = MessagePack::unpack($raw);
            } catch (Throwable) {
                return null;
            }

            return is_array($decoded) ? $decoded : null;
        }

        if ($request->isJson() || str_contains($contentType, 'application/json')) {
            $decoded = $request->json()->all();

            return is_array($decoded) ? $decoded : null;
        }

        if ($request->has('merge_variables')) {
            return $request->all();
        }

        return null;
    }
}
