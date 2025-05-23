<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package FacebookCommerce
 */

namespace WooCommerce\Facebook;

defined( 'ABSPATH' ) || exit;

/**
 * The checkout permalink.
 *
 * @since 3.3.0
 */
class Checkout {

	/**
	 * Checkout constructor.
	 *
	 * @since 3.3.0
	 */
	public function __construct() {
		$this->add_hooks();
	}

	/**
	 * Adds the necessary action and filter hooks.
	 *
	 * @since 3.3.0
	 */
	public function add_hooks() {
		add_action( 'init', array( $this, 'add_checkout_permalink_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'add_checkout_permalink_query_var' ) );
		add_filter( 'template_include', array( $this, 'load_checkout_permalink_template' ) );

		register_activation_hook( __FILE__, array( $this, 'flush_rewrite_rules_on_activation' ) );
		register_deactivation_hook( __FILE__, array( $this, 'flush_rewrite_rules_on_deactivation' ) );
	}

	/**
	 * Adds a rewrite rule for the checkout permalink.
	 *
	 * @since 3.3.0
	 */
	public function add_checkout_permalink_rewrite_rule() {
		add_rewrite_rule( '^fb-checkout/?$', 'index.php?fb_checkout=1', 'top' );
	}

	/**
	 * Adds query vars for the checkout permalink.
	 *
	 * @since 3.3.0
	 *
	 * @param array $vars
	 * @return array
	 */
	public function add_checkout_permalink_query_var( $vars ) {
		$vars[] = 'fb_checkout';
		$vars[] = 'products';
		$vars[] = 'coupon';

		return $vars;
	}

	/**
	 * Loads the checkout permalink template.
	 *
	 * @since 3.3.0
	 *
	 * @param string $template
	 * @return string
	 */
	public function load_checkout_permalink_template( $template ) {
		if ( get_query_var( 'fb_checkout' ) ) {
			WC()->cart->empty_cart();

			$products_param = get_query_var( 'products' );
			if ( $products_param ) {
				$products = explode( ',', $products_param );

				foreach ( $products as $product ) {
					list($product_id, $quantity) = explode( ':', $product );

					// Parse the product ID. The input is sent in the Retailer ID format (see get_fb_retailer_id())
					// The Retailer ID format is: {product_sku}_{product_id}, so we need to extract the product_id
					if ( false !== strpos( $product_id, '_' ) ) {
						$parts      = explode( '_', $product_id );
						$product_id = end( $parts );
					}

					// Validate and add the product to the cart
					if ( is_numeric( $product_id ) && is_numeric( $quantity ) && $quantity > 0 ) {
						try {
							WC()->cart->add_to_cart( $product_id, $quantity );
						} catch ( \Exception $e ) {
							\WC_Facebookcommerce_Utils::log_exception_immediately_to_meta(
								$e,
								array(
									'event'           => 'checkout',
									'event_type'      => 'checkout_permalink_template_exception',
									'incoming_params' => array(
										'products_param' => $products_param,
										'product_id'     => $product_id,
									),
								)
							);
						}
					} else {
						\WC_Facebookcommerce_Utils::log_to_meta(
							'Failed to add product to cart',
							array(
								'flow_name'       => 'checkout',
								'incoming_params' => array(
									'products_param' => $products_param,
									'product_id'     => $product_id,
								),
							)
						);
					}
				}
			}

			$coupon_code = get_query_var( 'coupon' );
			if ( $coupon_code ) {
				WC()->cart->apply_coupon( sanitize_text_field( $coupon_code ) );
			}

			$checkout_url = wc_get_checkout_url();
			echo '<!DOCTYPE html>
				<html lang="en">
				<head>
						<meta charset="UTF-8">
						<meta name="viewport" content="width=device-width, initial-scale=1">
						<title>Checkout</title>
						<style>
								body, html {
										margin: 0;
										padding: 0;
										height: 100%;
										overflow: hidden;
								}
								iframe {
										width: 100%;
										height: 100vh;
										border: none;
										display: block;
										max-width: 100%;
										max-height: 100%;
										box-sizing: border-box;
								}
						</style>
				</head>
				<body>
						<iframe src="' . esc_url( $checkout_url ) . '"></iframe>
				</body>
				</html>';

			exit;
		}

		return $template;
	}

	/**
	 * Flushes rewrite rules when the plugin is activated.
	 *
	 * @since 3.3.0
	 */
	public function flush_rewrite_rules_on_activation() {
		$this->add_checkout_permalink_rewrite_rule();
		flush_rewrite_rules();
	}

	/**
	 * Flushes rewrite rules when the plugin is deactivated.
	 *
	 * @since 3.3.0
	 */
	public function flush_rewrite_rules_on_deactivation() {
		flush_rewrite_rules();
	}
}
