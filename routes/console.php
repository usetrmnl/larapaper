<?php

use App\Jobs\CleanupDeviceLogsJob;
use App\Jobs\FetchDeviceModelsJob;
use App\Jobs\FetchProxyCloudResponses;
use App\Jobs\FirmwarePollJob;
use App\Jobs\NotifyDeviceBatteryLowJob;
use App\Jobs\PrerenderUpcomingPlaylistItemsJob;
use Illuminate\Support\Facades\Schedule;
use OffloadProject\Toggle\Facades\Toggle;

Schedule::job(FetchProxyCloudResponses::class, [])->cron(
    config('services.trmnl.proxy_refresh_cron') ? config('services.trmnl.proxy_refresh_cron') :
        sprintf('*/%s * * * *', (int) (config('services.trmnl.proxy_refresh_minutes', 15)))
);

Schedule::job(FirmwarePollJob::class)->daily();
Schedule::job(CleanupDeviceLogsJob::class)->daily();
Schedule::job(FetchDeviceModelsJob::class)->weekly();
Schedule::job(NotifyDeviceBatteryLowJob::class)->dailyAt('10:00');
Schedule::job(PrerenderUpcomingPlaylistItemsJob::class)
    ->everyMinute()
    ->when(fn (): bool => Toggle::active(PrerenderUpcomingPlaylistItemsJob::TOGGLE));
