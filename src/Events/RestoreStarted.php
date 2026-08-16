<?php

namespace SoftArtisan\Vanguard\Events;

use SoftArtisan\Vanguard\Models\RestoreRecord;

class RestoreStarted
{
    public function __construct(
        public readonly RestoreRecord $record,
    ) {}
}
