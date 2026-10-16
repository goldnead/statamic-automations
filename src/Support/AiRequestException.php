<?php

namespace Goldnead\StatamicAutomations\Support;

use RuntimeException;

/**
 * A model request that did not produce a response. The message is written for
 * the run log, so the action passes it on verbatim.
 */
class AiRequestException extends RuntimeException {}
