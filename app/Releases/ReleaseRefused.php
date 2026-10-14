<?php

namespace App\Releases;

// Ett släpp som inte får skapas, godkännas eller startas. Meddelandet är engelskt
// och skrivs av ReleaseGate/ReleaseService, aldrig av användarinmatning, så det får visas.
final class ReleaseRefused extends \RuntimeException {}
