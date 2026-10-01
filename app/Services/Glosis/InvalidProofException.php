<?php

namespace App\Services\Glosis;

use RuntimeException;

/**
 * Köpbeviset gick inte att verifiera. Meddelandet är internt (loggas, visas
 * aldrig för användaren) och innehåller aldrig själva beviset.
 */
final class InvalidProofException extends RuntimeException {}
