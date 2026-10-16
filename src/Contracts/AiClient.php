<?php

namespace Goldnead\StatamicAutomations\Contracts;

use Goldnead\StatamicAutomations\Support\AiRequestException;

/**
 * The one seam between the `ai_generate` action and the model provider.
 *
 * It exists so a test can hand the action a fake and assert on the model,
 * token limit and timeout the node actually asked for, without an HTTP
 * request. The shipped implementation talks to the Anthropic Messages API;
 * other providers are not part of this contract's job today.
 */
interface AiClient
{
    /**
     * Send a Messages API request body and return the decoded response.
     *
     * @param  array<string, mixed>  $body  model, max_tokens, messages, optional system
     * @param  int  $timeout  seconds the request may take before it is abandoned
     * @return array<string, mixed>
     *
     * @throws AiRequestException with a message fit for the run log
     */
    public function messages(array $body, int $timeout): array;
}
