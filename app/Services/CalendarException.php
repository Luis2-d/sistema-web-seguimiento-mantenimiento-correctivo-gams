<?php

namespace App\Services;

use RuntimeException;

// Only these deliberately written messages may be shown to users.
class CalendarException extends RuntimeException {}
