<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ImportPluginArchiveRequest;
use App\Models\Plugin;
use App\Services\PluginExportService;
use App\Services\PluginImportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PluginArchiveController extends Controller
{
    public function __construct(
        private PluginExportService $exporter,
        private PluginImportService $importer,
    ) {}

    public function export(string $trmnlp_id): BinaryFileResponse
    {
        if (mb_trim($trmnlp_id) === '') {
            abort(400, 'trmnlp_id is required');
        }

        $plugin = Plugin::where('trmnlp_id', $trmnlp_id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        return $this->exporter->exportToZip($plugin, auth()->user());
    }

    public function import(ImportPluginArchiveRequest $request, string $trmnlp_id): JsonResponse
    {
        $plugin = $this->importer->importFromZip($request->file('file'), auth()->user());

        return response()->json([
            'message' => 'Plugin settings archive processed successfully',
            'data' => [
                'settings_yaml' => $plugin['trmnlp_yaml'],
            ],
        ]);
    }
}
