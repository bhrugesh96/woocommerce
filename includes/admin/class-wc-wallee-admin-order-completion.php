<?php
/**
 *
 * WC_Wallee_Admin_Order_Completion Class
 *
 * Wallee
 * This plugin will add support for all Wallee payments methods and connect the Wallee servers to your WooCommerce webshop (https://www.wallee.com).
 *
 * @category Class
 * @package  Wallee
 * @author   wallee AG (http://www.wallee.com/)
 * @license  http://www.apache.org/licenses/LICENSE-2.0 Apache Software License (ASL 2.0)
 */

use Wallee\Sdk\Model\TransactionState;

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * WC Wallee Admin Order Completion class
 */
class WC_Wallee_Admin_Order_Completion {

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action(
			'woocommerce_order_item_add_line_buttons',
			array(
				__CLASS__,
				'render_execute_completion_button',
			)
		);

		add_action(
			'wp_ajax_woocommerce_wallee_execute_completion',
			array(
				__CLASS__,
				'execute_completion',
			)
		);

		add_action(
			'wallee_five_minutes_cron',
			array(
				__CLASS__,
				'update_completions',
			)
		);

		add_action(
			'wallee_update_running_jobs',
			array(
				__CLASS__,
				'update_for_order',
			)
		);
	}

	/**
	 * Render Execute Completion Button.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function render_execute_completion_button( WC_Order $order ) {
		$gateway = wc_get_payment_gateway_by_order( $order );
		if ( $gateway instanceof WC_Wallee_Gateway ) {
			$transaction_info = WC_Wallee_Entity_Transaction_Info::load_by_order_id( $order->get_id() );
			if ( TransactionState::AUTHORIZED === $transaction_info->get_state() ) {
				echo '<button type="button" class="button wallee-completion-button action-wallee-completion-cancel" style="display:none">' .
					esc_html__( 'Cancel', 'woo-wallee' ) . '</button>';
				echo '<button type="button" class="button button-primary wallee-completion-button action-wallee-completion-execute" style="display:none">' .
					esc_html__( 'Execute Completion', 'woo-wallee' ) . '</button>';
				echo '<label for="completion_restock_not_completed_items" style="display:none">' .
					esc_html__( 'Restock not completed items', 'woo-wallee' ) . '</label>';
				echo '<input type="checkbox" id="completion_restock_not_completed_items" name="restock_not_completed_items" checked="checked" style="display:none">';
				echo '<label for="refund_amount" style="display:none">' . esc_html__( 'Completion Amount', 'woo-wallee' ) . '</label>';
			}
		}
	}

	/**
	 * Execute completion.
	 *
	 * @return void
	 * @throws Exception Exception.
	 */
	public static function execute_completion() {
		ob_start();

		check_ajax_referer( 'order-item', 'security' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( -1 );
		}

		$order_id                    = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : null;
		$completion_amount           = isset( $_POST['completion_amount'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['completion_amount'] ) ), wc_get_price_decimals() ) : null;
		// phpcs:ignore
		$line_item_qtys              = isset( $_POST['line_item_qtys'] ) ?  array_map('sanitize_text_field', json_decode( wp_unslash( $_POST['line_item_qtys'] ), true ) ) : null;
		// phpcs:ignore
		$line_item_totals            = isset( $_POST['line_item_totals'] ) ? array_map('sanitize_text_field', json_decode( wp_unslash( $_POST['line_item_totals'] ), true ) ) : null;
	    	// phpcs:ignore
		$line_item_tax_totals        = isset( $_POST['line_item_tax_totals'] ) ? array_map('sanitize_text_field', json_decode( wp_unslash( $_POST['line_item_tax_totals'] ), true ) ): null;
		$restock_not_completed_items = isset( $_POST['restock_not_completed_items'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['restock_not_completed_items'] ) );
		$current_completion_id       = null;
		$transaction_info            = null;
		try {

			// Prepare line items which we are completed.
			$line_items = array();
			$item_ids   = array_unique( array_merge( array_keys( $line_item_qtys, $line_item_totals, true ) ) );
			foreach ( $item_ids as $item_id ) {
				$line_items[ $item_id ] = array(
					'qty'              => 0,
					'completion_total' => 0,
					'completion_tax'   => array(),
				);
			}
			foreach ( $line_item_qtys as $item_id => $qty ) {
				$line_items[ $item_id ]['qty'] = max( $qty, 0 );
			}
			foreach ( $line_item_totals as $item_id => $total ) {
				$line_items[ $item_id ]['completion_total'] = wc_format_decimal( $total );
			}
			foreach ( $line_item_tax_totals as $item_id => $tax_totals ) {
				$line_items[ $item_id ]['completion_tax'] = array_filter( array_map( 'wc_format_decimal', $tax_totals ) );
			}

			foreach ( array_keys( $line_items ) as $item_id ) {
				if ( isset( $line_items[ $item_id ]['qty'] ) && 0 === $line_items[ $item_id ]['qty'] && 0 === $line_items[ $item_id ]['completion_total'] ) {
					unset( $line_items[ $item_id ] );
				}
			}

			// Validate input first.
			$total_items_sum = 0;
			foreach ( $line_items as $item ) {

				$tax = 0;
				if ( isset( $item['completion_tax'] ) && is_array( $item['completion_tax'] ) ) {
					foreach ( $item['completion_tax'] as $rate_id => $amount ) {

						$percent = WC_Tax::get_rate_percent( $rate_id );
						$rate    = rtrim( $percent, '%' );

						$tax_amount = $item['completion_total'] * $rate / 100;
						if ( wc_format_decimal( $tax_amount, wc_get_price_decimals() ) !== wc_format_decimal( $amount, wc_get_price_decimals() ) ) {
							throw new Exception( __( 'The tax rate can not be changed.', 'woo-wallee' ) );
						}
					}
					$tax = array_sum( $item['completion_tax'] );
				}
				$total_items_sum += $item['completion_total'] + $tax;
			}

			if ( wc_format_decimal( $completion_amount, wc_get_price_decimals() ) !== wc_format_decimal( $total_items_sum, wc_get_price_decimals() ) ) {
				throw new Exception( __( 'The line item total does not correspond to the total amount to complete.', 'woo-wallee' ) );
			}

			WC_Wallee_Helper::instance()->start_database_transaction();
			$transaction_info = WC_Wallee_Entity_Transaction_Info::load_by_order_id( $order_id );
			if ( ! $transaction_info->get_id() ) {
				throw new Exception( __( 'Could not load corresponding transaction' ) );
			}

			WC_Wallee_Helper::instance()->lock_by_transaction_id( $transaction_info->get_space_id(), $transaction_info->get_transaction_id() );
			$transaction_info = WC_Wallee_Entity_Transaction_Info::load_by_transaction(
				$transaction_info->get_space_id(),
				$transaction_info->get_transaction_id(),
				$transaction_info->get_space_id()
			);

			if ( $transaction_info->get_state() !== TransactionState::AUTHORIZED ) {
				throw new Exception( __( 'The transaction is not in a state to be completed.', 'woo-wallee' ) );
			}

			if ( WC_Wallee_Entity_Completion_Job::count_running_completion_for_transaction(
				$transaction_info->get_space_id(),
				$transaction_info->get_transaction_id()
			) > 0 ) {
				throw new Exception( __( 'Please wait until the existing completion is processed.', 'woo-wallee' ) );
			}
			if ( WC_Wallee_Entity_Void_Job::count_running_void_for_transaction(
				$transaction_info->get_space_id(),
				$transaction_info->get_transaction_id()
			) > 0 ) {
				throw new Exception( __( 'There is a void in process. The order can not be completed.', 'woo-wallee' ) );
			}

			$completion_job = new WC_Wallee_Entity_Completion_Job();
			$completion_job->set_items( $line_items );
			$completion_job->set_restock( $restock_not_completed_items );
			$completion_job->set_space_id( $transaction_info->get_space_id() );
			$completion_job->set_transaction_id( $transaction_info->get_transaction_id() );
			$completion_job->set_state( WC_Wallee_Entity_Completion_Job::STATE_CREATED );
			$completion_job->set_order_id( $order_id );
			$completion_job->set_amount( $completion_amount );
			$completion_job->save();
			$current_completion_id = $completion_job->get_id();
			WC_Wallee_Helper::instance()->commit_database_transaction();
		} catch ( Exception $e ) {
			WC_Wallee_Helper::instance()->rollback_database_transaction();
			wp_send_json_error(
				array(
					'error' => $e->getMessage(),
				)
			);
			return;
		}

		try {
			self::update_line_items( $current_completion_id );
			self::send_completion( $current_completion_id );

			wp_send_json_success(
				array(
					'message' => __( 'The completion is updated automatically once the result is available.', 'woo-wallee' ),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'error' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Update line items.
	 *
	 * @param mixed $completion_job_id completion job id.
	 * @return void
	 * @throws \Wallee\Sdk\ApiException Api exception.
	 * @throws \Wallee\Sdk\Model\ClientError Client Error.
	 */
	protected static function update_line_items( $completion_job_id ) {
		$completion_job = WC_Wallee_Entity_Completion_Job::load_by_id( $completion_job_id );
		WC_Wallee_Helper::instance()->start_database_transaction();
		WC_Wallee_Helper::instance()->lock_by_transaction_id( $completion_job->get_space_id(), $completion_job->get_transaction_id() );
		// Reload void job.
		$completion_job = WC_Wallee_Entity_Completion_Job::load_by_id( $completion_job_id );

		if ( $completion_job->get_state() !== WC_Wallee_Entity_Completion_Job::STATE_CREATED ) {
			// Already updated in the meantime.
			WC_Wallee_Helper::instance()->rollback_database_transaction();
			return;
		}
		try {
			$line_items = WC_Wallee_Service_Line_Item::instance()->get_items_from_backend(
				$completion_job->get_items(),
				$completion_job->get_amount(),
				WC_Order_Factory::get_order( $completion_job->get_order_id() )
			);
			WC_Wallee_Service_Transaction::instance()->update_line_items(
				$completion_job->get_space_id(),
				$completion_job->get_transaction_id(),
				$line_items
			);
			$completion_job->set_state( WC_Wallee_Entity_Completion_Job::STATE_ITEMS_UPDATED );
			$completion_job->save();
			WC_Wallee_Helper::instance()->commit_database_transaction();
		} catch ( \Wallee\Sdk\ApiException $e ) {
			if ( $e->getResponseObject() instanceof \Wallee\Sdk\Model\ClientError ) {
				$completion_job->set_state( WC_Wallee_Entity_Completion_Job::STATE_DONE );
				$completion_job->save();
				WC_Wallee_Helper::instance()->commit_database_transaction();
			} else {
				$completion_job->save();
				WC_Wallee_Helper::instance()->commit_database_transaction();
				WooCommerce_Wallee::instance()->log( 'Error updating line items. ' . $e->getMessage(), WC_Log_Levels::INFO );
				throw $e;
			}
		} catch ( Exception $e ) {
			$completion_job->save();
			WC_Wallee_Helper::instance()->commit_database_transaction();
			WooCommerce_Wallee::instance()->log( 'Error updating line items. ' . $e->getMessage(), WC_Log_Levels::INFO );
			throw $e;
		}
	}

	/**
	 * Send Completion.
	 *
	 * @param mixed $completion_job_id completion job id.
	 * @return void
	 *
	 * @throws \Wallee\Sdk\ApiException ClientError.
	 * @throws Exception Exception.
	 */
	protected static function send_completion( $completion_job_id ) {
		$completion_job = WC_Wallee_Entity_Completion_Job::load_by_id( $completion_job_id );
		WC_Wallee_Helper::instance()->start_database_transaction();
		WC_Wallee_Helper::instance()->lock_by_transaction_id( $completion_job->get_space_id(), $completion_job->get_transaction_id() );
		// Reload void job.
		$completion_job = WC_Wallee_Entity_Completion_Job::load_by_id( $completion_job_id );

		if ( $completion_job->get_state() !== WC_Wallee_Entity_Completion_Job::STATE_ITEMS_UPDATED ) {
			// Already sent in the meantime.
			WC_Wallee_Helper::instance()->rollback_database_transaction();
			return;
		}
		try {
			$completion_service = new \Wallee\Sdk\Service\TransactionCompletionService( WC_Wallee_Helper::instance()->get_api_client() );

			$completion = $completion_service->completeOnline(
				$completion_job->get_space_id(),
				$completion_job->get_transaction_id()
			);
			$completion_job->set_completion_id( $completion->getId() );
			$completion_job->set_state( WC_Wallee_Entity_Completion_Job::STATE_SENT );
			$completion_job->save();
			WC_Wallee_Helper::instance()->commit_database_transaction();
		} catch ( \Wallee\Sdk\ApiException $e ) {
			if ( $e->getResponseObject() instanceof \Wallee\Sdk\Model\ClientError ) {
				$completion_job->set_state( WC_Wallee_Entity_Completion_Job::STATE_DONE );
				$completion_job->save();
				WC_Wallee_Helper::instance()->commit_database_transaction();
			} else {
				$completion_job->save();
				WC_Wallee_Helper::instance()->commit_database_transaction();
				WooCommerce_Wallee::instance()->log( 'Error sending completion. ' . $e->getMessage(), WC_Log_Levels::INFO );
				throw $e;
			}
		} catch ( Exception $e ) {
			$completion_job->save();
			WC_Wallee_Helper::instance()->commit_database_transaction();
			WooCommerce_Wallee::instance()->log( 'Error sending completion. ' . $e->getMessage(), WC_Log_Levels::INFO );
			throw $e;
		}
	}

	/**
	 * Update for order.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 * @throws \Wallee\Sdk\ApiException ApiException.
	 * @throws \Wallee\Sdk\Http\ConnectionException ConnectionException.
	 * @throws \Wallee\Sdk\VersioningException VersioningException.
	 */
	public static function update_for_order( WC_Order $order ) {

		$transaction_info = WC_Wallee_Entity_Transaction_Info::load_by_order_id( $order->get_id() );
		$completion_job   = WC_Wallee_Entity_Completion_Job::load_running_completion_for_transaction( $transaction_info->get_space_id(), $transaction_info->get_transaction_id() );

		if ( $completion_job->get_state() === WC_Wallee_Entity_Completion_Job::STATE_CREATED ) {
			self::update_line_items( $completion_job->get_id() );
			self::send_completion( $completion_job->get_id() );
		} elseif ( $completion_job->get_state() === WC_Wallee_Entity_Completion_Job::STATE_ITEMS_UPDATED ) {
			self::send_completion( $completion_job->get_id() );
		}
	}

	/**
	 * Update completions.
	 *
	 * @return void
	 */
	public static function update_completions() {
		$to_process = WC_Wallee_Entity_Completion_Job::load_not_sent_job_ids();
		foreach ( $to_process as $id ) {
			try {
				self::update_line_items( $id );
				self::send_completion( $id );
			} catch ( Exception $e ) {
				/* translators: %1$d: id of transaction, %2$s: error message */
				$message = sprintf( __( 'Error updating completion job with id %1$d: %2$s', 'woo-wallee' ), $id, $e->getMessage() );
				WooCommerce_Wallee::instance()->log( $message, WC_Log_Levels::ERROR );
			}
		}
	}
}
WC_Wallee_Admin_Order_Completion::init();
