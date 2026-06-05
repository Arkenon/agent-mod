<?php

/**
 * Block service
 *
 * @package AgentMod
 * @subpackage Services
 * @since 1.0.0
 *
 */

namespace AgentMod\Services;

use WP_Block;

defined('ABSPATH') || exit;

class BlockService
{
	public function __construct()
	{
		add_action('init', [$this, 'registerBlockTypes']);
	}

	/**
	 * Register block types
	 * @return void
	 * @since 1.0.0
	 */
	public function registerBlockTypes(): void
	{

		//First Block (or whatever your block name is)
		register_block_type(
			AGENT_MOD_PATH . '/build/first-block'/*,
			[
				//Callback function for your block (optional, use this callback if you want to make server side rendering)
				'render_callback' => [$this, 'firstBlockRenderCallback']
			]*/
		);
	}

	/**
	 * Callback function for your block
	 *
	 * @param array $block_attributes
	 * @param string $content
	 * @param WP_Block $block
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function firstBlockRenderCallback(array $block_attributes, string $content, WP_Block $block): string
	{
		$html = '<div ' . get_block_wrapper_attributes() . '>';
		if ($block_attributes['firstAttr']) {
			$html .= __("First Block: Hello from callback function / Attributes setted...");
		} else {
			$html .= __("First Block: Hello from callback function / Attributes not setted...");
		}
		$html .= '</div>';

		return wp_kses_post($html);
	}
}
