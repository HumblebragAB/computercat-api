<?php

namespace App\Provisioning;

// Markör för undantag vars meddelande är redigerat och får visas för spelets
// medlemmar (last_error, get_provisioning_status). Alla andra undantag visas
// bara som en referens.
interface SafeMessage {}
