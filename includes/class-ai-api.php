<?php
// includes/class-ai-api.php
// Single responsibility: send messages to Claude, return text response.
 
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! class_exists( 'AI_API' ) ) {
    class AI_API {
    
        private string $api_key;
        private string $api_url = 'https://api.anthropic.com/v1/messages';
        // claude-haiku-4-5 = fast and cheap, ideal for chat.
        // For complex reasoning switch to: 'claude-sonnet-4-6'
        private string $model   = 'claude-haiku-4-5';
    
        public function __construct() {
            // Read API key from WordPress database, admin sets it in settings.
            $this->api_key = get_option( 'ai_assistant_api_key', '' );
        }
    
        /**
         * Send conversation to Claude and return text reply.
         * @param array  $messages  [{role:'user'|'assistant', content:'text'}, ...]
         * @param string $system    System prompt (Claude's instructions)
         * @return string|WP_Error  Reply text, or WP_Error on failure
         */
        public function send_messages( array $messages, string $system = '' ) {
    
            if ( empty( $this->api_key ) ) {
                return new WP_Error( 'no_api_key', 'API key missing. Set it in Settings > AI Assistant.' );
            }
    
            $body = [
                'model'      => $this->model,
                'max_tokens' => 1024, // max response length (~750 words)
                'messages'   => $messages,
            ];
            if ( ! empty( $system ) ) {
                $body['system'] = $system; // system prompt is separate from messages
            }
    
            // wp_remote_post = WordPress HTTP API.
            // Safer than curl: handles SSL certificates, respects WP filters.
            $response = wp_remote_post( $this->api_url, [
                'headers' => [
                    'Content-Type'      => 'application/json',
                    'x-api-key'         => $this->api_key,
                    'anthropic-version' => '2023-06-01', // required by Anthropic API
                ],
                'body'    => wp_json_encode( $body ), // PHP array -> JSON string
                'timeout' => 30, // secs
            ] );
    
            // WP_Error returned on network failure (no internet, DNS, timeout).
            if ( is_wp_error( $response ) ) {
                return $response;
            }
    
            $code = wp_remote_retrieve_response_code( $response );
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
    
            // HTTP 200 = success. Anything else = API error.
            if ( $code !== 200 ) {
                $msg = $data['error']['message'] ?? "Claude API error (HTTP {$code})";
                return new WP_Error( 'api_error', $msg );
            }
    
            // Anthropic response: content array, first item, text field.
            return $data['content'][0]['text'] ?? '';
        }
    }
}