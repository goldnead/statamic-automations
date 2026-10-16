<?php

namespace Goldnead\StatamicAutomations\Support;

use Goldnead\StatamicAutomations\Models\AutomationConnection;

/** The connection templates that ship with the addon. */
class BuiltInConnectionTemplates
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'zoom_server_to_server' => self::zoom(),
            'deepgram' => self::deepgram(),
        ];
    }

    /** @return array<string, mixed> */
    protected static function zoom(): array
    {
        return [
            'name' => 'Zoom (Server-to-Server)',
            'description' => 'Zoom Server-to-Server OAuth app. Fill in the account ID, client ID and client secret of the app.',
            'connection' => [
                'handle' => 'zoom',
                'name' => 'Zoom (Server-to-Server)',
                'base_url' => 'https://api.zoom.us/v2',
                'auth_type' => AutomationConnection::OAUTH2,
                'auth_config' => [
                    'token_url' => 'https://zoom.us/oauth/token',
                    'client_id' => '',
                    'client_secret' => '',
                    'scope' => '',
                    'client_auth_method' => 'client_secret_basic',
                    'token_request_fields' => [
                        'grant_type' => 'account_credentials',
                        'account_id' => '',
                    ],
                ],
                'test_path' => '/users/me',
            ],
            'operations' => [
                [
                    'handle' => 'recordings_list',
                    'name' => 'List recordings',
                    'description' => 'The cloud recordings of a user in a date range (YYYY-MM-DD).',
                    'method' => 'GET',
                    'path' => '/users/{{ input.user_id }}/recordings',
                    'query' => ['from' => '{{ input.from }}', 'to' => '{{ input.to }}'],
                    'inputs' => [
                        ['handle' => 'user_id', 'label' => 'User ID or email', 'type' => 'text', 'required' => false, 'default' => 'me'],
                        ['handle' => 'from', 'label' => 'From (YYYY-MM-DD)', 'type' => 'text', 'required' => false],
                        ['handle' => 'to', 'label' => 'To (YYYY-MM-DD)', 'type' => 'text', 'required' => false],
                    ],
                ],
                [
                    'handle' => 'recording_get',
                    'name' => 'Get recording',
                    'description' => 'The recording files of one meeting. Downloading is left to the asset.fetch_from_url action.',
                    'method' => 'GET',
                    'path' => '/meetings/{{ input.meeting_id }}/recordings',
                    'inputs' => [
                        ['handle' => 'meeting_id', 'label' => 'Meeting ID or UUID', 'type' => 'text', 'required' => true],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected static function deepgram(): array
    {
        return [
            'name' => 'Deepgram',
            'description' => 'Deepgram speech-to-text. Deepgram wants the key as "Token <key>", not "Bearer".',
            'connection' => [
                'handle' => 'deepgram',
                'name' => 'Deepgram',
                'base_url' => 'https://api.deepgram.com/v1',
                'auth_type' => 'header',
                // The value is typed by the user: "Token " followed by the key.
                'auth_config' => ['name' => 'Authorization', 'value' => 'Token '],
            ],
            'operations' => [
                [
                    'handle' => 'transcribe_url',
                    'name' => 'Transcribe URL',
                    'description' => 'Transcribes an audio or video file that is reachable by URL.',
                    'method' => 'POST',
                    'path' => '/listen',
                    'query' => ['model' => '{{ input.model }}', 'language' => '{{ input.language }}', 'callback' => '{{ input.callback }}'],
                    'body' => ['url' => '{{ input.url }}'],
                    'inputs' => [
                        ['handle' => 'url', 'label' => 'Media URL', 'type' => 'text', 'required' => true],
                        ['handle' => 'model', 'label' => 'Model', 'type' => 'text', 'required' => false, 'default' => 'nova-3'],
                        ['handle' => 'language', 'label' => 'Language', 'type' => 'text', 'required' => false, 'default' => 'de'],
                        ['handle' => 'callback', 'label' => 'Callback URL', 'type' => 'text', 'required' => false],
                    ],
                ],
            ],
        ];
    }
}
