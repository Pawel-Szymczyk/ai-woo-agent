<?php
/**
 * Plugin Name:  AI WooCommerce Agent
 * Version:      1.0.0
 * Description:  Agent AI obsługi klienta sklepu WooCommerce
 * Author:       Pawel Szymczyk
 * License:      GPL v2 
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AI_WOO_VERSION', '1.0.0' );
define( 'AI_WOO_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_WOO_URL', plugin_dir_url( __FILE__ ) );

if ( ! class_exists( 'AI_API' ) ) {
    require_once AI_WOO_DIR . 'includes/class-ai-api.php';
}
require_once AI_WOO_DIR . 'includes/class-ai-woo-agent.php';

add_action( 'plugins_loaded', function() {
    new AI_Woo_Agent();
} );