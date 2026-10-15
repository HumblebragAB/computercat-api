<?php

namespace App\Releases\AppStore;

use App\Provisioning\SafeMessage;

// Meddelandet nämner bara env-namn, aldrig värden.
final class AppStoreNotConfigured extends \RuntimeException implements SafeMessage {}
