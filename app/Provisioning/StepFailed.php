<?php

namespace App\Provisioning;

// Ett steg som inte kan fortsätta. Meddelandet visas för spelets medlemmar
// och får aldrig innehålla hemligheter.
final class StepFailed extends \RuntimeException implements SafeMessage {}
