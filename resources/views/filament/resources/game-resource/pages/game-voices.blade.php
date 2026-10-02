@php
    $voices = $this->getVoices();
    $languages = \App\Services\Glosis\GlosisSettings::LANGUAGES;
    $genders = \App\Services\Glosis\GlosisSettings::GENDERS;
    $speedLabels = ['normal' => 'normal takt', 'slow' => 'långsamt'];
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Aktiva röster per språk</x-slot>
        <x-slot name="description">Varje språk har en kvinnlig och en manlig röst. Studiorösten i Glosis använder rösterna som är valda här, utan ny appversion. Saknas den röst appen ber om används språkets andra röst. Byts en röst genereras orden på nytt med den nya rösten.</x-slot>

        <table class="w-full text-sm">
            <thead>
                <tr class="text-left">
                    <th class="py-2">Språk</th>
                    <th class="py-2"></th>
                    <th class="py-2">Röst</th>
                    <th class="py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($languages as $code => $label)
                    @foreach ($genders as $gender => $genderLabel)
                        @php
                            $voice = $voices[$code][$gender] ?? null;
                            $previewKey = $this->previewKey($code, $gender);
                        @endphp
                        <tr class="{{ $loop->first ? 'border-t border-gray-200 dark:border-white/10' : '' }} align-top" data-test="voice-row-{{ $code }}-{{ $gender }}">
                            @if ($loop->first)
                                <td class="py-3 font-medium" rowspan="{{ count($genders) }}">{{ $label }}</td>
                            @endif
                            <td class="py-3 pr-3 whitespace-nowrap text-gray-600 dark:text-gray-300">{{ $genderLabel }}</td>
                            <td class="py-3">
                                @if ($voice === null)
                                    <span class="text-gray-500">Ingen röst vald.</span>
                                @else
                                    <div>{{ $voice['name'] ?? 'Namn okänt' }}</div>
                                    <div class="font-mono text-xs text-gray-500">{{ $voice['voice_id'] }}</div>
                                    @if ($voice['source'] === 'legacy')
                                        <div class="text-xs text-gray-500">Från det äldre fältet ElevenLabs voice ID under Games → Glosis.</div>
                                    @endif
                                    @if (($voice['category'] ?? null) === \App\Services\Glosis\ElevenLabsVoiceLibrary::EXPIRING_CATEGORY)
                                        <div class="text-xs font-semibold text-danger-600">Default-röst: upphör 31 dec 2026. Välj en annan röst.</div>
                                    @endif
                                @endif

                                @if (! empty($this->previews[$previewKey]))
                                    <div class="mt-3 grid gap-2 sm:grid-cols-2" data-test="previews-{{ $previewKey }}">
                                        @foreach ($this->previews[$previewKey] as $clip)
                                            <div>
                                                <div class="text-xs text-gray-500">{{ $clip['word'] }}, {{ $speedLabels[$clip['speed']] ?? $clip['speed'] }}</div>
                                                <audio controls preload="none" src="{{ $clip['url'] }}" class="w-full"></audio>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="py-3 text-right whitespace-nowrap space-x-2">
                                <x-filament::button size="sm" wire:click="startChoosing('{{ $code }}', '{{ $gender }}')">Välj röst</x-filament::button>
                                @if ($voice !== null)
                                    <x-filament::button size="sm" color="gray" wire:click="previewVoice('{{ $code }}', '{{ $gender }}')" wire:loading.attr="disabled" wire:target="previewVoice('{{ $code }}', '{{ $gender }}')">
                                        Provlyssna i Glosis
                                    </x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @if ($voices[$code]['female'] === null && $voices[$code]['male'] === null)
                        <tr data-test="voice-none-{{ $code }}">
                            <td></td>
                            <td colspan="3" class="pb-3 text-xs text-gray-500">Appen använder telefonens röst för {{ mb_strtolower($label) }}.</td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
        <p class="mt-3 text-xs text-gray-500">Provlyssna i Glosis genererar "grandmother" och "parrot" i normal och långsam takt med just den rösten. Det kostar som vanliga ord, räknas under AI-användning och sparas så att appen kan använda ljudet direkt.</p>
    </x-filament::section>

    @if ($activeLanguage !== null && $activeGender !== null)
        <x-filament::section>
            <x-slot name="heading">Välj {{ mb_strtolower($this->slotLabel($activeLanguage, $activeGender)) }}</x-slot>
            <x-slot name="description">Provlyssningen i listan är ElevenLabs gratisprov och kostar inget. Default-röster visas inte i röstbiblioteket; de upphör 31 dec 2026.</x-slot>

            <form wire:submit="searchVoices" class="grid gap-3 sm:grid-cols-3">
                <label class="text-sm">
                    <span class="block mb-1">Var</span>
                    <select wire:model="source" class="w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
                        <option value="library">Röstbiblioteket</option>
                        <option value="mine">Mina röster</option>
                    </select>
                </label>
                <label class="text-sm sm:col-span-2">
                    <span class="block mb-1">Sök</span>
                    <input type="text" wire:model="search" maxlength="100" placeholder="t.ex. teacher, calm, warm" class="w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
                </label>
                <label class="text-sm">
                    <span class="block mb-1">Språk</span>
                    <input type="text" wire:model="filterLanguage" maxlength="10" placeholder="en" class="w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
                </label>
                <label class="text-sm">
                    <span class="block mb-1">Accent</span>
                    <input type="text" wire:model="accent" maxlength="50" placeholder="t.ex. british" class="w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
                </label>
                <label class="text-sm">
                    <span class="block mb-1">Kön</span>
                    <input type="text" wire:model="gender" maxlength="20" placeholder="t.ex. female, male" class="w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
                </label>
                <label class="text-sm">
                    <span class="block mb-1">Ålder</span>
                    <input type="text" wire:model="age" maxlength="20" placeholder="t.ex. young" class="w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
                </label>
                <p class="text-xs text-gray-500 sm:col-span-3">Kön är förifyllt efter rösten som väljs och går att ändra. Språk, accent, kön och ålder filtrerar bara i röstbiblioteket och skickas som de skrivs till ElevenLabs (engelska värden, som i biblioteket). Mina röster söks på namn och beskrivning.</p>
                <div class="sm:col-span-3 flex gap-2">
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="searchVoices">Sök</x-filament::button>
                    <x-filament::button color="gray" wire:click="cancelChoosing">Avbryt</x-filament::button>
                </div>
            </form>

            @if ($searched && $results === [])
                <p class="mt-4 text-sm text-gray-500">Inga röster matchade.</p>
            @endif

            @if ($results !== [])
                <div class="mt-4 divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($results as $i => $result)
                        <div class="py-3 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between" data-test="result-{{ $i }}">
                            <div class="min-w-0">
                                <div class="font-medium">{{ $result['name'] }}</div>
                                <div class="text-xs text-gray-500">
                                    {{ collect([$result['accent'], $result['gender'], $result['age'], $result['language']])->filter()->implode(' · ') }}
                                    <span class="font-mono">{{ $result['voice_id'] }}</span>
                                </div>
                                @if ($result['description'])
                                    <p class="text-sm mt-1">{{ $result['description'] }}</p>
                                @endif
                                @if ($result['expiring'])
                                    <p class="text-xs font-semibold text-danger-600 mt-1">Default-röst: upphör 31 dec 2026</p>
                                @endif
                                @if ($result['preview_url'])
                                    <audio controls preload="none" src="{{ $result['preview_url'] }}" class="mt-2 w-full max-w-sm"></audio>
                                @endif
                            </div>
                            <div class="shrink-0">
                                @if ($result['expiring'])
                                    <x-filament::button size="sm" color="gray" disabled>Kan inte väljas</x-filament::button>
                                @else
                                    <x-filament::button size="sm" wire:click="selectVoice({{ $i }})" wire:loading.attr="disabled" wire:target="selectVoice">Välj</x-filament::button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
