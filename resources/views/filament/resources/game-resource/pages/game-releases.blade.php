<x-filament-panels::page>
    @php($status = $this->getStatus())
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Status</x-slot>
            <dl class="space-y-1 text-sm">
                <div><dt class="inline font-medium">Mål:</dt> <dd class="inline">{{ $status['release_targets'] === [] ? 'inga' : implode(', ', $status['release_targets']) }}</dd></div>
                <div><dt class="inline font-medium">Senaste Demo-commit:</dt> <dd class="inline font-mono">{{ $status['demo']['commit_sha'] ?? 'inget lyckat Demo-bygge' }}</dd></div>
                <div><dt class="inline font-medium">Brytare för spelet:</dt> <dd class="inline">{{ $record->releases_enabled ? 'på' : 'av' }}</dd></div>
                <div><dt class="inline font-medium">Global brytare (RELEASES_ENABLED):</dt> <dd class="inline">{{ config('releases.enabled') === true ? 'på' : 'av' }}</dd></div>
                <div><dt class="inline font-medium">Nästa:</dt> <dd class="inline">{{ $status['next'] }}</dd></div>
            </dl>
            <div class="mt-3">
                <x-filament::button color="gray" wire:click="toggleGameSwitch" wire:confirm="Slå om brytaren för det här spelet?">
                    {{ $record->releases_enabled ? 'Stäng av släpp för spelet' : 'Slå på släpp för spelet' }}
                </x-filament::button>
                <x-filament::button color="danger" wire:click="disableAllGames" wire:confirm="Stäng av släpp för ALLA spel? Varje spel slås på igen för sig.">
                    Stäng av släpp för alla spel
                </x-filament::button>
            </div>
        </x-filament::section>

        @if (in_array('app_store', $this->getRecord()->releaseTargets(), true))
            @php($appStore = $this->getAppStore())
            <x-filament::section>
                <x-slot name="heading">App Store</x-slot>
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt>Bundle-id</dt><dd class="font-mono">{{ $appStore['bundle_id'] ?? 'saknas' }}</dd>
                    <dt>Senaste byggnummer</dt><dd class="font-mono">{{ $appStore['ios_build_number'] }}</dd>
                    @foreach ($appStore['ids'] as $key => $value)
                        <dt>{{ $key }}</dt><dd class="font-mono">{{ is_array($value) ? implode(', ', $value) : $value }}</dd>
                    @endforeach
                </dl>
                <x-filament::button class="mt-4" color="gray" wire:click="runAppStoreSetup">Kör Computercats steg</x-filament::button>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Engångssteg</x-slot>
            @forelse ($this->getSteps() as $step)
                <div class="flex items-center justify-between gap-4 border-b border-gray-200 py-2 text-sm dark:border-white/10">
                    <div>
                        <span class="font-mono">{{ $step->target }}/{{ $step->key }}</span>
                        <span class="ml-2">{{ $step->status === 'done' ? '[klart]' : '[öppet]' }}</span>
                        <div>{{ $step->instruction }}</div>
                        @if ($step->last_error)
                            <div class="text-xs text-danger-600">{{ $step->last_error }}</div>
                        @endif
                        @if ($step->status === 'done')
                            <div class="text-xs text-gray-500">{{ $step->done_at?->toDateTimeString() }} av {{ $step->doneBy?->email ?? 'okänd' }}</div>
                        @endif
                    </div>
                    @if ($step->status === 'open' && ! in_array($step->key, \App\Releases\SetupSteps::AUTOMATIC, true))
                        <x-filament::button size="sm" wire:click="markStepDone({{ $step->id }})" wire:confirm="Har steget gjorts?">Bocka av</x-filament::button>
                    @elseif ($step->status === 'open')
                        <span class="text-xs">Sätts av Computercat</span>
                    @endif
                </div>
            @empty
                <p class="text-sm">Inga engångssteg. Sätt mål med set_release_targets.</p>
            @endforelse
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Släpp</x-slot>
            @forelse ($this->getReleases() as $release)
                <div class="flex items-start justify-between gap-4 border-b border-gray-200 py-2 text-sm dark:border-white/10">
                    <div>
                        <div><span class="font-mono">{{ $release->target }}/{{ $release->stage }}</span> <span class="ml-2 font-medium">{{ $release->status }}</span>
                            @if ($release->self_approved) <span class="ml-2">[self_approved]</span> @endif</div>
                        <div class="font-mono text-xs">{{ $release->commit_sha }}</div>
                        <div class="text-xs text-gray-500">{{ $release->created_at->toDateTimeString() }} av {{ $release->requester?->email ?? 'okänd' }}@if ($release->approved_at), godkänt {{ $release->approved_at->toDateTimeString() }} av {{ $release->approver?->email ?? 'okänd' }}@endif</div>
                        @if ($release->last_error)
                            <div class="text-xs">{{ $release->last_error }}</div>
                        @endif
                    </div>
                    @if (in_array($release->status, ['requested', 'awaiting_approval'], true))
                        <div class="flex gap-2">
                            @if ($release->status === 'awaiting_approval')
                                <x-filament::button size="sm" wire:click="approve({{ $release->id }})" wire:confirm="Godkänn commit {{ $release->commit_sha }} till {{ $release->stage }}?">Godkänn</x-filament::button>
                                <x-filament::button size="sm" color="gray" wire:click="reject({{ $release->id }})" wire:confirm="Avslå släppet?">Avslå</x-filament::button>
                            @endif
                            <x-filament::button size="sm" color="danger" wire:click="cancel({{ $release->id }})" wire:confirm="Avbryt släppet?">Avbryt</x-filament::button>
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-sm">Inga släpp ännu.</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
