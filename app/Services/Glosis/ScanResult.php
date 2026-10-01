<?php

namespace App\Services\Glosis;

final readonly class ScanResult
{
    /**
     * @param  list<array{sv: string, en: string, guessed: bool}>  $words
     */
    public function __construct(
        public string $title,
        public array $words,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
    ) {}

    /** @return array{title: string, words: list<array{sv: string, en: string, guessed: bool}>} */
    public function toResponse(): array
    {
        return ['title' => $this->title, 'words' => $this->words];
    }
}
