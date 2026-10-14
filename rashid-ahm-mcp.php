<?php
/**
 * Plugin Name: Rashid Ahm MCP
 * Description: A complete AI agency team for WordPress. Connect Claude, ChatGPT or any MCP app with role keys (supervisor, designer, SEO, copywriter and more), watch every change live in the Live Studio, edit directly, say yes in the chat and it goes live, and undo for 30 days.
 * Version: 2.1.0
 * Author: Rashid Ahmad
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: All rights reserved
 * Text Domain: rashid-ahm-mcp
 *
 * Made from scratch by Rashid Ahmad. Help: rashid.ahm199@outlook.com
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RAHM_VERSION', '2.1.0' );
define( 'RAHM_FILE', __FILE__ );
define( 'RAHM_DIR', plugin_dir_path( __FILE__ ) );
define( 'RAHM_URL', plugin_dir_url( __FILE__ ) );
define( 'RAHM_CONTACT', 'rashid.ahm199@outlook.com' );

foreach ( array( 'store', 'i18n', 'auth', 'ctx', 'skills', 'seo', 'direct', 'tools', 'tools-team', 'audit', 'server', 'studio', 'admin' ) as $rahm_file ) {
	require_once RAHM_DIR . 'includes/class-rahm-' . $rahm_file . '.php';
}
unset( $rahm_file );

register_activation_hook( __FILE__, array( 'RAHM_Store', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RAHM_Store', 'deactivate' ) );

RAHM_Store::init();
RAHM_SEO::init();
RAHM_Server::init();
RAHM_Studio::init();
RAHM_Admin::init();

/* Maintenance page: visitors see a short notice, admins and the MCP keep working. */
add_action(
	'template_redirect',
	function () {
		$s = RAHM_Store::settings();
		if ( empty( $s['maintenance'] ) || current_user_can( 'manage_options' ) || is_user_logged_in() ) {
			return;
		}
		status_header( 503 );
		header( 'Retry-After: 3600' );
		wp_die(
			'<h1>' . esc_html( get_bloginfo( 'name' ) ) . '</h1><p>' . esc_html( $s['maintenance_msg'] ) . '</p>',
			esc_html( get_bloginfo( 'name' ) ),
			array( 'response' => 503 )
		);
	}
);
