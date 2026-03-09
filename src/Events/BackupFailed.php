<?php

namespace SoftArtisan\Vanguard\Events;

use SoftArtisan\Vanguard\Models\BackupRecord;

class BackupFailed
{
    public function __construct(
        public readonly BackupRecord $record,
        public readonly \Throwable   $exception,
    ) {}
}
