<?php

namespace App\Services\Glosis;

use RuntimeException;

/**
 * Claude kunde inte ge en användbar glosläxa: API-fel, vägran eller svar som
 * inte klarar valideringen. Meddelandet är internt och loggas; användaren får
 * ett eget, barnvänligt meddelande.
 */
final class ScanFailedException extends RuntimeException {}
