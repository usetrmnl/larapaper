<div>
    @if ($isFirstUser)
        <div x-data="autoJoinCountdown">
            <flux:toggle
                wire:model.live="deviceAutojoin"
                size="sm"
                off:icon="link-slash"
                off:label="Auto-Join Disabled"
                on:icon="link"
                tooltip="Permit devices to join, or to re-run setup after a factory reset, for {{ \App\Models\User::AUTO_JOIN_WINDOW_MINUTES }} minutes"
            >
                Auto-Join Permitted
                <flux:badge
                    size="sm"
                    inset="top bottom"
                    class="ms-1 tabular-nums"
                    x-show="remaining"
                    x-text="remaining"
                />
            </flux:toggle>
        </div>
    @endif

    @script
        <script>
            Alpine.data('autoJoinCountdown', () => ({
                remaining: '',
                timer: null,

                init() {
                    this.start();
                    this.$wire.$watch('autoJoinExpiresAt', () => this.start());
                },

                destroy() {
                    clearInterval(this.timer);
                },

                start() {
                    clearInterval(this.timer);
                    this.remaining = '';

                    const { autoJoinExpiresAt, serverNow } = this.$wire;

                    if (!autoJoinExpiresAt) {
                        return;
                    }

                    const deadline = Date.now() + (autoJoinExpiresAt - serverNow) * 1000;

                    const tick = () => {
                        const seconds = Math.ceil((deadline - Date.now()) / 1000);

                        if (seconds <= 0) {
                            clearInterval(this.timer);
                            this.remaining = '';
                            this.$wire.syncState();

                            return;
                        }

                        const minutes = String(Math.floor(seconds / 60)).padStart(2, '0');
                        this.remaining = `${minutes}:${String(seconds % 60).padStart(2, '0')}`;
                    };

                    tick();
                    this.timer = setInterval(tick, 1000);
                },
            }));
        </script>
    @endscript
</div>
