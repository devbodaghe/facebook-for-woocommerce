<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package FacebookCommerce
 */

namespace WooCommerce\Facebook\Framework;

use WC_Facebookcommerce_Utils;
use WooCommerce\Facebook\Utilities\Heartbeat;

defined( 'ABSPATH' ) || exit;


/**
 * The BatchLog handler.
 *
 * @since 3.5.0
 */
class BatchLogHandler {

	/**
	 * Constructs a new BatchLog handler.
	 *
	 * @since 3.5.0
	 */
	public function __construct() {
		add_action( Heartbeat::EVERY_5_MINUTES, array( $this, 'process_telemetry_logs_batch' ) );
	}

	/**
	 * Function that runs every minute.
	 *
	 * @internal
	 *
	 * @since 3.5.0
	 */
	public function process_telemetry_logs_batch() {
		if ( get_transient( 'global_telemetry_message_queue' ) !== false ) {
			$logs = get_transient( 'global_telemetry_message_queue' );

			// TODO: Replace with send batch logging request to Meta function.
			WC_Facebookcommerce_Utils::log( wp_json_encode( $logs ) );
		}

		set_transient( 'global_telemetry_message_queue', [], HOUR_IN_SECONDS );
	}
}
