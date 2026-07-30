<?php
/**
 * Yappy payment method fields for the classic WooCommerce checkout.
 *
 * @package WooCommerce_Yappy
 * @version 1.2.3
 *
 * @var string $description Gateway description.
 * @var bool   $ask_phone   Whether to render the phone field.
 * @var string $phone       Pre-filled phone number, may be empty.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wc-yappy wc-yappy--inline" id="wc-yappy-inline">

	<?php if ( $description ) : ?>
		<div class="wc-yappy__description">
			<?php echo wp_kses_post( wpautop( $description ) ); ?>
		</div>
	<?php endif; ?>

	<?php if ( $ask_phone ) : ?>
		<p class="wc-yappy__field form-row form-row-wide">
			<label for="wc-yappy-phone"><?php esc_html_e( 'Your Yappy phone number', 'woocommerce-yappy' ); ?></label>
			<input
				type="tel"
				id="wc-yappy-phone"
				name="wc-yappy-phone"
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

	<div class="wc-yappy__notice woocommerce-error" id="wc-yappy-inline-error" role="alert" hidden></div>
	<div class="wc-yappy__notice wc-yappy__notice--info" id="wc-yappy-inline-status" role="status" hidden></div>
	<div class="wc-yappy__button" id="wc-yappy-inline-button"></div>

	<div class="wc-yappy__waiting" id="wc-yappy-inline-waiting" role="dialog" aria-modal="true" aria-labelledby="wc-yappy-inline-waiting-title" hidden>
		<div class="wc-yappy__waiting-card">
			<button class="wc-yappy__waiting-close" id="wc-yappy-inline-waiting-close" type="button" aria-label="Close">&times;</button>
			<span class="wc-yappy__spinner" aria-hidden="true"></span>
			<p class="wc-yappy__waiting-title" id="wc-yappy-inline-waiting-title"></p>
			<p class="wc-yappy__waiting-message" id="wc-yappy-inline-waiting-message"></p>
			<time class="wc-yappy__waiting-time" id="wc-yappy-inline-waiting-time" datetime="PT5M">5:00</time>
		</div>
	</div>

	<input type="hidden" id="wc-yappy-inline-request" name="wc_yappy_inline" value="0" />
</div>
