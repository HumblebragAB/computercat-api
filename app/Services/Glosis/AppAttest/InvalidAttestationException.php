<?php

namespace App\Services\Glosis\AppAttest;

use RuntimeException;

/** Avvisad attestering eller assertion. Meddelandet är internt (loggas), visas aldrig i appen. */
final class InvalidAttestationException extends RuntimeException {}
