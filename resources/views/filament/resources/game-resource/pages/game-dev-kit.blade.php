<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Feature toggles --}}
        <x-filament::section>
            <x-slot name="heading">Features</x-slot>
            <x-slot name="description">Select which features to include in the generated manual.</x-slot>
            {{ $this->form }}
        </x-filament::section>

        {{-- Setup Guide --}}
        <x-filament::section>
            <x-slot name="heading">Setup Guide</x-slot>
            <x-slot name="description">Overview of {{ $record->name }}'s current configuration.</x-slot>
            <div class="prose prose-sm dark:prose-invert max-w-none cc-markdown">
                {!! \Illuminate\Support\Str::markdown($this->getSetupGuide(), ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
            </div>
        </x-filament::section>

        {{-- AI Agent Manual --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center justify-between w-full">
                    <span>AI Agent Manual</span>
                    <div class="flex gap-2">
                        <button type="button" onclick="downloadManual()" class="cc-copy-btn">
                            <span>Download .md</span>
                        </button>
                        <button type="button" onclick="copyManual()" class="cc-copy-btn">
                            <span id="copy-label">Copy to clipboard</span>
                        </button>
                    </div>
                </div>
            </x-slot>
            <x-slot name="description">Give this to your AI coding assistant. It contains everything needed to integrate with the API. For testing, the assistant creates an anonymous user the same way the game does.</x-slot>

            {{-- Rendered preview --}}
            <div class="prose prose-sm dark:prose-invert max-w-none cc-markdown">
                {!! \Illuminate\Support\Str::markdown($this->getManual(), ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
            </div>

            {{-- Hidden raw markdown for copying --}}
            <textarea id="manual-raw" class="sr-only" aria-hidden="true">{{ $this->getManual() }}</textarea>
        </x-filament::section>
    </div>

    <style>
        .cc-copy-btn {
            font-family: var(--cc-font);
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.35rem 1rem;
            background: var(--cc-paper);
            border: 2px solid var(--cc-ink);
            border-radius: 8px;
            color: var(--cc-ink);
            cursor: pointer;
        }
        .cc-copy-btn:hover {
            background: var(--cc-ink);
            color: var(--cc-paper);
        }
        .cc-copy-btn:focus-visible { outline: 2px dashed var(--cc-ink); outline-offset: 4px; }
        .cc-copy-btn.copied {
            text-decoration: underline;
        }
        .cc-markdown code {
            font-family: var(--cc-font) !important;
            font-size: 0.8rem !important;
            background: var(--cc-desk) !important;
            border: 1px solid var(--cc-line) !important;
            padding: 0.1rem 0.3rem !important;
            border-radius: 0 !important;
        }
        /* prose ger pre-text ljusgrå (#e5e7eb) i ljust läge, vilket blir oläsligt på desk. */
        .cc-markdown pre {
            background: var(--cc-desk) !important;
            color: var(--cc-ink) !important;
            border: 1px solid var(--cc-line) !important;
            border-radius: 0 !important;
        }
        .cc-markdown pre code {
            border: none !important;
            background: transparent !important;
            padding: 0 !important;
        }
        .cc-markdown table {
            font-size: 0.8rem;
        }
        .cc-markdown th {
            font-family: var(--cc-font);
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.5px;
            color: var(--cc-muted);
        }
    </style>

    <script>
        function copyManual() {
            const raw = document.getElementById('manual-raw').value;
            navigator.clipboard.writeText(raw).then(() => {
                const btn = document.querySelector('#copy-label').closest('.cc-copy-btn');
                const label = document.getElementById('copy-label');
                btn.classList.add('copied');
                label.textContent = 'Copied!';
                setTimeout(() => {
                    btn.classList.remove('copied');
                    label.textContent = 'Copy to clipboard';
                }, 2000);
            });
        }

        function downloadManual() {
            const raw = document.getElementById('manual-raw').value;
            const slug = @js($record->slug);
            const blob = new Blob([raw], { type: 'text/markdown' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = slug + '-integration-manual.md';
            a.click();
            URL.revokeObjectURL(url);
        }
    </script>
</x-filament-panels::page>
