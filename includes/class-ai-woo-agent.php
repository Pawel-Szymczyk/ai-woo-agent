<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AI_Woo_Agent {

    private AI_API $api;

    public function __construct() {
        $this->api = new AI_API();
        add_action( 'init', [ $this, 'start_session' ] ); // track history per user session
        add_action( 'wp_logout', [ $this, 'end_session' ] );
        add_action( 'rest_api_init',      [ $this, 'register_endpoints' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_shortcode( 'ai_woo_agent',    [ $this, 'render_chat' ] );
    }

    public function start_session(): void {
        if ( ! session_id() ) session_start();
    }
    
    public function end_session(): void {
    // Usuń tylko historię naszego agenta, nie całą sesję
    if ( isset( $_SESSION ) ) {
        foreach ( $_SESSION as $key => $value ) {
            if ( strpos( $key, 'ai_woo_' ) === 0 ) {
                unset( $_SESSION[ $key ] );
            }
        }
    }
}

    // ── REST Endpoint ─────────────────────────────────────────────────────
    public function register_endpoints(): void {
        register_rest_route( 'ai-woo/v1', '/chat', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_chat' ],
            // Tylko zalogowani klienci
            'permission_callback' => function() {
                return is_user_logged_in();
            },
        ] );
    }

    public function handle_chat( WP_REST_Request $request ): WP_REST_Response {

        $message = sanitize_text_field( $request->get_param( 'message' ) );
        $session_id = sanitize_key( $request->get_param( 'session_id' ) );
        $user_id = get_current_user_id();

        if ( empty( $message ) ) {
            return new WP_REST_Response( [ 'error' => 'Message required.' ], 400 );
        }

        // Historia rozmowy z sesji
        $session_key = 'ai_woo_' . $session_id;
        $history     = $_SESSION[ $session_key ] ?? [];
        $history[]   = [ 'role' => 'user', 'content' => $message ];

        // Pobierz kontekst zamówień klienta
        $context = $this->get_customer_context( $user_id );
        $system  = $this->get_system_prompt( $context );

        $reply = $this->api->send_messages( $history, $system );

        if ( is_wp_error( $reply ) ) {
            return new WP_REST_Response( [ 'error' => $reply->get_error_message() ], 500 );
        }

        // Zapisz historię
        $history[]               = [ 'role' => 'assistant', 'content' => $reply ];
        $_SESSION[ $session_key ] = array_slice( $history, -20 );

        // Eskalacja do admina gdy agent nie może pomóc
        $this->maybe_escalate( $message, $reply, $user_id );

        return new WP_REST_Response( [ 'reply' => $reply ], 200 );
    }

    // ── Customer Context ──────────────────────────────────────────────────
    private function get_customer_context( int $user_id ): string {

        if ( ! function_exists( 'wc_get_orders' ) ) {
            return 'WooCommerce nie jest zainstalowany.';
        }

        $orders = wc_get_orders( [
            'customer' => $user_id,
            'limit'    => 5,
            'orderby'  => 'date',
            'order'    => 'DESC',
        ] );

        if ( empty( $orders ) ) {
            return 'Ten klient nie ma żadnych zamówień.';
        }

        $ctx = "=== ZAMÓWIENIA KLIENTA ===\n";
        foreach ( $orders as $order ) {
            $ctx .= sprintf(
                "\nZamówienie #%s | Status: %s | Kwota: %s | Data: %s\n",
                $order->get_order_number(),
                wc_get_order_status_name( $order->get_status() ),
                $order->get_formatted_order_total(),
                $order->get_date_created()->format( 'd.m.Y' )
            );
            foreach ( $order->get_items() as $item ) {
                $ctx .= sprintf(
                    "  - %s x%d\n",
                    $item->get_name(),
                    $item->get_quantity()
                );
            }
        }
        $ctx .= "=== KONIEC ZAMÓWIEŃ ===";

        return $ctx;
    }

    // ── System Prompt ─────────────────────────────────────────────────────
    private function get_system_prompt( string $context ): string {
        $shop_name = get_bloginfo( 'name' );
        $contact   = get_option( 'admin_email' );

        return $context . "\n\n" .
            "Jesteś asystentem obsługi klienta sklepu {$shop_name}. " .
            'Odpowiadaj po polsku, profesjonalnie i przyjaźnie. ' .
            'Masz dostęp do zamówień klienta powyżej — używaj ich do odpowiedzi. ' .
            'Jeśli klient pyta o zwrot: poinformuj że powinien skontaktować się przez email. ' .
            'Jeśli nie możesz pomóc: powiedz "Nie mogę pomóc w tej sprawie. ' .
            "Skontaktuj się z nami: {$contact}\"";
    }

    // ── Escalation ────────────────────────────────────────────────────────
    private function maybe_escalate( string $query, string $reply, int $user_id ): void {
        $triggers = [ 'nie mogę pomóc', 'skontaktuj się', 'nie mam informacji' ];
        foreach ( $triggers as $trigger ) {
            if ( stripos( $reply, $trigger ) !== false ) {
                $user = get_userdata( $user_id );
                wp_mail(
                    get_option( 'admin_email' ),
                    '[AI Agent] Klient potrzebuje pomocy',
                    "Klient: {$user->user_email}\n\n" .
                    "Pytanie: {$query}\n\n" .
                    "Odpowiedź agenta: {$reply}"
                );
                break;
            }
        }
    }

    // ── Assets ────────────────────────────────────────────────────────────
   public function enqueue_assets(): void {
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'ai_woo_agent' ) ) {
            return; // ← sprawdzenie na początku, nie w środku
        }

        wp_enqueue_style( 'ai-woo-chat', AI_WOO_URL . 'assets/css/chat.css', [], AI_WOO_VERSION );

        wp_enqueue_script( 'dompurify', 'https://cdn.jsdelivr.net/npm/dompurify@3/dist/purify.min.js', [], '3.0.0', true );
        wp_enqueue_script( 'marked',    'https://cdn.jsdelivr.net/npm/marked/marked.min.js',            [], '9.0.0', true );

        // chat.js zależy od marked i dompurify — ładowane przed nim
        wp_enqueue_script( 'ai-woo-chat', AI_WOO_URL . 'assets/js/chat.js', [ 'marked', 'dompurify' ], AI_WOO_VERSION, true );

        wp_localize_script( 'ai-woo-chat', 'AiChatConfig', [
            'apiUrl' => rest_url( 'ai-woo/v1/chat' ),
            'nonce'  => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    // ── Shortcode ─────────────────────────────────────────────────────────
    public function render_chat(): string {

        // Pokaż komunikat gdy klient nie jest zalogowany
        if ( ! is_user_logged_in() ) {
            return '<p>Zaloguj się aby skorzystać z asystenta. ' .
                   '<a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">Zaloguj się</a></p>';
        }

        ob_start(); ?>
        <div id='ai-chat-widget'>
            <div id='ai-chat-messages'></div>
            <div id='ai-chat-input-area'>
                <input type='text' id='ai-chat-input'
                       placeholder='Zapytaj o swoje zamówienie...' />
                <button id='ai-chat-send'>Wyślij</button>
            </div>
        </div>
        <?php return ob_get_clean();
    }
}