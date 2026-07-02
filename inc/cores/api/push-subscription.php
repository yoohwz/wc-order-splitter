<?php

if (!defined('ABSPATH')) {
	exit;
}

class WC_Order_Splitter_Push_Subscription {
	/** REST endpoint of your central subscriptions service */
	const SUBSCRIBE_API_ENDPOINT = 'https://yoexpress.top/wp-json/yo-pr/v1/email-subscriptions';

	/**
	 * Gather site info and POST to Yo Email Subscriptions.
	 */
	public static function push_subscription() {
		// 1) Collect and dedupe emails
		$emails = [];

		// site admin email
		$emails[] = get_option( 'admin_email' );

		if ( $rec2 = get_option( 'woocommerce_new_order_recipient', '' ) ) {
			$emails = array_merge( $emails, array_map( 'trim', explode( ',', $rec2 ) ) );
		}

		// sanitize & keep only valid, unique
		$emails = array_filter( array_map( 'sanitize_email', $emails ), 'is_email' );
		$emails = array_unique( $emails );
		$email_param = implode( ',', $emails );

		// 2) Source = bare domain
		$host = parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '#^www\.#i', '', $host );

		// 3) Product code
		$products = [ 'wcos' ];

		// 4) Country
		$country = get_option( 'woocommerce_default_country', '' );

		// 5) Fire the request
		$args = [
			'headers' => [
				'Content-Type' => 'application/json; charset=utf-8'
			],
			'body'    => wp_json_encode( [
				'email'    => $email_param,
				'source'   => $host,
				'products' => $products,
				'country'  => $country,
			] ),
			'timeout' => 15,
		];

		$response = wp_remote_post( self::SUBSCRIBE_API_ENDPOINT, $args );

		if ( is_wp_error( $response ) ) {
			error_log( 'WC Order Splitter subscription error: ' . $response->get_error_message() );
		}
	}
}
