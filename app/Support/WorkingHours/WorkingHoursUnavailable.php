<?php

namespace App\Support\WorkingHours;

use RuntimeException;

/** The branch has no open time within the search window (spec 004 FR-011). */
final class WorkingHoursUnavailable extends RuntimeException {}
