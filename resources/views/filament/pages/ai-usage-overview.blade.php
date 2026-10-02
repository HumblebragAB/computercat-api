@php
    $report = $this->getReport();
    $labels = ['scan' => 'Fota läxan', 'tts' => 'Studioröst'];
    $usd = fn (int $micro) => \App\Filament\Pages\AiUsageOverview::usd($micro);
@endphp
<x-filament-panels::page>
    @if ($report === null)
        <x-filament::section>Spelet glosis finns inte.</x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Den här månaden</x-slot>
            <x-slot name="description">Uppskattad kostnad mot månadsbudgeten (Europe/Stockholm). Budgeten ändras under Games → Glosis.</x-slot>
            <p class="text-lg font-semibold" data-test="month-total">
                {{ $usd($report['month_total_micro_usd']) }} av {{ $usd($report['budget_micro_usd']) }}
            </p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Vecka och månad</x-slot>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th class="py-1">Funktion</th>
                        <th class="py-1">Anrop, vecka</th>
                        <th class="py-1">Kostnad, vecka</th>
                        <th class="py-1">Anrop, månad</th>
                        <th class="py-1">Kostnad, månad</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($labels as $feature => $label)
                        <tr data-test="period-{{ $feature }}">
                            <td class="py-1">{{ $label }}</td>
                            <td class="py-1">{{ $report['week'][$feature]['count'] }}</td>
                            <td class="py-1">{{ $usd($report['week'][$feature]['micro_usd']) }}</td>
                            <td class="py-1">{{ $report['month'][$feature]['count'] }}</td>
                            <td class="py-1">{{ $usd($report['month'][$feature]['micro_usd']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Senaste 14 dagarna</x-slot>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th class="py-1">Dag</th>
                        @foreach ($labels as $label)
                            <th class="py-1">{{ $label }}, anrop</th>
                            <th class="py-1">{{ $label }}, kostnad</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['days'] as $day)
                        <tr>
                            <td class="py-1">{{ $day['date'] }}</td>
                            @foreach ($labels as $feature => $label)
                                <td class="py-1">{{ $day['features'][$feature]['count'] }}</td>
                                <td class="py-1">{{ $usd($day['features'][$feature]['micro_usd']) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
