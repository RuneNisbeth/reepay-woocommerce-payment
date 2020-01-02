<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

abstract class WC_Payment_Gateway_Reepay extends WC_Payment_Gateway
	implements WC_Payment_Gateway_Reepay_Interface
{
	/**
	 * Check is Capture possible
	 *
	 * @param WC_Order|int $order
	 * @param bool         $amount
	 *
	 * @return bool
	 */
	public function can_capture( $order, $amount = FALSE ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( $order->get_payment_method() !== $this->id ) {
			return FALSE;
		}

		if ( ! $amount ) {
			$amount = $order->get_total();
		}

		$handle = $this->get_order_handle( $order );

		try {
			$result = $this->request( 'GET', 'https://api.reepay.com/v1/invoice/' . $handle );
		} catch (Exception $e) {
			return FALSE;
		}

		return $result['state'] === 'authorized';
	}

	/**
	 * Check is Cancel possible
	 *
	 * @param WC_Order|int $order
	 *
	 * @return bool
	 */
	public function can_cancel( $order ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( $order->get_payment_method() !== $this->id ) {
			return FALSE;
		}

		$handle = $this->get_order_handle( $order );

		try {
			$result = $this->request( 'GET', 'https://api.reepay.com/v1/invoice/' . $handle );
		} catch (Exception $e) {
			return FALSE;
		}

		return $result['state'] === 'authorized';
	}

	/**
	 * @param \WC_Order $order
	 * @param bool      $amount
	 *
	 * @return bool
	 * @throws \Exception
	 */
	public function can_refund( $order, $amount = FALSE ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( $order->get_payment_method() !== $this->id ) {
			return FALSE;
		}

		if ( ! $amount ) {
			$amount = $order->get_total();
		}

		$handle = $this->get_order_handle( $order );

		try {
			$result = $this->request( 'GET', 'https://api.reepay.com/v1/invoice/' . $handle );
		} catch (Exception $e) {
			return FALSE;
		}

		return $result['state'] === 'settled';
	}

	/**
	 * Capture
	 *
	 * @param WC_Order|int $order
	 * @param bool         $amount
	 *
	 * @throws \Exception
	 * @return void
	 */
	public function capture_payment( $order, $amount = FALSE ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $amount ) {
			$amount = $order->get_total();
		}

		if ( ! $this->can_capture( $order, $amount ) ) {
			throw new Exception( 'Payment can\'t be captured.' );
		}

		$handle = $this->get_order_handle( $order );
		if ( empty( $handle ) ) {
			throw new Exception( 'Unable to get order handle' );
		}

		try {
			$result = $this->request( 'POST', 'https://api.reepay.com/v1/charge/' . $handle . '/settle' );
		} catch ( Exception $e ) {
			throw new Exception( sprintf( 'API Error: %s', $e->getMessage() ) );
		}

		update_post_meta( $order->get_id(), '_reepay_capture_transaction', $result['transaction'] );

		$order->set_transaction_id( $result['transaction'] );
		$order->save();

		$order->payment_complete( $result['transaction'] );

		if (defined('REEPAY_STATUS_SETTLED')) {
			$order->set_status( REEPAY_STATUS_SETTLED );
			$order->save();
		}

		$order->add_order_note( __( 'Transaction settled.', 'woocommerce-gateway-reepay-checkout' ) );
	}

	/**
	 * Cancel
	 *
	 * @param WC_Order|int $order
	 *
	 * @throws \Exception
	 * @return void
	 */
	public function cancel_payment( $order ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $this->can_cancel( $order ) ) {
			throw new Exception( 'Payment can\'t be cancelled.' );
		}

		$handle = $this->get_order_handle( $order );
		if ( empty( $handle ) ) {
			throw new Exception( 'Unable to get order handle' );
		}

		try {
			$result = $this->request( 'POST', 'https://api.reepay.com/v1/charge/' . $handle . '/cancel' );
		} catch ( Exception $e ) {
			throw new Exception( sprintf( 'API Error: %s', $e->getMessage() ) );
		}

		update_post_meta($order->get_id(), '_reepay_cancel_transaction', $result['transaction'] );
		update_post_meta($order->get_id(), '_transaction_id', $result['transaction']);

		if ( ! $order->has_status('cancelled') ) {
			$order->update_status( 'cancelled', __( 'Transaction cancelled.', 'woocommerce-gateway-reepay-checkout' ) );
		} else {
			$order->add_order_note( __( 'Transaction cancelled.', 'woocommerce-gateway-reepay-checkout' ) );
		}
	}

	/**
	 * Refund
	 *
	 * @param WC_Order|int $order
	 * @param bool         $amount
	 * @param string       $reason
	 *
	 * @throws \Exception
	 * @return void
	 */
	public function refund_payment( $order, $amount = FALSE, $reason = '' ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $this->can_refund( $order, $amount ) ) {
			throw new Exception( 'Payment can\'t be refunded.' );
		}

		$handle = $this->get_order_handle( $order );
		if ( empty( $handle ) ) {
			throw new Exception( 'Unable to get order handle' );
		}

		try {
			$params = [
				'invoice' => $handle,
				'amount' => $amount * 100,
			];
			$result = $this->request( 'POST', 'https://api.reepay.com/v1/refund', $params );
		} catch ( Exception $e ) {
			throw new Exception( sprintf( 'API Error: %s', $e->getMessage() ) );
		}

		// Save Credit Note ID
		$credit_note_ids = get_post_meta( $order->get_id(), '_reepay_credit_note_ids', TRUE );
		if ( ! is_array( $credit_note_ids ) ) {
			$credit_note_ids = array();
		}

		$credit_note_ids = array_merge( $credit_note_ids, $result['credit_note_id'] );
		update_post_meta( $order->get_id(), '_reepay_credit_note_ids', $credit_note_ids );

		$order->add_order_note(
			sprintf( __( 'Refunded: %s. Credit Note Id #%s. Reason: %s', 'woocommerce-gateway-reepay-checkout' ),
				wc_price( $amount ),
				$result['credit_note_id'],
				$reason
			)
		);
	}

	/**
	 * Assign payment token to order.
	 *
	 * @param WC_Order $order
	 * @param WC_Payment_Token_Reepay|int $token
	 *
	 * @return void
	 *
	 * @throws Exception
	 */
	public static function assign_payment_token( $order, $token ) {
		if ( is_numeric( $token ) ) {
			$token = new WC_Payment_Token_Reepay( $token );
		} elseif ( ! $token instanceof WC_Payment_Token_Reepay ) {
			throw new Exception( 'Invalid token parameter' );
		}

		if ( $token->get_id() ) {
			// Delete tokens if exist
			delete_post_meta( $order->get_id(), '_payment_tokens' );

			// Reload order
			$order = wc_get_order( $order->get_id() );

			// Add payment token
			$order->add_payment_token( $token );

			update_post_meta( $order->get_id(), '_reepay_token_id', $token->get_id() );
			update_post_meta( $order->get_id(), '_reepay_token', $token->get_token() );
		}
	}

	/**
	 * Save Payment Token
	 *
	 * @param WC_Order $order
	 * @param string $reepay_token
	 *
	 * @return bool|WC_Payment_Token_Reepay
	 *
	 * @throws Exception
	 */
	protected function reepay_save_token( $order, $reepay_token )
	{
		// Check if token is exists in WooCommerce
		$token = self::get_payment_token( $reepay_token );
		if ( ! $token ) {
			// Create Payment Token
			$token = $this->add_payment_token( $order, $reepay_token );
		}

		// Assign token to order
		self::assign_payment_token( $order, $token );

		return $token;
	}

	/**
	 * Add Payment Token.
	 *
	 * @param WC_Order $order
	 * @param string $reepay_token
	 *
	 * @return bool|WC_Payment_Token_Reepay
	 * @throws Exception
	 */
	public function add_payment_token( $order, $reepay_token ) {
		// Create Payment Token
		$customer_handle = $this->get_customer_handle( $order->get_user_id() );
		$source = $this->get_reepay_cards( $customer_handle, $reepay_token );
		if ( ! $source ) {
			throw new Exception('Unable to retrieve customer payment methods');
		}

		$expiryDate = explode( '-', $source['exp_date'] );

		// Initialize Token
		$token = new WC_Payment_Token_Reepay();
		$token->set_gateway_id( $this->id );
		$token->set_token( $reepay_token );
		$token->set_last4( substr( $source['masked_card'], -4 ) );
		$token->set_expiry_year( 2000 + $expiryDate[1] );
		$token->set_expiry_month( $expiryDate[0] );
		$token->set_card_type( $source['card_type'] );
		$token->set_user_id( $order->get_customer_id() );
		$token->set_masked_card( $source['masked_card'] );

		// Save Credit Card
		if ( ! $token->save() ) {
			throw new Exception( __( 'There was a problem adding the card.', 'woocommerce-gateway-reepay-checkout' ) );
		}

		update_post_meta( $order->get_id(), '_reepay_source', $source );
		$this->log( sprintf( '%s::%s Payment token #%s created for %s',
			__CLASS__,
			__METHOD__,
			$token->get_id(),
			$source['masked_card']
		) );

		return $token;
	}

	/**
	 * Get payment token.
	 * @deprecated
	 *
	 * @param WC_Order $order
	 *
	 * @return WC_Payment_Token_Reepay|false
	 */
	public static function retrieve_payment_token_order( $order ) {
		try {
			$tokens = $order->get_payment_tokens();
		} catch ( Exception $e ) {
			return false;
		}

		foreach ( $tokens as $token_id ) {
			try {
				$token = new WC_Payment_Token_Reepay( $token_id );
			} catch ( Exception $e ) {
				return false;
			}

			if ( ! $token->get_id() ) {
				continue;
			}

			if ( $token->get_gateway_id() !== 'reepay_checkout' ) {
				continue;
			}

			return $token;
		}

		return false;
	}

	/**
	 * Get payment token.
	 *
	 * @param WC_Order $order
	 *
	 * @return WC_Payment_Token_Reepay|false
	 */
	public static function get_payment_token_order( $order ) {
		$reepay_token = get_post_meta( $order->get_id(), '_reepay_token', true );
		if ( empty( $reepay_token ) ) {
			return false;
		}

		return self::get_payment_token( $reepay_token );
	}

	/**
	 * Get Payment Token by Token string.
	 *
	 * @param string $reepay_token
	 *
	 * @return WC_Payment_Token_Reepay|false
	 */
	public static function get_payment_token( $reepay_token ) {
		global $wpdb;

		$query = "SELECT token_id FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE token = '%s';";
		$token_id = $wpdb->get_var( $wpdb->prepare( $query, $reepay_token ) );
		if ( ! $token_id ) {
			return false;
		}

		return WC_Payment_Tokens::get( $token_id );
	}

	/**
	 * Request
	 * @param $method
	 * @param $url
	 * @param array $params
	 * @return array|mixed|object
	 * @throws Exception
	 */
	public function request($method, $url, $params = array()) {
		$start = microtime(true);
		if ( $this->debug === 'yes' ) {
			$this->log( sprintf('Request: %s %s %s', $method, $url, json_encode( $params, JSON_PRETTY_PRINT ) ) );
		}

		$key = $this->test_mode === 'yes' ? $this->private_key_test : $this->private_key;

		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			//CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_TIMEOUT        => 60
		]);

		$headers = [
			'Accept: application/json',
			'Content-Type: application/json'
		];
		curl_setopt_array($curl, [
			CURLOPT_USERAGENT     => 'curl',
			CURLOPT_HTTPHEADER    => $headers,
			CURLOPT_URL           => $url,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_USERPWD => "$key:"
		]);

		if (count($params) > 0) {
			$data      = json_encode($params, JSON_PRETTY_PRINT);
			$headers[] = 'Content-Length: ' . strlen($data);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
			curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		}
		$body = curl_exec($curl);
		$info = curl_getinfo($curl);
		$code = (int) ($info['http_code'] / 100);

		if ( $this->debug === 'yes' ) {
			$time = microtime(true) - $start;
			$this->log( sprintf( '[%.4F] HTTP Code: %s. Response: %s', $time, $info['http_code'], $body ) );
		}

		switch ($code) {
			case 0:
				$error = curl_error($curl);
				$errno = curl_errno($curl);
				throw new Exception(sprintf('Error: %s. Code: %s.', $error, $errno));
			case 1:
				throw new Exception(sprintf('Invalid HTTP Code: %s', $info['http_code']));
			case 2:
			case 3:
				return json_decode($body, true);
			case 4:
			case 5:
				if ( mb_strpos( $body, 'Request rate limit exceeded', 0, 'UTF-8' ) !== false ) {
					global $request_retry;
					if ($request_retry) {
						throw new Exception( 'Reepay: Request rate limit exceeded' );
					}

					sleep(10);
					$request_retry = true;
					$result = $this->request($method, $url, $params);
					$request_retry = false;

					return  $result;
				}

				throw new Exception(sprintf('API Error: %s. HTTP Code: %s', $body, $info['http_code']));
			default:
				throw new Exception(sprintf('Invalid HTTP Code: %s', $info['http_code']));
		}
	}

	/**
	 * Logging method.
	 *
	 * @param string $message Log message.
	 * @param string $level   Optional. Default 'info'.
	 *     emergency|alert|critical|error|warning|notice|info|debug
	 *
	 * @see WC_Log_Levels
	 *
	 * @return void
	 */
	protected function log( $message, $level = 'info' ) {
		// Is Enabled
		if ( $this->debug !== 'yes' ) {
			return;
		}

		// Get Logger instance
		$logger = wc_get_logger();

		// Write message to log
		if ( ! is_string( $message ) ) {
			$message = var_export( $message, TRUE );
		}

		$logger->log( $level, $message, array(
			'source'  => $this->id,
			'_legacy' => TRUE
		) );
	}

	/**
	 * Process Refund
	 *
	 * If the gateway declares 'refunds' support, this will allow it to refund
	 * a passed in amount.
	 *
	 * @param  int    $order_id
	 * @param  float  $amount
	 * @param  string $reason
	 *
	 * @return  bool|wp_error True or false based on success, or a WP_Error object
	 */
	public function process_refund( $order_id, $amount = NULL, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return FALSE;
		}

		// Full Refund
		if ( is_null( $amount ) ) {
			$amount = $order->get_total();
		}

		try {
			$this->refund_payment( $order, $amount, $reason );

			return TRUE;
		} catch ( \Exception $e ) {
			return new WP_Error( 'refund', $e->getMessage() );
		}
	}

	/**
	 * @param WC_Order $order
	 *
	 * @return array
	 */
	public function get_order_items($order) {
		$pricesIncludeTax = wc_prices_include_tax();

		$items = [];
		foreach ( $order->get_items() as $order_item ) {
			/** @var WC_Order_Item_Product $order_item */
			$price        = $order->get_line_subtotal( $order_item, FALSE, FALSE );
			$priceWithTax = $order->get_line_subtotal( $order_item, TRUE, FALSE );
			$tax          = $priceWithTax - $price;
			$taxPercent   = ( $tax > 0 ) ? round( 100 / ( $price / $tax ) ) : 0;
			$unitPrice    = round( ( $pricesIncludeTax ? $priceWithTax : $price ) / $order_item->get_quantity(), 2 );

			$items[] = array(
				'ordertext' => $order_item->get_name(),
				'quantity'  => $order_item->get_quantity(),
				'amount'    => round(100 * $unitPrice),
				'vat'       => round($taxPercent / 100, 2),
				'amount_incl_vat' => $pricesIncludeTax
			);
		}

		// Add Shipping Line
		if ( (float) $order->get_shipping_total() > 0 ) {
			$shipping        = $order->get_shipping_total();
			$tax             = $order->get_shipping_tax();
			$shippingWithTax = $shipping + $tax;
			$taxPercent      = ( $tax > 0 ) ? round( 100 / ( $shipping / $tax ) ) : 0;

			$items[] = array(
				'ordertext' => $order->get_shipping_method(),
				'quantity'  => 1,
				'amount'    => round(100 * ( $pricesIncludeTax ? $shippingWithTax : $shipping ) ),
				'vat'       => round($taxPercent / 100, 2),
				'amount_incl_vat' => $pricesIncludeTax
			);
		}

		// Add fee lines
		foreach ( $order->get_fees() as $order_fee ) {
			/** @var WC_Order_Item_Fee $order_fee */
			$fee        = $order_fee->get_total();
			$tax        = $order_fee->get_total_tax();
			$feeWithTax = $fee + $tax;
			$taxPercent = ( $tax > 0 ) ? round( 100 / ( $fee / $tax ) ) : 0;

			$items[] = array(
				'ordertext' => $order_fee->get_name(),
				'quantity'  => 1,
				'amount'    => round(100 * ( $pricesIncludeTax ? $feeWithTax : $fee ) ),
				'vat'       => round($taxPercent / 100, 2),
				'amount_incl_vat' => $pricesIncludeTax
			);
		}

		// Add discount line
		if ( $order->get_total_discount( FALSE ) > 0 ) {
			$discount        = $order->get_total_discount( TRUE );
			$discountWithTax = $order->get_total_discount( FALSE );
			$tax             = $discountWithTax - $discount;
			$taxPercent      = ( $tax > 0 ) ? round( 100 / ( $discount / $tax ) ) : 0;

			$items[] = array(
				'ordertext' => __( 'Discount', 'woocommerce-gateway-reepay-checkout' ),
				'quantity'  => 1,
				'amount'    => round(-100 * ( $pricesIncludeTax ? $discountWithTax : $discount ) ),
				'vat'       => round($taxPercent / 100, 2),
				'amount_incl_vat' => $pricesIncludeTax
			);
		}

		return $items;
	}

	/**
	 * Create Charge Session
	 * @param $order
	 * @return array|mixed|object
	 * @throws Exception
	 */
	public function create_charge_session($order) {
		/** @var WC_Order $order */
		$params = [
			'settle' => ($this->settle == 'yes'),
			'order' => [
				'handle' => uniqid($order->get_id()),
				'generate_handle' => false,
				'amount' => round(100 * $order->get_total()),
				'currency' => $order->get_currency(),
				'customer' => [
					'test' => $this->test_mode === 'yes',
					'handle' => uniqid('customer'),
					'email' => $order->get_billing_email(),
					'address' => $order->get_billing_address_1(),
					'address2' => $order->get_billing_address_2(),
					'city' => $order->get_billing_city(),
					'country' => $order->get_billing_country(),
					'phone' => $order->get_billing_phone(),
					'company' => $order->get_billing_company(),
					'vat' => '',
					'first_name' => $order->get_billing_first_name(),
					'last_name' => $order->get_billing_last_name(),
					'postal_code' => $order->get_billing_postcode()
				]
			],
			'recurring' => false, // @todo recurring
			// If set a recurring payment method is stored for the customer and a reference returned.
			'accept_url' => $this->get_return_url(),
			'cancel_url' => $order->get_cancel_order_url_raw()
		];

		try {
			$result = $this->request('POST', 'https://checkout-api.reepay.com/v1/session/charge', $params);
		} catch (Exception $e) {
			throw $e;
		}

		return $result;
	}

	/**
	 * Create Charge Session
	 * @param $order
	 * @return array|mixed|object
	 * @throws Exception
	 */
	public function create_recurring_session($order) {
		/** @var WC_Order $order */
		$params = [
			'settle' => ($this->settle == 'yes'),
			'create_customer' => [
				'handle' => uniqid('customer'),
				'generate_handle' => false,
				'test' => $this->test_mode === 'yes',
				'email' => $order->get_billing_email(),
				'address' => $order->get_billing_address_1(),
				'address2' => $order->get_billing_address_2(),
				'city' => $order->get_billing_city(),
				'country' => $order->get_billing_country(),
				'phone' => $order->get_billing_phone(),
				'company' => $order->get_billing_company(),
				'vat' => '',
				'first_name' => $order->get_billing_first_name(),
				'last_name' => $order->get_billing_last_name(),
				'postal_code' => $order->get_billing_postcode()
			],

			'accept_url' => $this->get_return_url(),
			'cancel_url' => $order->get_cancel_order_url_raw()
		];

		try {
			$result = $this->request('POST', 'https://checkout-api.reepay.com/v1/session/recurring', $params);
		} catch (Exception $e) {
			throw $e;
		}

		return $result;
	}

	/**
	 * Get Customer Cards from Reepay
	 * @param string $customer_handle
	 * @param string|null $reepay_token
	 *
	 * @return array|false
	 * @throws Exception
	 */
	public function get_reepay_cards($customer_handle, $reepay_token = null) {
		$result = $this->request( 'GET', 'https://api.reepay.com/v1/customer/' . $customer_handle . '/payment_method' );
		if ( ! isset( $result['cards'] ) ) {
			throw new Exception('Unable to retrieve customer payment methods');
		}

		if ( ! $reepay_token ) {
			return $result['cards'];
		}

		$cards = $result['cards'];
		foreach ($cards as $card) {
			if ( $card['id'] === $reepay_token && $card['state'] === 'active' ) {
				return $card;
			}
		}

		return false;
	}

	/**
	 * Update Order Status
	 * @param $order
	 * @param $new_status
	 * @param string $note
	 * @param bool $manual
	 */
	public static function update_order_status($order, $new_status, $note = '', $manual = false ) {
		if ( ! $order instanceof WC_Abstract_Order ) {
			$order = wc_get_order( $order );
		}

		// Disable status change hook
		remove_action( 'woocommerce_order_status_changed', 'WC_ReepayCheckout::order_status_changed', 10 );

		// Update status
		$order->update_status( $new_status, $note, $manual );

		// Enable status change hook
		add_action( 'woocommerce_order_status_changed', 'WC_ReepayCheckout::order_status_changed', 10, 4 );
	}

	/**
	 * Set Authorized Status
	 * @param WC_Order $order
	 */
	public function set_authorized_status($order)
	{
		$status = apply_filters( 'reepay_authorized_order_status', 'on-hold', $order->get_id(), $order );
		self::update_order_status( $order, $status, __( 'Payment authorized.', 'woocommerce-gateway-reepay-checkout' ) );
	}

	/**
	 * Checks an order to see if it contains a subscription.
	 * @see wcs_order_contains_subscription()
	 *
	 * @param WC_Order $order
	 *
	 * @return bool
	 */
	public static function order_contains_subscription( $order ) {
		if ( ! function_exists( 'wcs_order_contains_subscription' ) ) {
			return FALSE;
		}

		return wcs_order_contains_subscription( $order );
	}

	/**
	 * WC Subscriptions: Is Payment Change
	 * @return bool
	 */
	public static function wcs_is_payment_change() {
		return class_exists( 'WC_Subscriptions_Change_Payment_Gateway', FALSE ) &&
			   WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
	}

	/**
	 * Check is Cart have Subscription Products
	 * @return bool
	 */
	public static function wcs_cart_have_subscription() {
		if ( ! class_exists( 'WC_Product_Subscription', FALSE ) ) {
			return FALSE;
		}

		// Check is Recurring Payment
		$cart = WC()->cart->get_cart();
		foreach ( $cart as $key => $item ) {
			if ( is_object( $item['data'] ) && get_class( $item['data'] ) === 'WC_Product_Subscription' ) {
				return TRUE;
			}
		}

		return FALSE;
	}

	/**
	 * @param $user_id
	 *
	 * @return mixed|string
	 */
	public function get_customer_handle( $user_id ) {
		if ( ! $user_id ) {
			if ( session_status() === PHP_SESSION_NONE ) {
				session_start();
			}

			// Workaround: Allow to pay exist orders by guests
			if ( isset( $_GET['pay_for_order'], $_GET['key'] ) ) {
				if ( $order_id = wc_get_order_id_by_order_key( $_GET['key'] ) ) {
					$order = wc_get_order( $order_id );

					// Get customer handle by order
	                $handle = $this->get_order_handle( $order );

					$result = get_transient( 'reepay_invoice_' . $handle );
					if ( ! $result ) {
						try {
							$result = $this->request( 'GET', 'https://api.reepay.com/v1/invoice/' . wc_clean( $handle ) );
							set_transient( 'reepay_invoice_' . $handle, $result, 5 * MINUTE_IN_SECONDS );
						} catch (Exception $e) {
							//
						}
					}

					if ( is_array( $result ) && isset( $result['customer'] ) ) {
						$guest_id = $result['customer'];
						//WC()->session->set( 'reepay_guest', $guest_id );
						$_SESSION['reepay_guest1'] = $guest_id;
					}
				}
            }

			$guest_id = isset( $_SESSION['reepay_guest1'] ) ? $_SESSION['reepay_guest1'] : null;
			if ( empty( $guest_id ) ) {
				$guest_id = 'guest-' . wp_generate_password(12, false);
				$_SESSION['reepay_guest1'] = $guest_id;
			}

			return $guest_id;
		}

		$handle = get_user_meta( $user_id, 'reepay_customer_id', TRUE );
		if ( empty( $handle ) ) {
			$handle = 'customer-' . $user_id;
			update_user_meta( $user_id, 'reepay_customer_id', $handle );
		}

		return $handle;
	}

	/**
	 * @param $handle
	 *
	 * @return bool|int
	 */
	public function get_userid_by_handle( $handle ) {
		if ( strpos( $handle, 'guest-' ) !== false ) {
			return 0;
		}

		$users = get_users( array(
			'meta_key' => 'reepay_customer_id',
			'meta_value' => $handle,
			'number' => 1,
			'count_total' => false
		) );
		if ( count( $users ) > 0 ) {
			$user = array_shift( $users );
			return $user->ID;
		}

		return false;
	}

	/**
	 * Get Reepay Order Handle.
	 *
	 * @param WC_Order $order
	 *
	 * @return string
	 */
	public function get_order_handle( $order ) {
		return apply_filters( 'reepay_order_handle', null, $order->get_id(), $order );
	}

	/**
	 * Get Order Id by Reepay Order Handle.
	 *
	 * @param $handle
	 *
	 * @return bool|string|null
	 */
	public function get_orderid_by_handle( $handle ) {
		return apply_filters( 'reepay_get_order', null, $handle );
	}

	/**
	 * Get Order By Reepay Order Handle.
	 * @param $handle
	 *
	 * @return false|WC_Order
	 * @throws Exception
	 */
	public function get_order_by_handle( $handle ) {
		// Get Order by handle
		$order_id = $this->get_orderid_by_handle( $handle );
		if ( ! $order_id ) {
			throw new Exception( sprintf( 'Invoice #%s isn\'t exists in store.', $handle ) );
		}

		// Get Order
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			throw new Exception( sprintf( 'Order #%s isn\'t exists in store.', $order_id ) );
		}

		return $order;
	}

	/**
	 * Get Language
	 * @return string
	 */
	public function get_language() {
		if ( ! empty( $this->language ) ) {
			return $this->language;
		}

		$locale = get_locale();
		if ( in_array(
			$locale,
			array('en_US', 'da_DK', 'sv_SE', 'no_NO', 'de_DE', 'es_ES', 'fr_FR', 'it_IT', 'nl_NL')
		) ) {
			return $locale;
		}

		return 'en_US';
	}
}
