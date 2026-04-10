<?php

use App\Jobs\CheckVersionUpdateJob;
use App\Settings\UpdateSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

new class extends Component
{
    public ?string $latestVersion = null;

    public bool $isUpdateAvailable = false;

    public ?string $releaseNotes = null;

    public ?string $releaseUrl = null;

    public bool $prereleases = false;

    public bool $isChecking = false;

    public ?string $errorMessage = null;

    public ?int $backoffUntil = null;

    private UpdateSettings $updateSettings;

    public function boot(UpdateSettings $updateSettings): void
    {
        $this->updateSettings = $updateSettings;
    }

    public function mount(): void
    {
        $this->prereleases = $this->updateSettings->prereleases;
        $this->loadFromCache();
    }

    private function loadFromCache(): void
    {
        $currentVersion = config('app.version');
        if (! $currentVersion) {
            return;
        }

        // Load from cache without fetching
        $cachedRelease = Cache::get('latest_release');
        if ($cachedRelease) {
            $this->processCachedRelease($cachedRelease, $currentVersion);
        }
    }

    private function processCachedRelease($cachedRelease, string $currentVersion): void
    {
        $latestVersion = null;
        $releaseData = null;

        // Handle both single release object and array of releases
        if (is_array($cachedRelease) && isset($cachedRelease[0])) {
            // Array of releases - find the latest one
            $releaseData = $cachedRelease[0];
            $latestVersion = Arr::get($releaseData, 'tag_name');
        } else {
            // Single release object
            $releaseData = $cachedRelease;
            $latestVersion = Arr::get($releaseData, 'tag_name');
        }

        if ($latestVersion) {
            $this->latestVersion = $latestVersion;
            $this->isUpdateAvailable = version_compare($latestVersion, $currentVersion, '>');
            $this->releaseUrl = Arr::get($releaseData, 'html_url');
            $this->loadReleaseNotes();
        }
    }

    public function checkForUpdates(): void
    {
        $this->isChecking = true;
        $this->errorMessage = null;
        $this->backoffUntil = null;

        try {
            $result = CheckVersionUpdateJob::dispatchSync();

            $this->latestVersion = $result['latest_version'];
            $this->isUpdateAvailable = $result['is_newer'];
            $this->releaseUrl = Arr::get($result['release_data'] ?? [], 'html_url');

            // Handle errors
            if (isset($result['error'])) {
                if ($result['error'] === 'rate_limit') {
                    $this->backoffUntil = $result['backoff_until'] ?? null;
                    $this->errorMessage = 'GitHub API rate limit exceeded. Please try again later.';
                } elseif ($result['error'] === 'connection_failed') {
                    $this->errorMessage = 'Request timed out or failed to connect to GitHub. Please check your internet connection and try again.';
                } elseif ($result['error'] === 'fetch_failed') {
                    $this->errorMessage = 'Failed to fetch update information from GitHub. Please try again later.';
                } else {
                    $this->errorMessage = 'An unexpected error occurred while checking for updates. Please try again later.';
                }
            } else {
                // Reload release notes if we have a new version
                if ($this->latestVersion) {
                    $this->loadReleaseNotes();
                }
            }
        } catch (Illuminate\Http\Client\ConnectionException $e) {
            $this->errorMessage = 'Request timed out or failed to connect to GitHub. Please check your internet connection and try again.';
            Log::error('Update check connection failed: '.$e->getMessage());
        } catch (Exception $e) {
            $this->errorMessage = 'Request timed out or failed. Please check your internet connection and try again.';
            Log::error('Update check failed: '.$e->getMessage());
        } finally {
            $this->isChecking = false;
        }
    }

    public function updatedPrereleases(): void
    {
        $this->updateSettings->prereleases = $this->prereleases;
        $this->updateSettings->save();

        // Clear cache and recheck for updates with new preference
        Cache::forget('latest_release');
        $this->checkForUpdates();
    }

    public function loadReleaseNotes(): void
    {
        if (! $this->latestVersion) {
            return;
        }

        $cacheKey = "release_notes_{$this->latestVersion}";
        $currentVersionKey = 'release_notes_current_version';

        // Check if we have a previous version cached
        $previousVersion = Cache::get($currentVersionKey);

        // Clean up old version cache if different
        if ($previousVersion && $previousVersion !== $this->latestVersion) {
            Cache::forget("release_notes_{$previousVersion}");
        }

        // Try to get from cache first - always load from cache if available
        $cachedNotes = Cache::get($cacheKey);
        if ($cachedNotes) {
            $this->releaseNotes = $cachedNotes;
            // Update current version tracker
            Cache::put($currentVersionKey, $this->latestVersion, 86400);

            return;
        }

        // Fetch release notes if we have a version but no cache
        // This will fetch on mount if an update is available, or when explicitly checking
        $githubRepo = config('app.github_repo');
        $apiBaseUrl = "https://api.github.com/repos/{$githubRepo}";

        try {
            // Fetch release notes for the specific version with HTML format
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github.v3.html+json',
            ])->timeout(10)->get("{$apiBaseUrl}/releases/tags/{$this->latestVersion}");
            if ($response->successful()) {
                $releaseData = $response->json();
                $bodyHtml = Arr::get($releaseData, 'body_html');

                if ($bodyHtml) {
                    // Cache for 24 hours
                    Cache::put($cacheKey, $bodyHtml, 86400);
                    $this->releaseNotes = $bodyHtml;
                }
            }
        } catch (Exception $e) {
            Log::debug('Failed to fetch release notes: '.$e->getMessage());
        }

        // Update current version tracker
        Cache::put($currentVersionKey, $this->latestVersion, 86400);
    }
} ?>

<section class="w-full py-12">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        @include('partials.settings-heading')

        <x-pages::settings.layout heading="Updates">
            <div class="my-6 w-full space-y-6">
                @if(config('app.version'))
                    <div class="flex items-center justify-between">
                        <div>
                            <flux:heading size="sm">Current Version</flux:heading>
                            <flux:text class="text-sm">
                                <a href="https://github.com/{{ config('app.github_repo') }}/releases/" target="_blank" class="text-primary-600 dark:text-primary-400 hover:underline">
                                    {{ config('app.version') }}
                                </a>
                            </flux:text>
                        </div>
                        <flux:button wire:click="checkForUpdates">
                            Check for Updates
                        </flux:button>
                    </div>
                @endif

                <div class="space-y-4">
                    <flux:switch wire:model.live="prereleases" label="Include Pre-Releases"/>
                </div>

                <div class="space-y-4">
                    @if($errorMessage)
                        <flux:callout icon="exclamation-triangle" variant="danger">
                            <flux:callout.heading>Error</flux:callout.heading>
                            <flux:callout.text>
                                {{ $errorMessage }}
                                @if($backoffUntil)
                                    <br><small class="text-xs opacity-75">You can try again after {{ \Carbon\Carbon::createFromTimestamp($backoffUntil)->format('H:i') }}.</small>
                                @endif
                            </flux:callout.text>
                        </flux:callout>
                    @elseif($isUpdateAvailable && $latestVersion)
                        <flux:callout icon="arrow-down-circle" variant="info">
                            <flux:callout.heading>Update Available</flux:callout.heading>
                            <flux:callout.text>
                                A newer version {{ $latestVersion }} is available. Update to the latest version for the best experience.
                            </flux:callout.text>
                            @if($releaseNotes)
                                <div class="mt-4 [&_h2]:text-sm [&_h2]:font-semibold [&_h2]:text-zinc-900 [&_h2]:dark:text-white [&_h2]:mb-2 [&_h2]:mt-4 [&_h2:first-child]:mt-0 [&_p]:text-sm [&_p]:text-zinc-500 [&_p]:dark:text-white/70 [&_p]:mb-2 [&_ul]:text-sm [&_ul]:text-zinc-500 [&_ul]:dark:text-white/70 [&_ul]:list-disc [&_ul]:ml-6 [&_ul]:mb-2 [&_ul>li]:mb-1 [&_li]:text-sm [&_li]:text-zinc-500 [&_li]:dark:text-white/70 [&_a]:text-primary-600 [&_a]:dark:text-primary-400 [&_a]:hover:underline">
                                    {!! $releaseNotes !!}
                                </div>
                            @endif
                            @if($releaseUrl)
                                <div class="mt-4">
                                    <flux:button
                                        href="{{ $releaseUrl }}"
                                        target="_blank"
                                        icon:trailing="arrow-up-right"
                                        class="w-full">
                                        View on GitHub
                                    </flux:button>
                                </div>
                            @endif
                        </flux:callout>
                    @elseif($latestVersion && !$isUpdateAvailable)
                        <flux:callout icon="check-circle" variant="success">
                            <flux:callout.heading>Up to Date</flux:callout.heading>
                            <flux:callout.text>
                                You are running the latest version.
                            </flux:callout.text>
                        </flux:callout>
                    @endif
                </div>
            </div>
        </x-pages::settings.layout>
    </div>
</section>
