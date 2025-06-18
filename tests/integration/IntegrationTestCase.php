<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Integration;

use WP_UnitTestCase;
use WC_Helper_Product;
use WC_Helper_Shipping;
use WC_Helper_Customer;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use WooCommerce\Facebook\Products;

/**
 * Base integration test case for Facebook for WooCommerce.
 *
 * Provides common functionality for integration tests including:
 * - WordPress/WooCommerce setup
 * - Product creation helpers
 * - Plugin state management
 * - Database transaction rollback
 */
abstract class IntegrationTestCase extends WP_UnitTestCase {

	/**
	 * Plugin instance
	 * @var \WC_Facebookcommerce
	 */
	protected $plugin;

	/**
	 * Integration instance
	 * @var \WC_Facebookcommerce_Integration  
	 */
	protected $integration;

	/**
	 * Set up before each test
	 */
	public function setUp(): void {
		parent::setUp();
		
		// Initialize plugin
		$this->plugin = facebook_for_woocommerce();
		$this->integration = $this->plugin->get_integration();
		
		// Reset plugin settings to defaults
		$this->reset_plugin_settings();
		
		// Clear any existing products
		$this->clear_products();
	}

	/**
	 * Tear down after each test
	 */
	public function tearDown(): void {
		// Clear any test data
		$this->clear_products();
		$this->reset_plugin_settings();
		
		parent::tearDown();
	}

	/**
	 * Reset plugin settings to defaults
	 */
	protected function reset_plugin_settings(): void {
		// Reset integration settings
		delete_option( 'woocommerce_facebookcommerce_settings' );
		delete_option( 'wc_facebook_external_business_id' );
		delete_option( 'wc_facebook_access_token' );
		delete_option( 'wc_facebook_product_catalog_id' );
		delete_option( 'wc_facebook_pixel_id' );
		
		// Clear transients
		delete_transient( 'wc_facebook_connection_invalid' );
		delete_transient( '_wc_facebook_for_woocommerce_refresh_business_configuration' );
	}

	/**
	 * Clear all products from database
	 */
	protected function clear_products(): void {
		global $wpdb;
		
		// Get all product IDs
		$product_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation')" );
		
		// Delete products
		foreach ( $product_ids as $product_id ) {
			wp_delete_post( $product_id, true );
		}
		
		// Clear any product meta
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation'))" );
	}

	/**
	 * Create a simple test product
	 */
	protected function create_simple_product( array $args = [] ): WC_Product {
		$defaults = [
			'name' => 'Test Product',
			'regular_price' => '10.00',
			'sku' => 'test-product-' . uniqid(),
			'manage_stock' => true,
			'stock_quantity' => 100,
			'status' => 'publish',
			'catalog_visibility' => 'visible',
		];
		
		$args = array_merge( $defaults, $args );
		
		$product = WC_Helper_Product::create_simple_product();
		
		foreach ( $args as $key => $value ) {
			$setter = "set_{$key}";
			if ( method_exists( $product, $setter ) ) {
				$product->$setter( $value );
			}
		}
		
		$product->save();
		
		return $product;
	}

	/**
	 * Create a variable product with variations
	 */
	protected function create_variable_product( array $attributes = [], array $variations = [] ): WC_Product_Variable {
		$product = WC_Helper_Product::create_variation_product();
		
		if ( ! empty( $attributes ) ) {
			$product->set_attributes( $attributes );
		}
		
		$product->save();
		
		// Create variations if provided
		foreach ( $variations as $variation_data ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( $variation_data['attributes'] ?? [] );
			$variation->set_regular_price( $variation_data['price'] ?? '10.00' );
			$variation->set_status( 'publish' );
			$variation->save();
		}
		
		return $product;
	}

	/**
	 * Create a test category
	 */
	protected function create_category( string $name, int $parent_id = 0 ): \WP_Term {
		$result = wp_insert_term( $name, 'product_cat', [
			'parent' => $parent_id
		] );
		
		if ( is_wp_error( $result ) ) {
			$this->fail( 'Failed to create category: ' . $result->get_error_message() );
		}
		
		return get_term( $result['term_id'], 'product_cat' );
	}

	/**
	 * Enable Facebook sync for the plugin
	 */
	protected function enable_facebook_sync(): void {
		$this->integration->update_option( 'is_enabled', 'yes' );
		$this->integration->update_option( 'sync_enabled', 'yes' );
		$this->integration->update_option( 'product_sync_enabled', 'yes' );
	}

	/**
	 * Disable Facebook sync for the plugin
	 */
	protected function disable_facebook_sync(): void {
		$this->integration->update_option( 'is_enabled', 'no' );
		$this->integration->update_option( 'sync_enabled', 'no' );
		$this->integration->update_option( 'product_sync_enabled', 'no' );
	}

	/**
	 * Set excluded categories
	 */
	protected function set_excluded_categories( array $category_ids ): void {
		$this->integration->update_option( 'excluded_product_category_ids', $category_ids );
	}

	/**
	 * Set excluded tags  
	 */
	protected function set_excluded_tags( array $tag_ids ): void {
		$this->integration->update_option( 'excluded_product_tag_ids', $tag_ids );
	}

	/**
	 * Enable product sync for a specific product
	 */
	protected function enable_product_sync( WC_Product $product ): void {
		$product->update_meta_data( Products::get_product_sync_meta_key(), 'yes' );
		$product->save_meta_data();
	}

	/**
	 * Disable product sync for a specific product
	 */
	protected function disable_product_sync( WC_Product $product ): void {
		$product->update_meta_data( Products::get_product_sync_meta_key(), 'no' );
		$product->save_meta_data();
	}

	/**
	 * Assert that a product should be synced
	 */
	protected function assertProductShouldSync( WC_Product $product, string $message = '' ): void {
		$should_sync = Products::product_should_be_synced( $product );
		$this->assertTrue( $should_sync, $message ?: "Product {$product->get_id()} should be synced to Facebook" );
	}

	/**
	 * Assert that a product should not be synced
	 */
	protected function assertProductShouldNotSync( WC_Product $product, string $message = '' ): void {
		$should_sync = Products::product_should_be_synced( $product );
		$this->assertFalse( $should_sync, $message ?: "Product {$product->get_id()} should not be synced to Facebook" );
	}

	/**
	 * Assert that a product should be deleted from Facebook
	 */
	protected function assertProductShouldBeDeleted( WC_Product $product, string $message = '' ): void {
		$should_delete = Products::product_should_be_deleted( $product );
		$this->assertTrue( $should_delete, $message ?: "Product {$product->get_id()} should be deleted from Facebook" );
	}
} 