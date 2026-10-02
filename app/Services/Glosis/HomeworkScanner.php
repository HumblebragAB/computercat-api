<?php

namespace App\Services\Glosis;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * Läser av en fotograferad glosläxa med Claude.
 *
 * Officiella SDK:n anthropic-ai/sdk. Strukturerat svar via
 * output_config.format (json_schema), effort low (enkel avläsning), och
 * server-side fallback ("default", beta server-side-fallback-2026-07-01) så att
 * en vägran i en kategori som har reserv prövas på reservmodellen. stop_reason
 * kontrolleras innan innehållet läses. Claudes svar valideras här innan det
 * går vidare till appen.
 *
 * Bilden finns bara i minnet under anropet och loggas aldrig.
 */
class HomeworkScanner
{
    public const MODEL = 'claude-sonnet-5-5';

    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public const MAX_TOKENS = 4000;

    public const MAX_WORDS = 60;

    public const MAX_FIELD_LENGTH = 80;

    public const MAX_TITLE_LENGTH = 60;

    public const DEFAULT_TITLE = 'Ny lista';

    /**
     * Ordagrant från Glosis-prototypens readPhoto (docs/handoff/prototyper/
     * glosis-app-prototyp.html) och SCAN_PROMPT i packages/core/src/input/
     * scanResult.ts. Servern äger prompten från och med nu.
     */
    public const PROMPT = <<<'PROMPT'
Bilden visar en glosläxa (svenska och engelska ord) för ett barn i årskurs 3–5 i Sverige. Den kan vara tryckt eller handskriven.
Plocka ut alla glospar. Rätta uppenbara stavfel (även tryckfel på bladet, t.ex. "undelat" → "undulat") men ändra inte vilka ord som ska övas.
Om ett ord har flera översättningar (t.ex. "Farmor,mormor"), behåll alla i samma fält separerade med ", ". Skriv orden med gemener om de inte är namn.
Ignorera all annan text på bladet, som webbadresser, mejladresser och lösenord.
Om ett ord bara står på ett av språken: fyll i den vanligaste översättningen och sätt "guessed": true.
Om det finns en rubrik eller vecka på bladet, använd den som title, annars "".
Svara endast med JSON i exakt detta format:
{"title":"Vecka 40","words":[{"sv":"hund","en":"dog","guessed":false}]}
Om bilden inte innehåller några glosor, svara {"title":"","words":[]}.
PROMPT;

    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'words' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'sv' => ['type' => 'string'],
                        'en' => ['type' => 'string'],
                        'guessed' => ['type' => 'boolean'],
                    ],
                    'required' => ['sv', 'en', 'guessed'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['title', 'words'],
        'additionalProperties' => false,
    ];

    /**
     * @param  ClientInterface|null  $transporter  PSR-18-klient för SDK:n. Tester
     *                                             skickar in en fejk; i drift en Guzzle med timeout (SDK:ns egen
     *                                             timeout är bara rådgivande).
     */
    public function __construct(private readonly ?ClientInterface $transporter = null) {}

    /**
     * @throws ScanFailedException
     */
    public function scan(string $apiKey, string $imageBytes, string $mediaType): ScanResult
    {
        $client = new Client(
            apiKey: $apiKey,
            requestOptions: [
                'transporter' => $this->transporter ?? new GuzzleClient(['timeout' => 90, 'connect_timeout' => 10]),
                'maxRetries' => 1,
            ],
        );

        try {
            $message = $client->beta->messages->create(
                maxTokens: self::MAX_TOKENS,
                messages: [[
                    'role' => 'user',
                    'content' => [
                        // Bilden före texten, enligt Anthropics råd för vision.
                        ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mediaType, 'data' => base64_encode($imageBytes)]],
                        ['type' => 'text', 'text' => self::PROMPT],
                    ],
                ]],
                model: self::MODEL,
                fallbacks: 'default',
                outputConfig: [
                    'effort' => 'low',
                    'format' => ['type' => 'json_schema', 'schema' => self::SCHEMA],
                ],
                betas: [self::FALLBACK_BETA],
            );
        } catch (Throwable $e) {
            // Undantagets meddelande innehåller aldrig request-kroppen (bilden).
            throw new ScanFailedException('Claude-anropet misslyckades: '.$e::class.': '.mb_substr($e->getMessage(), 0, 500), previous: $e);
        }

        try {
            return $this->parse($message);
        } catch (ScanFailedException $e) {
            // Svaret debiterades även om det inte gick att använda.
            throw $e->withUsage($message->model, $message->usage->inputTokens, $message->usage->outputTokens);
        }
    }

    private function parse(BetaMessage $message): ScanResult
    {
        if ($message->stopReason === 'refusal') {
            $category = $message->stopDetails?->category ?? 'okänd';
            throw new ScanFailedException("Claude vägrade (kategori: {$category})");
        }
        if ($message->stopReason !== 'end_turn') {
            throw new ScanFailedException('Oväntad stop_reason: '.($message->stopReason ?? 'null'));
        }

        $text = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text = $block->text;
                break;
            }
        }
        if ($text === null) {
            throw new ScanFailedException('Svaret saknar textblock');
        }

        $data = json_decode($text, true);
        if (! is_array($data)) {
            throw new ScanFailedException('Svaret är inte JSON');
        }

        [$title, $words] = self::validate($data);

        return new ScanResult(
            title: $title,
            words: $words,
            model: $message->model,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
        );
    }

    /**
     * Servern litar inte på att schemat hölls. Ord där svenska eller engelska
     * saknas efter trim tas bort (appen visar "inga glosor" om inget blir kvar).
     * För många ord eller för långa fält tyder på ett felläst blad: hela
     * avläsningen underkänns hellre än att barnet får skräp att öva på.
     *
     * @param  array<mixed>  $data
     * @return array{0: string, 1: list<array{sv: string, en: string, guessed: bool}>}
     *
     * @throws ScanFailedException
     */
    public static function validate(array $data): array
    {
        if (array_is_list($data) && $data !== []) {
            throw new ScanFailedException('Svaret är inte ett objekt');
        }
        if (! is_string($data['title'] ?? null)) {
            throw new ScanFailedException('title saknas eller har fel typ');
        }
        if (! is_array($data['words'] ?? null) || ! array_is_list($data['words'])) {
            throw new ScanFailedException('words saknas eller är ingen lista');
        }
        if (count($data['words']) > self::MAX_WORDS) {
            throw new ScanFailedException('För många ord: '.count($data['words']));
        }

        $words = [];
        foreach ($data['words'] as $i => $word) {
            if (! is_array($word) || ! is_string($word['sv'] ?? null) || ! is_string($word['en'] ?? null) || ! is_bool($word['guessed'] ?? null)) {
                throw new ScanFailedException("words[{$i}] har fel form");
            }
            $sv = trim($word['sv']);
            $en = trim($word['en']);
            if (mb_strlen($sv) > self::MAX_FIELD_LENGTH || mb_strlen($en) > self::MAX_FIELD_LENGTH) {
                throw new ScanFailedException("words[{$i}] är för långt");
            }
            if ($sv === '' || $en === '') {
                continue;
            }
            $words[] = ['sv' => $sv, 'en' => $en, 'guessed' => $word['guessed']];
        }

        $title = trim($data['title']);
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            // En lång rubrik är ingen felläsning; den kortas och kan redigeras i appen.
            $title = rtrim(mb_substr($title, 0, self::MAX_TITLE_LENGTH));
        }
        if ($title === '') {
            $title = self::DEFAULT_TITLE;
        }

        return [$title, $words];
    }
}
