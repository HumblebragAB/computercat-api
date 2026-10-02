<?php

namespace App\Services\Glosis;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ElevenLabs röster för sidan Röster i Filament. Dokumentation läst 2026-10-02:
 *
 * List shared voices (röstbiblioteket):
 * https://elevenlabs.io/docs/api-reference/voices/voice-library/get-shared
 *   GET https://api.elevenlabs.io/v1/shared-voices
 *   query: page_size (max 100), search, language, accent, gender, age, ...
 *   200 {voices: [LibraryVoiceResponseModel], has_more, total_count}
 *   LibraryVoiceResponseModel: public_owner_id, voice_id, name, accent, gender,
 *   age, descriptive, use_case, category, language, description, preview_url,
 *   is_added_by_user, ...
 *
 * List voices (kontots röster, "My Voices"):
 * https://elevenlabs.io/docs/api-reference/voices/search
 *   GET https://api.elevenlabs.io/v2/voices
 *   query: page_size (max 100), search, voice_ids, include_total_count, ...
 *   200 {voices: [VoiceResponseModel], has_more, total_count, next_page_token}
 *   VoiceResponseModel: voice_id, name, category, labels, description,
 *   preview_url, is_legacy, ...
 *
 * Add shared voice:
 * https://elevenlabs.io/docs/api-reference/voices/voice-library/share
 *   POST https://api.elevenlabs.io/v1/voices/add/{public_user_id}/{voice_id}
 *   JSON {new_name}
 *   200 {voice_id}
 *
 * Autentisering med huvudet xi-api-key
 * (https://elevenlabs.io/docs/api-reference/authentication). Fel har formen
 * {"detail": {type, code, message, status, request_id}}
 * (https://elevenlabs.io/docs/eleven-api/resources/errors). En nyckel som saknar
 * en behörighet ger 401 med status "missing_permissions" och ett meddelande som
 * namnger behörigheten (sett från ElevenLabs 2026-10-02: "The API key you used
 * is missing the permission voices_read to execute this operation.").
 *
 * Default-röster (category "premade") upphör 31 december 2026:
 * https://elevenlabs.io/docs/help-center/product/voices/my-voices/what-are-default-voices
 * Legacy-röster (is_legacy) är redan borttagna:
 * https://elevenlabs.io/docs/help-center/product/voices/my-voices/what-are-legacy-voices
 */
class ElevenLabsVoiceLibrary
{
    public const BASE = 'https://api.elevenlabs.io';

    public const PAGE_SIZE = 30;

    /** Kategorin för ElevenLabs Default-röster, som upphör 31 december 2026. */
    public const EXPIRING_CATEGORY = 'premade';

    private const LOG_BYTES = 4096;

    /**
     * Röstbiblioteket. Default-röster (premade) och legacy-röster tas bort ur
     * träffarna; de går inte att välja.
     *
     * @param  array{search?: string|null, language?: string|null, accent?: string|null, gender?: string|null, age?: string|null}  $filters
     * @return list<array<string, mixed>>
     *
     * @throws VoiceLibraryException
     */
    public function searchShared(string $apiKey, array $filters): array
    {
        $query = ['page_size' => self::PAGE_SIZE];
        foreach (['search', 'language', 'accent', 'gender', 'age'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value !== '') {
                $query[$field] = mb_substr($value, 0, 100);
            }
        }

        $json = $this->get($apiKey, '/v1/shared-voices', $query);
        $voices = [];
        foreach ($this->list($json, 'voices') as $voice) {
            if (! is_array($voice) || ! GlosisSettings::validVoiceId($voice['voice_id'] ?? null)) {
                continue;
            }
            if (($voice['category'] ?? null) === self::EXPIRING_CATEGORY || ($voice['is_legacy'] ?? false) === true) {
                continue;
            }
            $voices[] = [
                'source' => 'library',
                'voice_id' => $voice['voice_id'],
                'public_owner_id' => self::string($voice['public_owner_id'] ?? null),
                'name' => self::string($voice['name'] ?? null) ?? $voice['voice_id'],
                'accent' => self::string($voice['accent'] ?? null),
                'gender' => self::string($voice['gender'] ?? null),
                'age' => self::string($voice['age'] ?? null),
                'language' => self::string($voice['language'] ?? null),
                'category' => self::string($voice['category'] ?? null),
                'description' => self::string($voice['description'] ?? null) ?? self::string($voice['descriptive'] ?? null),
                'preview_url' => self::httpsUrl($voice['preview_url'] ?? null),
                'is_added_by_user' => ($voice['is_added_by_user'] ?? false) === true,
                'expiring' => false,
            ];
        }

        return $voices;
    }

    /**
     * Kontots egna röster. Default-röster (premade) är med men markerade som
     * expiring, så att det syns att de upphör.
     *
     * @return list<array<string, mixed>>
     *
     * @throws VoiceLibraryException
     */
    public function myVoices(string $apiKey, ?string $search = null): array
    {
        $query = ['page_size' => 100, 'include_total_count' => 'false'];
        if (($search = trim((string) $search)) !== '') {
            $query['search'] = mb_substr($search, 0, 100);
        }

        return array_map(fn (array $voice) => self::ownVoice($voice), $this->ownVoices($apiKey, $query));
    }

    /** Kontots röst med detta id, eller null. @throws VoiceLibraryException */
    public function findOwnVoice(string $apiKey, string $voiceId): ?array
    {
        foreach ($this->ownVoices($apiKey, ['voice_ids' => $voiceId, 'include_total_count' => 'false']) as $voice) {
            if ($voice['voice_id'] === $voiceId) {
                return self::ownVoice($voice);
            }
        }

        return null;
    }

    /**
     * Lägger till en röst ur biblioteket i kontot och returnerar röst-id:t som
     * ElevenLabs svarar med (det som ska användas för text-to-speech).
     *
     * @throws VoiceLibraryException
     */
    public function addShared(string $apiKey, string $publicOwnerId, string $voiceId, string $name): string
    {
        if (preg_match('/^[A-Za-z0-9]{1,128}\z/', $publicOwnerId) !== 1 || ! GlosisSettings::validVoiceId($voiceId)) {
            throw new VoiceLibraryException('Rösten har ett ogiltigt id och kan inte läggas till.');
        }
        $path = '/v1/voices/add/'.rawurlencode($publicOwnerId).'/'.rawurlencode($voiceId);
        $body = ['new_name' => mb_substr($name, 0, 100)];

        try {
            $response = Http::withHeaders(['xi-api-key' => $apiKey, 'Accept' => 'application/json'])
                ->connectTimeout(5)
                ->timeout(20)
                ->post(self::BASE.$path, $body);
        } catch (ConnectionException $e) {
            throw new VoiceLibraryException('ElevenLabs gick inte att nå. Försök igen om en stund.', previous: $e);
        }
        $this->guard($response, 'POST', $path, $body);

        $newId = $response->json('voice_id');
        if (! GlosisSettings::validVoiceId($newId)) {
            $this->logFailure('POST', $path, $body, $response);

            throw new VoiceLibraryException('ElevenLabs svarade utan giltigt voice_id när rösten lades till.');
        }

        return $newId;
    }

    /**
     * Felmeddelande på svenska för ett felsvar från ElevenLabs, och behörigheten
     * som saknas om det var felet.
     *
     * @return array{message: string, permission: string|null}
     */
    public static function describeError(int $status, string $body): array
    {
        $json = json_decode($body, true);
        $detail = is_array($json) ? ($json['detail'] ?? null) : null;
        $message = is_array($detail) ? (string) ($detail['message'] ?? '') : (is_string($detail) ? $detail : '');
        $code = is_array($detail) ? (string) ($detail['status'] ?? $detail['code'] ?? '') : '';

        if (preg_match('/missing the permission ([a-z0-9_]+)/i', $message, $m) === 1 || $code === 'missing_permissions') {
            $permission = $m[1] ?? null;

            return [
                'message' => $permission !== null
                    ? "ElevenLabs-nyckeln saknar behörigheten {$permission}. Lägg till den på nyckeln under elevenlabs.io → API Keys och försök igen."
                    : 'ElevenLabs-nyckeln saknar en behörighet för det här: '.mb_substr($message, 0, 200),
                'permission' => $permission,
            ];
        }
        if ($status === 401) {
            return ['message' => 'ElevenLabs godtog inte nyckeln (401). Kontrollera ElevenLabs-nyckeln under Games → Glosis.', 'permission' => null];
        }
        $suffix = $message !== '' ? ': '.mb_substr($message, 0, 200) : '';

        return ['message' => "ElevenLabs svarade {$status}{$suffix}", 'permission' => null];
    }

    /** @return list<array<string, mixed>> rå VoiceResponseModel med giltigt voice_id */
    private function ownVoices(string $apiKey, array $query): array
    {
        $json = $this->get($apiKey, '/v2/voices', $query);

        return array_values(array_filter(
            $this->list($json, 'voices'),
            fn ($voice) => is_array($voice) && GlosisSettings::validVoiceId($voice['voice_id'] ?? null),
        ));
    }

    private static function ownVoice(array $voice): array
    {
        $labels = is_array($voice['labels'] ?? null) ? $voice['labels'] : [];
        $category = self::string($voice['category'] ?? null);

        return [
            'source' => 'mine',
            'voice_id' => $voice['voice_id'],
            'public_owner_id' => null,
            'name' => self::string($voice['name'] ?? null) ?? $voice['voice_id'],
            'accent' => self::string($labels['accent'] ?? null),
            'gender' => self::string($labels['gender'] ?? null),
            'age' => self::string($labels['age'] ?? null),
            'language' => self::string($labels['language'] ?? null),
            'category' => $category,
            'description' => self::string($voice['description'] ?? null) ?? self::string($labels['descriptive'] ?? null),
            'preview_url' => self::httpsUrl($voice['preview_url'] ?? null),
            'is_added_by_user' => true,
            'expiring' => $category === self::EXPIRING_CATEGORY || ($voice['is_legacy'] ?? false) === true,
        ];
    }

    /** @throws VoiceLibraryException */
    private function get(string $apiKey, string $path, array $query): array
    {
        try {
            $response = Http::withHeaders(['xi-api-key' => $apiKey, 'Accept' => 'application/json'])
                ->connectTimeout(5)
                ->timeout(15)
                ->get(self::BASE.$path, $query);
        } catch (ConnectionException $e) {
            throw new VoiceLibraryException('ElevenLabs gick inte att nå. Försök igen om en stund.', previous: $e);
        }
        $this->guard($response, 'GET', $path, $query);

        $json = $response->json();
        if (! is_array($json) || ! is_array($json['voices'] ?? null)) {
            $this->logFailure('GET', $path, $query, $response);

            throw new VoiceLibraryException('ElevenLabs svar saknar listan voices.');
        }

        return $json;
    }

    /** @throws VoiceLibraryException */
    private function guard(Response $response, string $method, string $path, array $request): void
    {
        if ($response->successful()) {
            return;
        }
        $this->logFailure($method, $path, $request, $response);
        $error = self::describeError($response->status(), $response->body());

        throw new VoiceLibraryException($error['message'], $response->status(), $error['permission']);
    }

    private function logFailure(string $method, string $path, array $request, Response $response): void
    {
        // Nyckeln ligger i ett huvud och loggas aldrig.
        Log::warning('Glosis röster: ElevenLabs svarade med fel', [
            'method' => $method,
            'path' => $path,
            'request' => $request,
            'status' => $response->status(),
            'response' => mb_strcut($response->body(), 0, self::LOG_BYTES),
        ]);
    }

    /** @return list<mixed> */
    private function list(array $json, string $key): array
    {
        return array_values(is_array($json[$key] ?? null) ? $json[$key] : []);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 500) : null;
    }

    /** Bara https-adresser når <audio src> på sidan. */
    private static function httpsUrl(mixed $value): ?string
    {
        return is_string($value) && str_starts_with($value, 'https://') && filter_var($value, FILTER_VALIDATE_URL) !== false
            ? $value
            : null;
    }
}
