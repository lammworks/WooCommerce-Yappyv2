<?php
/**
 * Yappy payment page, rendered on the order-pay endpoint.
 *
 * Override by copying this file to `yourtheme/woocommerce/yappy/payment-page.php`.
 *
 * @package WooCommerce_Yappy
 * @version 1.0.0
 *
 * @var WC_Order         $order     Order being paid.
 * @var WC_Yappy_Gateway $gateway   Gateway instance.
 * @var bool             $ask_phone Whether to render the phone field.
 * @var string           $phone     Pre-filled phone number, may be empty.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wc-yappy" id="wc-yappy">

	<p class="wc-yappy__total">
		<?php
		printf(
			/* translators: %s: formatted order total. */
			esc_html__( 'Amount to pay: %s', 'woocommerce-yappy' ),
			wp_kses_post( $order->get_formatted_order_total() )
		);
		?>
	</p>

	<?php if ( $ask_phone ) : ?>
		<p class="wc-yappy__field form-row form-row-wide">
			<label for="wc-yappy-phone"><?php esc_html_e( 'Your Yappy phone number', 'woocommerce-yappy' ); ?></label>
			<input
				type="tel"
				id="wc-yappy-phone"
				class="input-text"
				inputmode="numeric"
				autocomplete="tel-national"
				maxlength="12"
				placeholder="61234567"
				value="<?php echo esc_attr( $phone ); ?>"
			/>
			<span class="wc-yappy__hint">
				<?php esc_html_e( 'We will send the payment request straight to this number. Leave it empty to pay by scanning a QR code instead.', 'woocommerce-yappy' ); ?>
			</span>
		</p>
	<?php endif; ?>

	<div class="wc-yappy__notice woocommerce-error" id="wc-yappy-error" role="alert" hidden></div>

	<div class="wc-yappy__notice wc-yappy__notice--info" id="wc-yappy-status" role="status" hidden></div>

	<div class="wc-yappy__button" id="wc-yappy-button"></div>

	<noscript>
		<p class="woocommerce-error">
			<?php esc_html_e( 'JavaScript is required to pay with Yappy. Please enable it and reload this page.', 'woocommerce-yappy' ); ?>
		</p>
	</noscript>

	<p class="wc-yappy__unavailable woocommerce-info" id="wc-yappy-unavailable" hidden>
		<?php esc_html_e( 'Yappy is temporarily unavailable. Please choose another payment method.', 'woocommerce-yappy' ); ?>
	</p>

	<p class="wc-yappy__cancel">
		<a href="<?php echo esc_url( $order->get_cancel_order_url( wc_get_checkout_url() ) ); ?>">
			<?php esc_html_e( 'Cancel and choose another payment method', 'woocommerce-yappy' ); ?>
		</a>
	</p>

</div>
