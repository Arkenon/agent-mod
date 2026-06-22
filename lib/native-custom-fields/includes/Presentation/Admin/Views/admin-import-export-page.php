<?php

/**
 * View for the admin import/export page
 * @since 1.0.5
 * @package NativeCustomFields
 */

defined( 'ABSPATH' ) || exit;

use NativeCustomFields\Common\Helper;

?>

<div class="wrap">
	<div class="native-custom-fields-import-export-container">
		<h1><?php esc_html_e( 'Import/Export Data', 'native-custom-fields' ); ?></h1>
		<p><?php esc_html_e( 'Use the options below to import or export your data.', 'native-custom-fields' ); ?></p>

		<?php if ( Helper::sanitize( 'result', 'get' ) === 'success' ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Operation completed successfully.', 'native-custom-fields' ); ?></p>
			</div>
		<?php elseif ( Helper::sanitize( 'result', 'get' ) === 'error' ) : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'There was an error during export. Please try again.', 'native-custom-fields' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="native-custom-fields-import-export-options">
			<div class="native-custom-fields-export-section">
				<h2><?php esc_html_e( 'Export Fields', 'native-custom-fields' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'native_custom_fields_export_action', 'native_custom_fields_export_nonce' ); ?>
					<input type="hidden" name="action" value="native_custom_fields_export"/>

					<label for="native_custom_fields_export_forms">
						<?php esc_html_e( 'Select Field Types to Export:', 'native-custom-fields' ); ?>
					</label>
					<select name="native_custom_fields_export_forms[]" id="native_custom_fields_export_forms" multiple required>
						<?php
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local variables, not global.
						$choices = [
							'options'   => __( 'Option Pages & Fields', 'native-custom-fields' ),
							'post_meta' => __( 'Post Types & Post Meta Fields', 'native-custom-fields' ),
							'term_meta' => __( 'Taxonomies & Term Meta Fields', 'native-custom-fields' ),
							'user_meta' => __( 'User Meta Fields', 'native-custom-fields' )
						];
						foreach ( $choices as $key => $value ) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
						?>
							<option value="<?php echo esc_attr( $key ); ?>">
								<?php echo esc_html( $value ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Export', 'native-custom-fields' ); ?>
					</button>
					<button type="button" id="native-custom-fields-create-php" class="button">
						<?php esc_html_e( 'Create PHP', 'native-custom-fields' ); ?>
					</button>
				</form>

				<div id="native-custom-fields-php-output" style="margin-top:16px; display:none;">
					<h3 style="margin-bottom:8px;">&nbsp;<?php esc_html_e( 'Generated PHP', 'native-custom-fields' ); ?></h3>
					<textarea id="native-custom-fields-php-code"
							  aria-label="<?php echo esc_attr__( 'Generated PHP code', 'native-custom-fields' ); ?>"
							  style="width:100%; min-height:300px; font-family:monospace;"></textarea>
					<p>
						<button type="button" id="native-custom-fields-copy-php"
								class="button button-secondary"><?php esc_html_e( 'Copy', 'native-custom-fields' ); ?></button>
						<span id="native-custom-fields-copy-status" style="margin-left:8px;"></span>
					</p>
				</div>

				<script>
					(function () {
						const btn = document.getElementById('native-custom-fields-create-php');
						const select = document.getElementById('native_custom_fields_export_forms');
						const outputWrap = document.getElementById('native-custom-fields-php-output');
						const codeEl = document.getElementById('native-custom-fields-php-code');
						const copyBtn = document.getElementById('native-custom-fields-copy-php');
						const copyStatus = document.getElementById('native-custom-fields-copy-status');
						const endpoint = '<?php echo esc_url_raw( rest_url( 'native-custom-fields/v1/import-export/create-php' ) ); ?>';
						const nonce = '<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>';

						btn?.addEventListener('click', async function () {
							copyStatus.textContent = '';
							const selected = Array.from(select.options).filter(o => o.selected).map(o => o.value);
							if (!selected.length) {
								alert('<?php echo esc_js( __( 'Please select at least one item.', 'native-custom-fields' ) ); ?>');
								return;
							}
							btn.disabled = true;
							btn.textContent = '<?php echo esc_js( __( 'Creating…', 'native-custom-fields' ) ); ?>';
							try {
								const res = await fetch(endpoint, {
									method: 'POST',
									headers: {
										'Content-Type': 'application/json',
										'X-WP-Nonce': nonce
									},
									body: JSON.stringify({choices: selected})
								});
								const data = await res.json();
								if (data && data.success && data.code) {
									codeEl.value = data.code;
									outputWrap.style.display = '';
								} else {
									outputWrap.style.display = '';
									codeEl.value = (data && data.message) ? data.message : 'Error';
								}
							} catch (err) {
								outputWrap.style.display = '';
								codeEl.value = 'Request failed: ' + (err?.message || err);
							} finally {
								btn.disabled = false;
								btn.textContent = '<?php echo esc_js( __( 'Create PHP', 'native-custom-fields' ) ); ?>';
							}
						});

						async function copyToClipboard(text) {
							// Try modern API first
							if (navigator.clipboard && navigator.clipboard.writeText) {
								try {
									await navigator.clipboard.writeText(text);
									return true;
								} catch (e) {
								}
							}
							// Fallback to execCommand
							try {
								const ta = document.createElement('textarea');
								ta.value = text || '';
								ta.setAttribute('readonly', '');
								ta.style.position = 'absolute';
								ta.style.left = '-9999px';
								document.body.appendChild(ta);
								ta.select();
								ta.setSelectionRange(0, ta.value.length);
								const ok = document.execCommand('copy');
								document.body.removeChild(ta);
								return ok;
							} catch (e) {
								return false;
							}
						}

						copyBtn?.addEventListener('click', async function () {
							const ok = await copyToClipboard(codeEl.value || '');
							copyStatus.textContent = ok ? '<?php echo esc_js( __( 'Copied', 'native-custom-fields' ) ); ?>' : '<?php echo esc_js( __( 'Copy failed', 'native-custom-fields' ) ); ?>';
							if (ok) {
								setTimeout(() => copyStatus.textContent = '', 2000);
							}
						});
					})();
				</script>
			</div>
			<div class="native-custom-fields-import-section">
				<h2><?php esc_html_e( 'Import Fields', 'native-custom-fields' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					  enctype="multipart/form-data">
					<input type="file" name="native_custom_fields_import_file" accept=".json" required>
					<?php wp_nonce_field( 'native_custom_fields_import_action', 'native_custom_fields_import_nonce' ); ?>
					<input type="hidden" name="action" value="native_custom_fields_import"/>
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Import', 'native-custom-fields' ); ?>
					</button>
				</form>
			</div>
		</div>
	</div>
</div>
