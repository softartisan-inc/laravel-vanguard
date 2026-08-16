<?php

namespace SoftArtisan\Vanguard\Events;

use SoftArtisan\Vanguard\Models\RestoreRecord;

class RestoreFailed
{
    public function __construct(
        public readonly RestoreRecord $record,
        public readonly \Throwable $exception,
    ) {}
}
