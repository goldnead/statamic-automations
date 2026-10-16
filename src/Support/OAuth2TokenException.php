<?php

namespace Goldnead\StatamicAutomations\Support;

/**
 * The token endpoint could not hand out a token. The message is already
 * masked ({@see OAuth2TokenService}), so it may go into a run log as it is.
 */
class OAuth2TokenException extends \RuntimeException {}
