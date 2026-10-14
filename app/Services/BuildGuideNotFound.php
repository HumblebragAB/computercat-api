<?php

namespace App\Services;

// Version eller sökväg finns inte i byggguiden. Meddelandet innehåller aldrig
// anroparens indata och får visas i verktygssvar.
class BuildGuideNotFound extends \RuntimeException {}
