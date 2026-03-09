<?php

namespace SoftArtisan\Vanguard\Events;

use SoftArtisan\Vanguard\Models\BackupRecord;

class BackupCompleted
{
    public function __construct(public readonly BackupRecord $record) {}
}
