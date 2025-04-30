<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Products\Sync;

defined( 'ABSPATH' ) || exit;

use WooCommerce\Facebook\Framework\Api\Exception as ApiException;
use WooCommerce\Facebook\Framework\Plugin\Exception as PluginException;
use WooCommerce\Facebook\Framework\Utilities\BackgroundJobHandler;
use WooCommerce\Facebook\Products;
use WooCommerce\Facebook\Products\Sync;

/**
 * The background sync handler.
 */
class Background extends BackgroundJobHandler {

	/** @var string async request prefix */
	protected $prefix = 'wc_facebook';

	/** @var string async request action */
	protected $action = 'background_product_sync';

	/** @var string data key */
	protected $data_key = 'requests';

	/**
	 * Processes a job.
	 *
	 * @since 2.0.0
	 *
	 * @param \stdClass|object $job
	 * @param int|null         $items_per_batch number of items to process in a single request (defaults to null for unlimited)
	 * @throws \Exception When job data is incorrect.
	 * @return \stdClass $job
	 */
	public function process_job( $job, $items_per_batch = null ) {
		$profiling_logger = facebook_for_woocommerce()->get_profiling_logger();
		$profiling_logger->start( 'background_product_sync__process_job' );

		if ( ! $this->start_time ) {
			$this->start_time = time();
		}

		// Indicate that the job has started processing
		if ( 'processing' !== $job->status ) {

			$job->status                = 'processing';
			$job->started_processing_at = current_time( 'mysql' );

			$job = $this->update_job( $job );
		}

		$data_key = $this->data_key;

		if ( ! isset( $job->{$data_key} ) ) {
			/* translators: Placeholders: %s - user-friendly error message */
			throw new \Exception( sprintf( __( 'Job data key "%s" not set', 'facebook-for-woocommerce' ), $data_key ) );
		}

		if ( ! is_array( $job->{$data_key} ) ) {
			/* translators: Placeholders: %s - user-friendly error message */
			throw new \Exception( sprintf( __( 'Job data key "%s" is not an array', 'facebook-for-woocommerce' ), $data_key ) );
		}

		$data = $job->{$data_key};

		$job->total = count( $data );

		// progress indicates how many items have been processed, it
		// does NOT indicate the processed item key in any way
		if ( ! isset( $job->progress ) ) {
			$job->progress = 0;
		}

		// skip already processed items
		if ( $job->progress && ! empty( $data ) ) {
			$data = array_slice( $data, $job->progress, null, true );
		}

		// loop over unprocessed items and process them
		if ( ! empty( $data ) ) {
			$this->process_items( $job, $data, (int) $items_per_batch );
		}

		// complete current job
		if ( $job->progress >= count( $job->{$data_key} ) ) {
			$job = $this->complete_job( $job );
		}

		$profiling_logger->stop( 'background_product_sync__process_job' );

		return $job;
	}


	/**
	 * Processes multiple items.
	 *
	 * @since 2.0.0
	 *
	 * @param \stdClass|object $job
	 * @param array            $data
	 * @param int|null         $items_per_batch number of items to process in a single request (defaults to null for unlimited)
	 */
	public function process_items( $job, $data, $items_per_batch = null ) {
		$processed = 0;
		$requests  = [];

		foreach ( $data as $item_id => $method ) {
			try {
				$request = $this->process_item( [ $item_id, $method ], $job );
				if ( $request ) {
					$requests[] = $request;
				}
			} catch ( PluginException $e ) {
				facebook_for_woocommerce()->log( "Background sync error: {$e->getMessage()}" );
			}

			++$processed;
			++$job->progress;
			// update job progress
			$job = $this->update_job( $job );
			// job limits reached
			if ( ( $items_per_batch && $processed >= $items_per_batch ) || $this->time_exceeded() || $this->memory_exceeded() ) {
				break;
			}
		}

		// send item updates to Facebook and update the job with the returned array of batch handles
		if ( ! empty( $requests ) ) {
			try {
				$handles      = $this->send_item_updates( $requests );
				$job->handles = ! isset( $job->handles ) || ! is_array( $job->handles ) ? $handles : array_merge( $job->handles, $handles );
				$this->update_job( $job );
			} catch ( ApiException $e ) {
				/* translators: Placeholders: %1$s - <string  job ID, %2$s - <strong> error message */
				$message = sprintf( __( 'There was an error trying sync products using the Catalog Batch API for job %1$s: %2$s', 'facebook-for-woocommerce' ), $job->id, $e->getMessage() );
				facebook_for_woocommerce()->log( $message );
			}
		}
	}

	/**
	 * Processes a single item.
	 *
	 * @param mixed            $item
	 * @param object|\stdClass $job
	 * @return array|null
	 * @throws PluginException In case of invalid sync request method.
	 */
	public function process_item( $item, $job ) {
		list( $item_id, $method ) = $item;
		if ( ! in_array( $method, [ Sync::ACTION_UPDATE, Sync::ACTION_DELETE ], true ) ) {
			throw new PluginException( "Invalid sync request method: {$method}." );
		}

		if ( Sync::ACTION_UPDATE === $method ) {
			$request = $this->process_item_update( $item_id );
		} else {
			$request = $this->process_item_delete( $item_id );
		}
		return $request;
	}

	/**
	 * Processes an UPDATE sync request for the given product.
	 *
	 * @since 2.0.0
	 *
	 * @param string $prefixed_product_id prefixed product ID
	 * @return array|null
	 * @throws PluginException In case no product was found.
	 */
	private function process_item_update( $prefixed_product_id ) {
		$product_id = (int) str_replace( Sync::PRODUCT_INDEX_PREFIX, '', $prefixed_product_id );
		$product    = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			throw new PluginException( "No product found with ID equal to {$product_id}." );
		}

		// Perform a thorough attribute sync before updating the product
		$this->ensure_attributes_are_synced($product);

		$request = null;
		if ( ! Products::product_should_be_deleted( $product ) && Products::product_should_be_synced( $product ) ) {

			if ( $product->is_type( 'variation' ) ) {
				$product_data = \WC_Facebookcommerce_Utils::prepare_product_variation_data_items_batch( $product );
			} else {
				$product_data = \WC_Facebookcommerce_Utils::prepare_product_data_items_batch( $product );
			}

			// extract the retailer_id
			$retailer_id = $product_data['retailer_id'];

			// NB: Changing this to get items_batch to work
			// retailer_id cannot be included in the data object
			unset( $product_data['retailer_id'] );
			$product_data['id'] = $retailer_id;

			$request = [
				'method' => Sync::ACTION_UPDATE,
				'data'   => $product_data,
			];

			/**
			 * Filters the data that will be included in a UPDATE sync request.
			 *
			 * @since 2.0.0
			 *
			 * @param array $request request data
			 * @param \WC_Product $product product object
			 */
			$request = apply_filters( 'wc_facebook_sync_background_item_update_request', $request, $product );
		}

		return $request;
	}

	/**
	 * Processes a DELETE sync request for the given product.
	 *
	 * @param string $prefixed_retailer_id Product retailer ID.
	 */
	private function process_item_delete( $prefixed_retailer_id ) {
		$retailer_id = str_replace( Sync::PRODUCT_INDEX_PREFIX, '', $prefixed_retailer_id );
		$request     = [
			'data'   => [ 'id' => $retailer_id ],
			'method' => Sync::ACTION_DELETE,
		];

		/**
		 * Filters the data that will be included in a DELETE sync request.
		 *
		 * @since 2.0.0
		 *
		 * @param array $request request data
		 * @param string $retailer prefixed product retailer ID
		 */
		return apply_filters( 'wc_facebook_sync_background_item_delete_request', $request, $prefixed_retailer_id );
	}

	/**
	 * Sends item updates to Facebook.
	 *
	 * @param array $requests Array of JSON objects containing batch requests. Each batch request consists of method and data fields.
	 * @return array An array of handles.
	 * @throws ApiException In case of failed API request.
	 */
	private function send_item_updates( array $requests ): array {
		$facebook_catalog_id = facebook_for_woocommerce()->get_integration()->get_product_catalog_id();
		$response            = facebook_for_woocommerce()->get_api()->send_item_updates( $facebook_catalog_id, $requests );
		$response_handles    = $response->handles;
		$handles             = ( isset( $response_handles ) && is_array( $response_handles ) ) ? $response_handles : array();
		return $handles;
	}

	// Add a new method for syncing critical attributes if the admin instance is not available
	private function sync_critical_attributes( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$attributes = $product->get_attributes();
		
		// Map for critical dropdown attributes
		$critical_attributes = [
			'age_group' => [
				'meta_key' => \WC_Facebook_Product::FB_AGE_GROUP,
				'valid_values' => [
					\WC_Facebook_Product::AGE_GROUP_ADULT,
					\WC_Facebook_Product::AGE_GROUP_ALL_AGES,
					\WC_Facebook_Product::AGE_GROUP_TEEN,
					\WC_Facebook_Product::AGE_GROUP_KIDS,
					\WC_Facebook_Product::AGE_GROUP_TODDLER,
					\WC_Facebook_Product::AGE_GROUP_INFANT,
					\WC_Facebook_Product::AGE_GROUP_NEWBORN,
				],
			],
			'gender' => [
				'meta_key' => \WC_Facebook_Product::FB_GENDER,
				'valid_values' => [
					\WC_Facebook_Product::GENDER_MALE,
					\WC_Facebook_Product::GENDER_FEMALE,
					\WC_Facebook_Product::GENDER_UNISEX,
				],
			],
			'condition' => [
				'meta_key' => \WC_Facebook_Product::FB_PRODUCT_CONDITION,
				'valid_values' => [
					\WC_Facebook_Product::CONDITION_NEW,
					\WC_Facebook_Product::CONDITION_USED,
					\WC_Facebook_Product::CONDITION_REFURBISHED,
				],
			],
		];
		
		foreach ( $attributes as $attribute ) {
			// Extract attribute details
			$raw_name = $attribute->get_name();
			$clean_name = str_replace( 'pa_', '', $raw_name );
			$normalized_name = strtolower( $clean_name );
			$attribute_label = wc_attribute_label( $raw_name );
			$normalized_label = strtolower( $attribute_label );
			
			// Process each critical attribute type
			foreach ( $critical_attributes as $fb_attr_type => $attr_details ) {
				// Skip if this attribute doesn't match the current type
				if ( $normalized_name !== $fb_attr_type && $normalized_label !== $fb_attr_type && 
					 strpos( $normalized_name, $fb_attr_type ) === false && 
					 strpos( $normalized_label, $fb_attr_type ) === false ) {
					continue;
				}
				
				// Get attribute values
				$values = [];
				if ( $attribute->is_taxonomy() ) {
					$terms = $attribute->get_terms();
					if ( $terms && ! is_wp_error( $terms ) ) {
						$values = wp_list_pluck( $terms, 'name' );
					}
				} else {
					$values = $attribute->get_options();
				}
				
				if ( ! empty( $values ) ) {
					$valid_values = [];
					
					// Validate against allowed values
					foreach ( $values as $value ) {
						$normalized_value = strtolower( trim( $value ) );
						
						foreach ( $attr_details['valid_values'] as $allowed_value ) {
							if ( strtolower( $allowed_value ) === $normalized_value ) {
								$valid_values[] = $allowed_value;
								break;
							}
						}
					}
					
					if ( ! empty( $valid_values ) ) {
						$joined_values = implode( ' | ', $valid_values );
						update_post_meta( $product_id, $attr_details['meta_key'], $joined_values );
					} else {
						delete_post_meta( $product_id, $attr_details['meta_key'] );
					}
				}
				
				// We found and processed a match, no need to check this attribute against other types
				break;
			}
		}
	}

	/**
	 * Ensures all attributes are properly synced before product update
	 * This is a more comprehensive approach than the previous implementation
	 *
	 * @param \WC_Product $product Product object
	 */
	private function ensure_attributes_are_synced($product) {
		if (!$product) {
			return;
		}
		
		$product_id = $product->get_id();
		
		// Sync parent product attributes first if this is a variation
		if ($product->is_type('variation')) {
			$parent_id = $product->get_parent_id();
			if ($parent_id) {
				$parent_product = wc_get_product($parent_id);
				if ($parent_product) {
					$this->ensure_attributes_are_synced($parent_product);
				}
			}
		}
		
		// First try using the Admin instance if available
		$admin_synced = false;
		
		if (class_exists('\\WooCommerce\\Facebook\\Admin')) {
			$admin_instance = facebook_for_woocommerce()->admin;
			
			if ($admin_instance && method_exists($admin_instance, 'sync_product_attributes')) {
				$admin_instance->sync_product_attributes($product_id);
				$admin_synced = true;
			}
		}
		
		// If admin sync not available, use our basic sync
		if (!$admin_synced) {
			$this->sync_critical_attributes($product_id);
		}
		
		// Create a Facebook product object to refresh attribute meta values
		$fb_product = new \WC_Facebook_Product($product_id);
		if (method_exists($fb_product, 'refresh_attribute_meta_values')) {
			$fb_product->refresh_attribute_meta_values();
		}
		
		// Check for global attributes that might not be directly assigned to the product
		$this->sync_global_attributes($product_id);
	}
	
	/**
	 * Syncs global attributes that might not be directly assigned to the product
	 *
	 * @param int $product_id Product ID
	 */
	private function sync_global_attributes($product_id) {
		// Map of critical attributes to their meta keys
		$attribute_map = [
			'age_group' => \WC_Facebook_Product::FB_AGE_GROUP,
			'gender' => \WC_Facebook_Product::FB_GENDER,
			'condition' => \WC_Facebook_Product::FB_PRODUCT_CONDITION,
			'color' => \WC_Facebook_Product::FB_COLOR,
			'size' => \WC_Facebook_Product::FB_SIZE,
			'material' => \WC_Facebook_Product::FB_MATERIAL,
			'pattern' => \WC_Facebook_Product::FB_PATTERN,
		];
		
		// Get all attribute taxonomies
		$attribute_taxonomies = wc_get_attribute_taxonomies();
		
		if (!$attribute_taxonomies) {
			return;
		}
		
		foreach ($attribute_taxonomies as $tax) {
			$taxonomy_name = 'pa_' . $tax->attribute_name;
			$terms = get_the_terms($product_id, $taxonomy_name);
			
			if (!$terms || is_wp_error($terms)) {
				continue;
			}
			
			// Get attribute label for matching
			$attribute_label = $tax->attribute_label;
			$normalized_label = strtolower($attribute_label);
			$normalized_name = strtolower($tax->attribute_name);
			
			// Find matching Facebook field
			$matched_fb_field = null;
			$fb_field_name = null;
			
			foreach ($attribute_map as $fb_attr_name => $fb_meta_key) {
				if ($normalized_name === $fb_attr_name || 
					$normalized_label === $fb_attr_name || 
					strpos($normalized_name, $fb_attr_name) !== false || 
					strpos($normalized_label, $fb_attr_name) !== false) {
					
					$matched_fb_field = $fb_meta_key;
					$fb_field_name = $fb_attr_name;
					break;
				}
			}
			
			// If we found a matching field
			if ($matched_fb_field) {
				$values = wp_list_pluck($terms, 'name');
				
				if (!empty($values)) {
					$joined_values = implode(' | ', $values);
					update_post_meta($product_id, $matched_fb_field, $joined_values);
				}
			}
		}
	}
}
