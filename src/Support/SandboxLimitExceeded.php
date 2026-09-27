<?php

namespace Goldnead\StatamicAutomations\Support;

use RuntimeException;

/** A sandboxed Antlers render went over its budget. */
class SandboxLimitExceeded extends RuntimeException {}
