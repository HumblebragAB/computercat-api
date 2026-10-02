<?php

namespace App\Services\Glosis;

use RuntimeException;

/**
 * Butiken gick inte att fråga (nätverk, 5xx, saknad behörighet, trasiga
 * inloggningsuppgifter). Köpet kan vara giltigt, så svaret blir 503, inte 422.
 * Meddelandet är internt och innehåller aldrig nycklar eller köpbevis.
 */
final class StoreUnavailableException extends RuntimeException {}
