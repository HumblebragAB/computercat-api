<?php

namespace App\Services\Glosis;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Ett nekat Glosis-anrop med svar i appens felform:
 * {"error": "<kod>", "message": "<barnvänlig text>", ...extra}.
 * Meddelandet visas för användaren och innehåller aldrig interna detaljer.
 */
final class GlosisRejection extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $error,
        public readonly string $userMessage,
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($error);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json(['error' => $this->error, 'message' => $this->userMessage] + $this->extra, $this->status, $this->headers);
    }
}
