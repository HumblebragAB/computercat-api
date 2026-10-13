<div>
    <link rel="stylesheet" href="{{ \App\Support\ThemeAsset::url('css/cc-login.css') }}">
    <script src="{{ \App\Support\ThemeAsset::url('js/cc-login.js') }}" defer></script>

    <div class="cc-login-wrapper">
        <div class="cc-login-card" id="login-card">
            {{-- Katten flyttas av cc-login.js mellan banorna och till body. wire:ignore
                 hindrar Livewire från att morpha om den vid t.ex. misslyckad inloggning. --}}
            <div class="cc-cat-track" id="cat-track" wire:ignore>
                <div class="cc-cat" id="the-cat">
                    <div class="cc-cat-body"></div>
                    <div class="cc-cat-legs"></div>
                    <div class="cc-cat-blink"></div>
                    <div class="cc-cat-zzz" id="cat-zzz"></div>
                </div>
                <div class="cc-fly" id="the-fly"></div>
            </div>

            <div class="cc-login-header">
                <div class="cc-logo-text">COMPUTER<span class="cc-logo-accent">CAT</span></div>
                <div class="cc-subtitle">// admin_panel</div>
            </div>

            <div class="cc-form-area">
                <x-filament-panels::form wire:submit="authenticate">
                    {{ $this->form }}

                    <x-filament-panels::form.actions
                        :actions="$this->getCachedFormActions()"
                        :full-width="$this->hasFullWidthFormActions()"
                    />
                </x-filament-panels::form>
            </div>

            <div class="cc-login-footer">
                <span class="cc-blink-cursor">&gt;</span> ready_
            </div>

            <div class="cc-cat-track cc-cat-track-bottom" id="cat-track-bottom" wire:ignore></div>
        </div>
    </div>
</div>
