<?php

/**
 * BlockBindingsController class
 * Registers the Native Custom Fields block bindings source and loads its editor script
 *
 * @package NativeCustomFields
 * @subpackage Presentation\Admin\Controllers
 * @since 1.4.3
 */

namespace NativeCustomFields\Presentation\Admin\Controllers;

use NativeCustomFields\Services\BlockBindingsService;

defined('ABSPATH') || exit;

class BlockBindingsController
{
	/**
	 * Inject BlockBindingsService
	 *
	 * @var BlockBindingsService
	 * @since 1.4.3
	 */
	private BlockBindingsService $blockBindingsService;

	public function __construct(BlockBindingsService $block_bindings_service)
	{
		$this->blockBindingsService = $block_bindings_service;

		// Controllers are loaded on init (priority 5), so this still runs on the same init.
		add_action('init', [$this->blockBindingsService, 'registerSource']);

		// Load the editor side of the source (field list and preview values)
		add_action('enqueue_block_editor_assets', [$this, 'enqueueEditorScript']);
	}

	/**
	 * Enqueue the block bindings editor script
	 *
	 * @return void
	 * @since 1.4.3
	 */
	public function enqueueEditorScript(): void
	{
		$asset_path = NATIVE_CUSTOM_FIELDS_PATH . '/build/editor/block-bindings.asset.php';

		if (! file_exists($asset_path)) {
			return;
		}

		$fields = $this->blockBindingsService->getEditorFieldsConfig();

		if (empty($fields)) {
			return;
		}

		$asset_file = require $asset_path;

		wp_enqueue_script(
			'native-custom-fields-block-bindings',
			NATIVE_CUSTOM_FIELDS_URL . '/build/editor/block-bindings.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		wp_localize_script('native-custom-fields-block-bindings', 'nativeCustomFieldsBlockBindings', [
			'source'      => BlockBindingsService::SOURCE_NAME,
			'label'       => __('Native Custom Fields', 'native-custom-fields'),
			'yes'         => __('Yes', 'native-custom-fields'),
			'no'          => __('No', 'native-custom-fields'),
			'fields'      => $fields,
			'dateFormats' => $this->blockBindingsService->getDateFormats(),
		]);
	}
}
