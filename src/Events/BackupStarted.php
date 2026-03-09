<?php

namespace SoftArtisan\Vanguard\Events;

use SoftArtisan\Vanguard\Models\BackupRecord;

class BackupStarted
{
    public function __construct(public readonly BackupRecord $record) {}
}
