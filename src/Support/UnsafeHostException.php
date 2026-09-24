<?php

namespace Goldnead\StatamicAutomations\Support;

use RuntimeException;

/** A connection URL points somewhere this addon will not call. See {@see HostGuard}. */
class UnsafeHostException extends RuntimeException {}
