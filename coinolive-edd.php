<?php
/**
 * Plugin Name:       Coino Live Crypto Payments for Easy Digital Downloads
 * Plugin URI:        https://coino.live/plugins
 * Description:       Accept USDT, Bitcoin, Ethereum, TRON, TON, Solana and more in Easy Digital Downloads. Customers pay on the Coino Live checkout; orders update automatically.
 * Version:           1.0.0
 * Author:            Coino Live
 * Author URI:        https://coino.live
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       coinolive-edd
 * Domain Path:       /languages
 * Requires at least: 6.7
 * Requires PHP:      8.0
 * Requires Plugins:  easy-digital-downloads
 */

defined( 'ABSPATH' ) || exit;

define( 'COINOLIVE_EDD_VERSION', '1.0.0' );
define( 'COINOLIVE_EDD_FILE', __FILE__ );
define( 'COINOLIVE_EDD_DIR', plugin_dir_path( __FILE__ ) );
define( 'COINOLIVE_EDD_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'coinolive-edd', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( ! function_exists( 'edd_insert_payment' ) || ! function_exists( 'edd_get_order' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Coino Live Crypto Payments needs Easy Digital Downloads 3 to be installed and active.', 'coinolive-edd' ) . '</p></div>';
				}
			);
			return;
		}

		require_once COINOLIVE_EDD_DIR . 'includes/class-coinolive-edd-client.php';
		require_once COINOLIVE_EDD_DIR . 'includes/class-coinolive-edd-gateway.php';
		CoinoLive_EDD_Gateway::init();

		if ( is_admin() ) {
			require_once COINOLIVE_EDD_DIR . 'includes/class-coinolive-edd-admin.php';
			CoinoLive_EDD_Admin::init();
		}
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=download&page=edd-settings&tab=gateways&section=coinolive' ) ) . '">' . esc_html__( 'Settings', 'coinolive-edd' ) . '</a>' );
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=download&page=coinolive-edd' ) ) . '">' . esc_html__( 'Dashboard', 'coinolive-edd' ) . '</a>' );
		return $links;
	}
);

// Safety net for missed webhooks: re-check open orders every five minutes.
add_filter(
	'cron_schedules',
	static function ( $schedules ) {
		$schedules['coinolive_edd_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every five minutes', 'coinolive-edd' ),
		);
		return $schedules;
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! wp_next_scheduled( 'coinolive_edd_sync' ) ) {
			wp_schedule_event( time() + 300, 'coinolive_edd_five_minutes', 'coinolive_edd_sync' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'coinolive_edd_sync' );
	}
);
