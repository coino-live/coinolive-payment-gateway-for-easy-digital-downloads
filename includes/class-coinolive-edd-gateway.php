<?php
/**
 * The Coino Live gateway for Easy Digital Downloads 3.
 *
 * An order only changes from a payment as read back from the API with the shop's key:
 * a webhook is just a trigger, so a forged one cannot mark anything paid.
 *
 * @package CoinoLiveEDD
 */

defined( 'ABSPATH' ) || exit;

class CoinoLive_EDD_Gateway {

	const ID = 'coinolive';

	const META_PAYMENT  = '_coinolive_payment_id';   // The current payment.
	const META_PAYMENTS = '_coinolive_payments';     // Every payment created for the order (IDs).
	const META_CHECKOUT = '_coinolive_checkout_url';
	const META_PRICE    = '_coinolive_price';        // "25.00 EUR": what the current payment was made for.
	const META_ATTEMPT  = '_coinolive_attempt';
	const META_NONCE    = '_coinolive_nonce';
	const META_STATES   = '_coinolive_states';       // Last state handled per payment, so notes are added once.

	/** Statuses in which the money is settled (or reversed): later news only adds a note. */
	const DONE = array( 'complete', 'refunded', 'partially_refunded', 'revoked' );

	public static function init() {
		add_filter( 'edd_payment_gateways', array( __CLASS__, 'register' ) );
		add_action( 'edd_coinolive_cc_form', '__return_false' );
		add_action( 'edd_gateway_coinolive', array( __CLASS__, 'process' ) );
		add_filter( 'edd_payment_confirm_coinolive', array( __CLASS__, 'confirmation' ) );
		add_filter( 'edd_settings_sections_gateways', array( __CLASS__, 'settings_section' ) );
		add_filter( 'edd_settings_gateways', array( __CLASS__, 'settings' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'coinolive_edd_sync', array( __CLASS__, 'sync_open_orders' ) );

		// The activation hook does not run when the plugin is updated by copying files: make sure the cron exists.
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( 'coinolive_edd_sync' ) ) {
					wp_schedule_event( time() + 300, 'coinolive_edd_five_minutes', 'coinolive_edd_sync' );
				}
			}
		);
	}

	public static function register( $gateways ) {
		$gateways[ self::ID ] = array(
			'admin_label'    => __( 'Coino Live (crypto)', 'coinolive-edd' ),
			'checkout_label' => __( 'Pay with crypto', 'coinolive-edd' ),
			'supports'       => array(),
		);
		return $gateways;
	}

	public static function option( $key, $default = '' ) {
		return function_exists( 'edd_get_option' ) ? edd_get_option( 'coinolive_' . $key, $default ) : $default;
	}

	public static function client() {
		$url = trim( (string) self::option( 'api_url' ) );
		return new CoinoLive_EDD_Client( (string) self::option( 'api_key' ), self::is_allowed_api_url( $url ) ? $url : '' );
	}

	/** Checkout: records the order (pending), creates its Coino Live payment and sends the customer to it. */
	public static function process( $purchase_data ) {
		if ( empty( $purchase_data['gateway_nonce'] ) || ! wp_verify_nonce( $purchase_data['gateway_nonce'], 'edd-gateway' ) ) {
			wp_die( esc_html__( 'Nonce verification has failed', 'coinolive-edd' ), esc_html__( 'Error', 'coinolive-edd' ), array( 'response' => 403 ) );
		}
		$back = '?payment-mode=' . self::ID;

		if ( '' === trim( (string) self::option( 'api_key' ) ) ) {
			edd_set_error( 'coinolive_not_configured', __( 'Crypto payment is not set up yet. Please choose another payment method.', 'coinolive-edd' ) );
			edd_send_back_to_checkout( $back );
			return;
		}
		$currency = strtoupper( edd_get_currency() );
		try {
			if ( ! in_array( $currency, self::currencies(), true ) ) {
				/* translators: %s: currency code */
				edd_set_error( 'coinolive_currency', sprintf( __( 'Crypto payment is not available in %s. Please choose another payment method.', 'coinolive-edd' ), $currency ) );
				edd_send_back_to_checkout( $back );
				return;
			}
		} catch ( CoinoLive_EDD_Exception $e ) {
			// API unreachable right now: creating the payment will say so.
		}

		$order_id = edd_insert_payment(
			array(
				'price'        => $purchase_data['price'],
				'date'         => $purchase_data['date'],
				'user_email'   => $purchase_data['user_email'],
				'purchase_key' => $purchase_data['purchase_key'],
				'currency'     => $currency,
				'downloads'    => $purchase_data['downloads'],
				'user_info'    => $purchase_data['user_info'],
				'cart_details' => $purchase_data['cart_details'],
				'gateway'      => self::ID,
				'status'       => 'pending',
			)
		);
		if ( ! $order_id ) {
			edd_record_gateway_error( __( 'Payment Error', 'coinolive-edd' ), 'Coino Live: the order could not be recorded.' );
			edd_send_back_to_checkout( $back );
			return;
		}
		if ( class_exists( '\EDD\Discounts\OrderValidation' ) && \EDD\Discounts\OrderValidation::refuse_unclaimed( $order_id ) ) {
			edd_send_back_to_checkout( $back );
			return;
		}

		try {
			$payment = self::payment_for( $order_id );
		} catch ( CoinoLive_EDD_Exception $e ) {
			/* translators: %s: error message */
			edd_insert_payment_note( $order_id, sprintf( __( 'Coino Live refused the payment: %s', 'coinolive-edd' ), $e->getMessage() ) );
			edd_update_payment_status( $order_id, 'failed' );
			/* translators: %s: error message */
			edd_set_error( 'coinolive_unavailable', sprintf( __( 'Crypto payment is not available right now: %s', 'coinolive-edd' ), $e->getMessage() ) );
			edd_send_back_to_checkout( $back );
			return;
		}

		edd_empty_cart();
		wp_redirect( in_array( $payment['status'], array( 'paid', 'confirming' ), true ) ? self::success_url( $order_id ) : $payment['checkout_url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the Coino Live checkout.
		exit;
	}

	/**
	 * The payment for an order: the current one while it still fits (or money is on its way), otherwise a new one.
	 *
	 * @return array The payment (open, confirming, partially_paid or paid)
	 */
	public static function payment_for( $order_id ) {
		$order    = edd_get_order( $order_id );
		$amount   = number_format( (float) $order->total, 2, '.', '' );
		$currency = strtoupper( (string) $order->currency );
		$price    = $amount . ' ' . $currency;
		$client   = self::client();

		$existing = (string) edd_get_order_meta( $order_id, self::META_PAYMENT, true );
		if ( '' !== $existing ) {
			try {
				$payment = $client->get_payment( $existing );
				self::apply( $payment );
				if ( in_array( $payment['status'], array( 'confirming', 'partially_paid', 'paid' ), true )
					|| ( 'open' === $payment['status'] && edd_get_order_meta( $order_id, self::META_PRICE, true ) === $price ) ) {
					return $payment;
				}
			} catch ( CoinoLive_EDD_Exception $e ) {
				if ( 404 !== $e->status ) {
					throw $e;
				}
			}
		}

		// First payment, a changed total, or the last one expired: a new attempt with a new key.
		$attempt = '' === $existing ? 0 : (int) edd_get_order_meta( $order_id, self::META_ATTEMPT, true ) + 1;
		// A random per-order nonce keeps keys unique even if order IDs repeat (a reset database).
		$nonce = (string) edd_get_order_meta( $order_id, self::META_NONCE, true );
		if ( '' === $nonce ) {
			$nonce = bin2hex( random_bytes( 8 ) );
			edd_update_order_meta( $order_id, self::META_NONCE, $nonce );
		}
		$key = 'edd-' . $order_id . '-' . $attempt . '-' . substr( md5( $nonce . '|' . $price . '|' . home_url() ), 0, 16 );

		$data = array(
			'amount'         => $amount,
			'currency'       => $currency,
			'order_id'       => (string) ( $order->order_number ? $order->order_number : $order_id ),
			/* translators: 1: order number, 2: shop name */
			'description'    => mb_substr( sprintf( __( 'Order #%1$s at %2$s', 'coinolive-edd' ), $order->order_number ? $order->order_number : $order_id, wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), 0, 500 ),
			'customer_email' => is_email( $order->email ) ? $order->email : null,
			'success_url'    => self::success_url( $order_id ),
			'cancel_url'     => edd_get_checkout_uri(),
			'expires_in'     => max( 15, min( 10080, (int) self::option( 'expires_in', 60 ) ) ),
			'metadata'       => array(
				'edd_order_id' => (string) $order_id,
				'site'         => home_url( '/' ),
			),
		);
		if ( self::is_public_url( rest_url( 'coinolive-edd/v1/webhook' ) ) ) {
			$data['notify_url'] = rest_url( 'coinolive-edd/v1/webhook' );
		}

		$payment = $client->create_payment( $data, $key );
		if ( (string) ( $payment['metadata']['edd_order_id'] ?? '' ) !== (string) $order_id || 'open' !== $payment['status'] ) {
			throw new CoinoLive_EDD_Exception( __( 'Coino Live returned a payment that does not belong to this order. Please try again.', 'coinolive-edd' ) );
		}

		$all   = (array) edd_get_order_meta( $order_id, self::META_PAYMENTS, true );
		$all[] = $payment['id'];
		edd_update_order_meta( $order_id, self::META_PAYMENTS, array_values( array_unique( array_filter( $all ) ) ) );
		edd_update_order_meta( $order_id, self::META_PAYMENT, $payment['id'] );
		edd_update_order_meta( $order_id, self::META_CHECKOUT, $payment['checkout_url'] );
		edd_update_order_meta( $order_id, self::META_PRICE, $price );
		edd_update_order_meta( $order_id, self::META_ATTEMPT, $attempt );
		/* translators: 1: payment ID, 2: amount in USD */
		edd_insert_payment_note( $order_id, sprintf( __( 'Coino Live payment %1$s created (%2$s USD). Waiting for the customer to pay.', 'coinolive-edd' ), $payment['id'], $payment['amount'] ) );

		return $payment;
	}

	/**
	 * Brings the order in line with the payment (as read from the API). Safe to call any number of times.
	 *
	 * @return string paid, updated, unchanged or unknown
	 */
	public static function apply( array $payment ) {
		$order_id = (int) ( $payment['metadata']['edd_order_id'] ?? 0 );
		$order    = $order_id ? edd_get_order( $order_id ) : null;
		// Only payments this shop created, for the order they were created for.
		if ( ! $order || self::ID !== $order->gateway || ! in_array( $payment['id'], (array) edd_get_order_meta( $order_id, self::META_PAYMENTS, true ), true ) ) {
			return 'unknown';
		}

		$states = (array) edd_get_order_meta( $order_id, self::META_STATES, true );
		$state  = $payment['status'] . '|' . $payment['received_usd'];
		if ( ( $states[ $payment['id'] ] ?? '' ) === $state ) {
			return 'unchanged';
		}

		$current = (string) edd_get_payment_status( $order_id );
		$done    = in_array( $current, self::DONE, true );
		$latest  = edd_get_order_meta( $order_id, self::META_PAYMENT, true ) === $payment['id'];
		$result  = 'updated';

		switch ( $payment['status'] ) {
			case 'paid':
				$requested = $payment['requested_amount'] . ' ' . $payment['requested_currency'];
				if ( $done ) {
					/* translators: 1: payment ID, 2: list of deposits */
					edd_insert_payment_note( $order_id, sprintf( __( 'Another Coino Live payment (%1$s) was paid for this order: %2$s. Refund or settle it with the customer.', 'coinolive-edd' ), $payment['id'], self::deposits( $payment ) ) );
				} elseif ( $latest && edd_get_order_meta( $order_id, self::META_PRICE, true ) !== $requested ) {
					edd_update_payment_status( $order_id, 'on_hold' );
					/* translators: 1: amount paid for, 2: order amount */
					edd_insert_payment_note( $order_id, sprintf( __( 'Coino Live payment is paid but was for %1$s, the order expects %2$s. Check before giving access.', 'coinolive-edd' ), $requested, edd_get_order_meta( $order_id, self::META_PRICE, true ) ) );
				} else {
					edd_set_payment_transaction_id( $order_id, $payment['id'] );
					/* translators: 1: payment ID, 2: list of deposits */
					edd_insert_payment_note( $order_id, sprintf( __( 'Coino Live payment %1$s confirmed: %2$s', 'coinolive-edd' ), $payment['id'], self::deposits( $payment ) ) );
					edd_update_payment_status( $order_id, 'complete' );
					$result = 'paid';
				}
				break;
			case 'confirming':
				if ( ! $done && 'on_hold' !== $current ) {
					edd_update_payment_status( $order_id, 'processing' );
				}
				edd_insert_payment_note( $order_id, __( 'Coino Live: the payment was sent and is waiting for network confirmations.', 'coinolive-edd' ) );
				break;
			case 'partially_paid':
				if ( ! $done ) {
					edd_update_payment_status( $order_id, 'on_hold' );
				}
				/* translators: 1: USD received, 2: USD expected */
				edd_insert_payment_note( $order_id, sprintf( __( 'Coino Live: only %1$s of %2$s USD received so far. The customer was asked to send the rest.', 'coinolive-edd' ), $payment['received_usd'], $payment['amount'] ) );
				break;
			case 'expired':
			case 'cancelled':
				if ( (float) $payment['received_usd'] > 0 ) {
					if ( ! $done ) {
						edd_update_payment_status( $order_id, 'on_hold' );
					}
					/* translators: 1: USD received, 2: USD expected */
					edd_insert_payment_note( $order_id, sprintf( __( 'Coino Live: the payment closed with %1$s of %2$s USD received (in your Coino Live balance). Settle the rest with the customer.', 'coinolive-edd' ), $payment['received_usd'], $payment['amount'] ) );
				} elseif ( $latest && in_array( $current, array( 'pending', 'processing' ), true ) ) {
					edd_update_payment_status( $order_id, 'failed' );
					edd_insert_payment_note( $order_id, __( 'Coino Live: the payment window closed without payment.', 'coinolive-edd' ) );
				}
				break;
			default:
				// open: nothing to record.
		}

		$states                   = (array) edd_get_order_meta( $order_id, self::META_STATES, true );
		$states[ $payment['id'] ] = $state;
		edd_update_order_meta( $order_id, self::META_STATES, $states );

		return $result;
	}

	/** Re-reads an order's current payment and applies it. */
	public static function refresh( $order_id ) {
		$payment_id = (string) edd_get_order_meta( $order_id, self::META_PAYMENT, true );
		if ( '' === $payment_id ) {
			return 'unknown';
		}

		return self::apply( self::client()->get_payment( $payment_id ) );
	}

	/** The success page: re-check the payment and tell the customer where it stands. */
	public static function confirmation( $content ) {
		$order_id = isset( $_GET['payment-id'] ) ? absint( $_GET['payment-id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$order    = $order_id ? edd_get_order( $order_id ) : null;
		$session  = function_exists( 'edd_get_purchase_session' ) ? edd_get_purchase_session() : array();
		// Only the browser that placed the order (its purchase session) sees the details.
		if ( ! $order || self::ID !== $order->gateway || empty( $session['purchase_key'] ) || ! hash_equals( (string) $order->payment_key, (string) $session['purchase_key'] ) ) {
			return $content;
		}

		try {
			self::refresh( $order_id );
			$payment = self::client()->get_payment( (string) edd_get_order_meta( $order_id, self::META_PAYMENT, true ) );
		} catch ( CoinoLive_EDD_Exception $e ) {
			return $content;
		}

		$box = '';
		if ( 'confirming' === $payment['status'] ) {
			$box = '<p>' . esc_html__( 'Your crypto payment was received and is being confirmed on the network. We will email you when the order is paid.', 'coinolive-edd' ) . '</p>';
		} elseif ( in_array( $payment['status'], array( 'open', 'partially_paid' ), true ) ) {
			$text = 'open' === $payment['status']
				? __( 'We have not seen your payment yet. If you have not paid, you can still do so:', 'coinolive-edd' )
				: __( 'Part of your crypto payment has arrived. Please send the rest:', 'coinolive-edd' );
			$box  = '<p>' . esc_html( $text ) . ' <a class="button" href="' . esc_url( $payment['checkout_url'] ) . '">' . esc_html__( 'Open the payment page', 'coinolive-edd' ) . '</a></p>';
		} elseif ( in_array( $payment['status'], array( 'expired', 'cancelled' ), true ) ) {
			$box = '<p>' . esc_html__( 'The payment time ran out. Please contact us or place the order again.', 'coinolive-edd' ) . '</p>';
		}

		return ( '' !== $box ? '<div class="edd-alert edd-alert-info coinolive-edd-status">' . $box . '</div>' : '' ) . $content;
	}

	public static function routes() {
		register_rest_route(
			'coinolive-edd/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true', // Authenticated by signature and the API re-read.
			)
		);
	}

	/** A webhook only says "this payment changed"; the order is updated from the payment as read back from the API. */
	public static function webhook( WP_REST_Request $request ) {
		$body   = $request->get_body();
		$secret = trim( (string) self::option( 'webhook_secret' ) );
		if ( '' !== $secret && ! self::valid_signature( (string) $request->get_header( 'x_coino_signature' ), $body, $secret ) ) {
			return new WP_REST_Response( array( 'message' => 'Invalid signature.' ), 401 );
		}

		$event = json_decode( $body, true );
		$id    = is_array( $event ) ? (string) ( $event['data']['id'] ?? '' ) : '';
		if ( 0 !== strpos( (string) ( $event['event'] ?? '' ), 'payment.' ) || ! preg_match( '/^[0-9a-z]{26}$/', $id ) ) {
			return new WP_REST_Response( array( 'ignored' => true ), 200 );
		}

		try {
			$payment = self::client()->get_payment( $id );
		} catch ( CoinoLive_EDD_Exception $e ) {
			return new WP_REST_Response( array( 'message' => 404 === $e->status ? 'Unknown payment.' : 'Try again later.' ), 404 === $e->status ? 200 : 503 );
		}

		return new WP_REST_Response( array( 'ok' => true, 'result' => self::apply( $payment ) ), 200 );
	}

	/** Safety net for missed webhooks (WP-Cron, every five minutes). */
	public static function sync_open_orders() {
		if ( '' === trim( (string) self::option( 'api_key' ) ) ) {
			return;
		}
		$orders = edd_get_orders(
			array(
				'gateway'      => self::ID,
				'status__in'   => array( 'pending', 'processing', 'on_hold' ),
				'date_created_query' => array( 'after' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ),
				'number'       => 50,
				'orderby'      => 'id',
				'order'        => 'DESC',
			)
		);
		foreach ( $orders as $order ) {
			try {
				self::refresh( (int) $order->id );
			} catch ( CoinoLive_EDD_Exception $e ) {
				if ( 0 === $e->status || $e->status >= 500 ) {
					break; // API unreachable: try again on the next run.
				}
			}
		}
	}

	public static function settings_section( $sections ) {
		$sections['coinolive'] = __( 'Coino Live (crypto)', 'coinolive-edd' );
		return $sections;
	}

	public static function settings( $settings ) {
		$settings['coinolive'] = array(
			'coinolive_header'         => array(
				'id'   => 'coinolive_header',
				'name' => '<strong>' . __( 'Coino Live (crypto)', 'coinolive-edd' ) . '</strong>',
				'type' => 'header',
			),
			'coinolive_status'         => array(
				'id'   => 'coinolive_status',
				'name' => __( 'Connection', 'coinolive-edd' ),
				'desc' => self::connection_status(),
				'type' => 'descriptive_text',
			),
			'coinolive_api_key'        => array(
				'id'   => 'coinolive_api_key',
				'name' => __( 'API key', 'coinolive-edd' ),
				'desc' => __( 'Create one in your Coino Live dashboard under Developers. Keys start with ck_live_ (or ck_test_ on the test network).', 'coinolive-edd' ),
				'type' => 'password',
			),
			'coinolive_webhook_secret' => array(
				'id'   => 'coinolive_webhook_secret',
				'name' => __( 'Webhook signing secret (optional)', 'coinolive-edd' ),
				'desc' => __( 'From your gateway settings (whsec_…). When set, webhooks with a wrong signature are refused. Orders are always checked against the API before they change, so this is an extra layer.', 'coinolive-edd' ),
				'type' => 'password',
			),
			'coinolive_expires_in'     => array(
				'id'   => 'coinolive_expires_in',
				'name' => __( 'Payment window (minutes)', 'coinolive-edd' ),
				'desc' => __( 'How long the customer has to send the payment. Between 15 and 10080.', 'coinolive-edd' ),
				'type' => 'number',
				'size' => 'small',
				'min'  => 15,
				'max'  => 10080,
				'std'  => 60,
			),
			'coinolive_api_url'        => array(
				'id'   => 'coinolive_api_url',
				'name' => __( 'API URL', 'coinolive-edd' ),
				'desc' => __( 'Leave as is unless Coino Live support tells you otherwise.', 'coinolive-edd' ),
				'type' => 'text',
				'std'  => CoinoLive_EDD_Client::DEFAULT_URL,
			),
		);
		return $settings;
	}

	/** Shown on the settings page: is the key accepted, and can the shop currency be priced? */
	private static function connection_status() {
		if ( ! is_admin() || ! isset( $_GET['section'] ) || 'coinolive' !== $_GET['section'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
			return '';
		}
		if ( '' === trim( (string) self::option( 'api_key' ) ) ) {
			return esc_html__( 'Add your API key below and save.', 'coinolive-edd' );
		}
		$url = trim( (string) self::option( 'api_url' ) );
		if ( '' !== $url && ! self::is_allowed_api_url( $url ) ) {
			return '<span style="color:#b32d2e">' . esc_html__( 'The API URL must start with https://.', 'coinolive-edd' ) . '</span>';
		}
		try {
			delete_transient( 'coinolive_edd_currencies' );
			$currency = strtoupper( edd_get_currency() );
			if ( ! in_array( $currency, self::currencies(), true ) ) {
				/* translators: %s: currency code */
				return '<span style="color:#b32d2e">' . esc_html( sprintf( __( 'Connected, but Coino Live cannot price payments in %s yet. The payment method stays hidden at checkout.', 'coinolive-edd' ), $currency ) ) . '</span>';
			}
			return '<span style="color:#008a20">' . esc_html__( 'Connected to Coino Live.', 'coinolive-edd' ) . '</span> ' . esc_html__( 'Webhook URL (sent with every payment, nothing to configure):', 'coinolive-edd' ) . ' <code>' . esc_html( rest_url( 'coinolive-edd/v1/webhook' ) ) . '</code>';
		} catch ( CoinoLive_EDD_Exception $e ) {
			/* translators: %s: error message */
			return '<span style="color:#b32d2e">' . esc_html( sprintf( __( 'Coino Live refused the API key: %s', 'coinolive-edd' ), $e->getMessage() ) ) . '</span>';
		}
	}

	/** @return list<string> Currency codes the API can price payments in (cached 12 hours). */
	public static function currencies() {
		$cached = get_transient( 'coinolive_edd_currencies' );
		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}
		$codes = array_values( array_map( static fn( $c ) => (string) $c['code'], self::client()->currencies() ) );
		set_transient( 'coinolive_edd_currencies', $codes, 12 * HOUR_IN_SECONDS );
		return $codes;
	}

	public static function success_url( $order_id ) {
		return add_query_arg(
			array(
				'payment-confirmation' => self::ID,
				'payment-id'           => (int) $order_id,
			),
			edd_get_success_page_uri()
		);
	}

	/** X-Coino-Signature: t=<unix time>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")> */
	public static function valid_signature( $header, $body, $secret ) {
		$parts = array();
		foreach ( explode( ',', $header ) as $pair ) {
			$kv = explode( '=', trim( $pair ), 2 );
			if ( 2 === count( $kv ) ) {
				$parts[ $kv[0] ] = $kv[1];
			}
		}
		if ( empty( $parts['t'] ) || empty( $parts['v1'] ) || ! ctype_digit( $parts['t'] ) || abs( time() - (int) $parts['t'] ) > 300 ) {
			return false;
		}

		return hash_equals( hash_hmac( 'sha256', $parts['t'] . '.' . $body, $secret ), $parts['v1'] );
	}

	private static function deposits( array $payment ) {
		$lines = array();
		foreach ( (array) ( $payment['deposits'] ?? array() ) as $d ) {
			$lines[] = sprintf( '%s %s (≈ %s USD), tx %s', $d['amount'], $d['symbol'] ?? strtoupper( (string) $d['asset'] ), $d['usd_value'], $d['tx_hash'] );
		}
		return implode( '; ', $lines );
	}

	private static function is_allowed_api_url( $url ) {
		return 0 === strpos( $url, 'https://' ) || (bool) preg_match( '#^http://(localhost|127\.0\.0\.1)(:\d+)?/#', $url );
	}

	/** False for local development sites, which Coino Live cannot (and must not) call. */
	private static function is_public_url( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host || 'localhost' === $host || preg_match( '/\.(local|localhost|test|internal)$/', $host ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return (bool) filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return true;
	}
}
