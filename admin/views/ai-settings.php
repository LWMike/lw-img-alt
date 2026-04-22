<?php
/**
 * AI Settings screen.
 *
 * Variables provided by LWIA_Admin::render_ai_settings():
 *   $saved  bool   True if settings were just saved.
 *   $notice string Error/info notice key.
 *   $spend  array  Current month spend record.
 *
 * @package LW_Image_Alt
 */

defined( 'ABSPATH' ) || exit;

$api_key_set = '' !== LWIA_AI_Settings::get_api_key();
$cap         = LWIA_AI_Settings::get_spend_cap();
?>
<div class="wrap">

	<h1><?php esc_html_e( 'Image Alt — AI Settings', 'lw-img-alt' ); ?></h1>

	<?php if ( $saved ) : ?>
	<div class="notice notice-success is-dismissible">
		<p><?php esc_html_e( 'Settings saved.', 'lw-img-alt' ); ?></p>
	</div>
	<?php endif; ?>

	<?php if ( 'no_key' === $notice ) : ?>
	<div class="notice notice-warning">
		<p><?php esc_html_e( 'Please enter your OpenAI API key before using AI features.', 'lw-img-alt' ); ?></p>
	</div>
	<?php endif; ?>

	<?php if ( LWIA_AI_Settings::is_cap_reached() ) : ?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'Monthly spend cap reached. AI generation is paused until next month or until you raise the cap below.', 'lw-img-alt' ); ?></p>
	</div>
	<?php elseif ( LWIA_AI_Settings::is_cap_warning() ) : ?>
	<div class="notice notice-warning">
		<p>
		<?php
		printf(
			/* translators: 1: current spend, 2: cap */
			esc_html__( 'AI spend is at £%1$s this month (cap: £%2$s).', 'lw-img-alt' ),
			esc_html( number_format( (float) $spend['estimated_gbp'], 2 ) ),
			esc_html( number_format( $cap, 2 ) )
		);
		?>
		</p>
	</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'lwia_ai_settings' ); ?>
		<input type="hidden" name="action" value="lwia_ai_settings">

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row"><?php esc_html_e( 'AI features', 'lw-img-alt' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="lwia_ai_enabled"
							value="1"
							<?php checked( LWIA_AI_Settings::is_enabled() ); ?>
						>
						<?php esc_html_e( 'Enable AI alt text generation for this site', 'lw-img-alt' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Unticking this hides the Generate button and AI Suggest menu item. API key and settings are retained.', 'lw-img-alt' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="lwia_ai_api_key"><?php esc_html_e( 'OpenAI API key', 'lw-img-alt' ); ?></label>
				</th>
				<td>
					<input
						type="password"
						id="lwia_ai_api_key"
						name="lwia_ai_api_key"
						class="regular-text"
						value="<?php echo esc_attr( LWIA_AI_Settings::masked_api_key() ); ?>"
						autocomplete="new-password"
						placeholder="<?php echo $api_key_set ? esc_attr__( 'Leave blank to keep current key', 'lw-img-alt' ) : 'sk-…'; ?>"
					>
					<p class="description">
						<?php
						printf(
							/* translators: 1: opening anchor, 2: closing anchor */
							esc_html__( 'Lead Wolf central key. Never share this. %1$sManage keys in the OpenAI platform%2$s.', 'lw-img-alt' ),
							'<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">',
							'</a>'
						);
						?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="lwia_ai_style_guide"><?php esc_html_e( 'Style guide', 'lw-img-alt' ); ?></label>
				</th>
				<td>
					<textarea
						id="lwia_ai_style_guide"
						name="lwia_ai_style_guide"
						class="large-text"
						rows="6"
						placeholder="<?php esc_attr_e( 'e.g. This is Square Kitchens at Ponsford, a kitchen showroom in Sheffield…', 'lw-img-alt' ); ?>"
					><?php echo esc_textarea( LWIA_AI_Settings::get_style_guide() ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Per-site brand context prepended to every prompt. Describe the business, key products, and any terminology to use or avoid. Leave blank for a generic prompt.', 'lw-img-alt' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="lwia_ai_spend_cap"><?php esc_html_e( 'Monthly spend cap (£)', 'lw-img-alt' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						id="lwia_ai_spend_cap"
						name="lwia_ai_spend_cap"
						class="small-text"
						value="<?php echo esc_attr( number_format( LWIA_AI_Settings::get_spend_cap(), 2 ) ); ?>"
						min="0"
						step="0.01"
					>
					<p class="description">
						<?php esc_html_e( 'New batch jobs are blocked once this is reached. A warning appears at 80%. Set to 0 for no cap.', 'lw-img-alt' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Model', 'lw-img-alt' ); ?></th>
				<td>
					<code><?php echo esc_html( LWIA_AI_OpenAI::MODEL ); ?></code>
					<p class="description">
						<?php esc_html_e( 'GPT-4o mini — not configurable in v2.', 'lw-img-alt' ); ?>
					</p>
				</td>
			</tr>

		</table>

		<?php submit_button( esc_html__( 'Save AI Settings', 'lw-img-alt' ) ); ?>
	</form>

	<!-- Spend this month -->
	<hr>
	<h2><?php esc_html_e( 'This month&#8217;s usage', 'lw-img-alt' ); ?></h2>
	<table class="widefat fixed striped lwia-spend-table" style="max-width:500px;">
		<tbody>
			<tr>
				<th><?php esc_html_e( 'Month', 'lw-img-alt' ); ?></th>
				<td><?php echo esc_html( $spend['month'] ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Images processed', 'lw-img-alt' ); ?></th>
				<td><?php echo esc_html( number_format( (int) $spend['images'] ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Tokens (in / out)', 'lw-img-alt' ); ?></th>
				<td>
					<?php echo esc_html( number_format( (int) $spend['tokens_in'] ) ); ?>
					/
					<?php echo esc_html( number_format( (int) $spend['tokens_out'] ) ); ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Estimated cost', 'lw-img-alt' ); ?></th>
				<td>
					£<?php echo esc_html( number_format( (float) $spend['estimated_gbp'], 2 ) ); ?>
					<?php if ( $cap > 0 ) : ?>
						<span class="description"> / £<?php echo esc_html( number_format( $cap, 2 ) ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>
	<p class="description">
		<?php esc_html_e( 'Costs are estimates based on Anthropic pricing and a fixed USD→GBP rate. Actual invoice may differ slightly.', 'lw-img-alt' ); ?>
	</p>

</div><!-- .wrap -->
