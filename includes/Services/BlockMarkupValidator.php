<?php

/**
 * Block markup validator.
 *
 * Single home for all stateless, WP-core-only block-markup analysis. The
 * read-only `agent-mod/validate-block-markup` ability and the write-path
 * enforcement in AbilityRegistrarService::resolveBlocks() both delegate here,
 * so the advisory linter and the hard write-time gate can never diverge.
 *
 * @package AgentMod
 * @subpackage Services
 * @since x.x.x
 */

namespace AgentMod\Services;

use WP_Block_Type_Registry;

defined('ABSPATH') || exit;

final class BlockMarkupValidator
{
	/**
	 * Common container/inline tags whose open/close counts must match.
	 *
	 * @var string[]
	 * @since x.x.x
	 */
	private const BALANCED_TAGS = [
		'div', 'section', 'header', 'footer', 'main', 'aside', 'figure',
		'figcaption', 'ul', 'ol', 'li', 'p', 'span', 'a',
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
	];

	/**
	 * Lints serialized block markup without writing anything.
	 *
	 * Mirrors the checks the write abilities perform implicitly (parse_blocks +
	 * named-block filter) and adds the diagnostics they lack, so the model can
	 * repair markup before a write call. Registry- and legacy-alignment findings
	 * are advisory warnings appended after `valid` is decided, so they never flip
	 * a structurally sound document to invalid.
	 *
	 * @param string $markup The serialized block markup to validate.
	 *
	 * @return array<string, mixed> { valid: bool, block_count: int, issues: string[] }
	 * @since x.x.x
	 */
	public function validate(string $markup): array
	{
		$issues = [];

		$blocks      = parse_blocks($markup);
		$namedBlocks = $this->countNamedBlocks($blocks);

		if (0 === $namedBlocks) {
			// Same wording as the write abilities so the model recognizes it as
			// the error a write call would return.
			$issues[] = __('No valid blocks found in the provided block markup.', 'agent-mod');
		}

		foreach ($this->strayContentExcerpts($blocks) as $excerpt) {
			$issues[] = sprintf(
				/* translators: %s: excerpt of the stray content. */
				__('Content exists outside block delimiters and will be dropped on write: "%s".', 'agent-mod'),
				mb_substr($excerpt, 0, 80)
			);
		}

		foreach ($this->invalidJsonAttributes($markup) as $attributeJson) {
			$issues[] = sprintf(
				/* translators: %s: excerpt of the invalid attribute object. */
				__('Invalid JSON attribute object: "%s" — attributes must be strict JSON: double-quoted keys and strings, no trailing commas, no single quotes.', 'agent-mod'),
				mb_substr($attributeJson, 0, 80)
			);
		}

		$delimiterCount = preg_match_all('#<!--\s+wp:[a-z][a-z0-9/-]*#', $markup);

		if (false !== $delimiterCount && $delimiterCount > $namedBlocks) {
			$issues[] = sprintf(
				/* translators: 1: number of opening delimiters found, 2: number of blocks parsed. */
				__('%1$d block delimiters found but only %2$d parsed — check that every attribute object is valid single-line JSON and every non-self-closing block has a matching closing comment.', 'agent-mod'),
				$delimiterCount,
				$namedBlocks
			);
		}

		$issues = array_merge($issues, $this->tagBalanceIssues($markup));
		$issues = array_merge($issues, $this->checkAttributeClassParity($blocks));

		$valid = empty($issues);

		$registry = WP_Block_Type_Registry::get_instance();

		foreach ($this->collectBlockNames($blocks) as $blockName) {
			if (! $registry->is_registered($blockName)) {
				// Warning only — client-registered blocks are absent from the
				// server registry, so this must not flip `valid` to false.
				$issues[] = sprintf(
					/* translators: %s: block name. */
					__('warning: block "%s" is not registered on the server (may be a client-only block — verify the name).', 'agent-mod'),
					$blockName
				);
			}
		}

		$issues = array_merge($issues, $this->checkLegacyAlignmentAttributes($blocks));

		return [
			'valid'       => $valid,
			'block_count' => $namedBlocks,
			'issues'      => array_values($issues),
		];
	}

	/**
	 * Reports container tags that open and close a different number of times.
	 *
	 * parse_blocks() never validates the saved HTML, so a single missing
	 * `</div>` round-trips into the database and invalidates the whole parent
	 * chain in the editor. A document-level open/close count catches the typical
	 * "forgot a closing tag" failure cheaply.
	 *
	 * @param string $markup Full serialized block markup.
	 *
	 * @return array<int, string> Human-readable issue strings; empty when balanced.
	 * @since x.x.x
	 */
	public function tagBalanceIssues(string $markup): array
	{
		$issues = [];

		// HTML inside delimiter comments must not count towards the balance.
		$html = preg_replace('#<!--.*?-->#s', '', $markup);

		foreach (self::BALANCED_TAGS as $tag) {
			$opened = preg_match_all('#<' . $tag . '(?![\w-])#i', $html);
			$closed = preg_match_all('#</' . $tag . '\s*>#i', $html);

			if ($opened !== $closed) {
				$issues[] = sprintf(
					/* translators: 1: tag name, 2: number of opening tags, 3: number of closing tags. */
					__('Unbalanced HTML: <%1$s> is opened %2$d times but closed %3$d times — every opened tag must be closed inside its own block.', 'agent-mod'),
					$tag,
					(int) $opened,
					(int) $closed
				);
			}
		}

		return $issues;
	}

	/**
	 * Returns the raw attribute-JSON excerpts that fail strict JSON decoding.
	 *
	 * parse_blocks() silently nulls the attributes when the JSON is malformed
	 * while still recognizing the block, so the editor later fails validation —
	 * every attribute object must be scanned directly. Serialized attributes can
	 * never contain "-->" (the serializer escapes "--").
	 *
	 * @param string $markup Full serialized block markup.
	 *
	 * @return array<int, string> Offending attribute-object strings; empty when all decode.
	 * @since x.x.x
	 */
	public function invalidJsonAttributes(string $markup): array
	{
		$invalid = [];

		if (preg_match_all('#<!--\s+wp:[a-z][a-z0-9/-]*\s+({.*?})\s*/?-->#s', $markup, $matches)) {
			foreach ($matches[1] as $attributeJson) {
				json_decode($attributeJson);

				if (JSON_ERROR_NONE !== json_last_error()) {
					$invalid[] = $attributeJson;
				}
			}
		}

		return $invalid;
	}

	/**
	 * Returns the trimmed content of top-level nodes that carry text but no
	 * block name — i.e. content sitting outside block delimiters that would be
	 * silently dropped on save.
	 *
	 * @param array<int, array<string, mixed>> $blocks parse_blocks() output.
	 *
	 * @return array<int, string> Offending content excerpts; empty when none.
	 * @since x.x.x
	 */
	public function strayContentExcerpts(array $blocks): array
	{
		$stray = [];

		foreach ($blocks as $block) {
			if (empty($block['blockName']) && '' !== trim((string) $block['innerHTML'])) {
				$stray[] = trim((string) $block['innerHTML']);
			}
		}

		return $stray;
	}

	/**
	 * Checks that style attributes are mirrored by the classes the block
	 * serializer would emit ("attribute → class parity").
	 *
	 * A missing companion class (e.g. `has-border-color` when
	 * `style.border.color` is set) is the single most common cause of the
	 * editor's "unexpected or invalid content" recovery dialog, and nothing
	 * on the write path detects it.
	 *
	 * @param array<int, array<string, mixed>> $blocks parse_blocks() output.
	 *
	 * @return array<int, string> Hard issues.
	 * @since x.x.x
	 */
	private function checkAttributeClassParity(array $blocks): array
	{
		$issues = [];

		foreach ($blocks as $block) {
			if (! empty($block['innerBlocks'])) {
				$issues = array_merge($issues, $this->checkAttributeClassParity($block['innerBlocks']));
			}

			if (empty($block['blockName']) || '' === trim((string) $block['innerHTML'])) {
				continue;
			}

			$attrs = is_array($block['attrs']) ? $block['attrs'] : [];
			$html  = (string) $block['innerHTML'];

			$required = [];

			if (! empty($attrs['textColor'])) {
				$required[] = 'has-' . $attrs['textColor'] . '-color';
				$required[] = 'has-text-color';
			}

			if (! empty($attrs['backgroundColor'])) {
				$required[] = 'has-' . $attrs['backgroundColor'] . '-background-color';
				$required[] = 'has-background';
			}

			if (! empty($attrs['gradient'])) {
				$required[] = 'has-' . $attrs['gradient'] . '-gradient-background';
				$required[] = 'has-background';
			}

			if (! empty($attrs['fontSize'])) {
				$required[] = 'has-' . $attrs['fontSize'] . '-font-size';
			}

			if (! empty($attrs['fontFamily'])) {
				$required[] = 'has-' . $attrs['fontFamily'] . '-font-family';
			}

			if (! empty($attrs['borderColor'])) {
				$required[] = 'has-border-color';
				$required[] = 'has-' . $attrs['borderColor'] . '-border-color';
			}

			if (! empty($attrs['style']['color']['text'])) {
				$required[] = 'has-text-color';
			}

			if (! empty($attrs['style']['color']['background']) || ! empty($attrs['style']['color']['gradient'])) {
				$required[] = 'has-background';
			}

			if (! empty($attrs['style']['border']['color'])) {
				$required[] = 'has-border-color';
			}

			if (! empty($attrs['style']['typography']['textAlign'])) {
				$required[] = 'has-text-align-' . $attrs['style']['typography']['textAlign'];
			}

			if (! empty($attrs['align']) && in_array($attrs['align'], ['wide', 'full'], true)) {
				$required[] = 'align' . $attrs['align'];
			}

			if (! empty($attrs['className']) && is_string($attrs['className'])) {
				foreach (preg_split('/\s+/', trim($attrs['className'])) as $customClass) {
					if ('' !== $customClass) {
						$required[] = $customClass;
					}
				}
			}

			foreach (array_unique($required) as $class) {
				if (! preg_match('/(?<![\w-])' . preg_quote($class, '/') . '(?![\w-])/', $html)) {
					$issues[] = sprintf(
						/* translators: 1: CSS class name, 2: block name. */
						__('Missing required class "%1$s" in the saved HTML of a "%2$s" block — every style attribute must be mirrored by its companion class or the editor rejects the block.', 'agent-mod'),
						$class,
						$block['blockName']
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * Warns about legacy text-alignment attributes the current editor migrates
	 * to `style.typography.textAlign` (observed via block recovery on this
	 * site's WordPress version).
	 *
	 * @param array<int, array<string, mixed>> $blocks parse_blocks() output.
	 *
	 * @return array<int, string> Warnings (never flip validity).
	 * @since x.x.x
	 */
	private function checkLegacyAlignmentAttributes(array $blocks): array
	{
		$issues = [];

		foreach ($blocks as $block) {
			if (! empty($block['innerBlocks'])) {
				$issues = array_merge($issues, $this->checkLegacyAlignmentAttributes($block['innerBlocks']));
			}

			if (empty($block['blockName']) || ! in_array($block['blockName'], ['core/paragraph', 'core/heading'], true)) {
				continue;
			}

			$attrs = is_array($block['attrs']) ? $block['attrs'] : [];

			$legacy = ! empty($attrs['textAlign'])
				|| (! empty($attrs['align']) && in_array($attrs['align'], ['left', 'center', 'right'], true));

			if ($legacy) {
				$issues[] = sprintf(
					/* translators: %s: block name. */
					__('warning: "%s" uses a legacy top-level alignment attribute ("align"/"textAlign") — this editor stores text alignment as {"style":{"typography":{"textAlign":"…"}}} with the has-text-align-… class; use that form.', 'agent-mod'),
					$block['blockName']
				);
			}
		}

		return $issues;
	}

	/**
	 * Counts named blocks recursively.
	 *
	 * @param array<int, array<string, mixed>> $blocks parse_blocks() output.
	 *
	 * @return int
	 * @since x.x.x
	 */
	private function countNamedBlocks(array $blocks): int
	{
		$count = 0;

		foreach ($blocks as $block) {
			if (! empty($block['blockName'])) {
				$count++;
			}

			if (! empty($block['innerBlocks'])) {
				$count += $this->countNamedBlocks($block['innerBlocks']);
			}
		}

		return $count;
	}

	/**
	 * Collects the unique block names used in a parsed block tree.
	 *
	 * @param array<int, array<string, mixed>> $blocks parse_blocks() output.
	 *
	 * @return array<int, string>
	 * @since x.x.x
	 */
	private function collectBlockNames(array $blocks): array
	{
		$names = [];

		foreach ($blocks as $block) {
			if (! empty($block['blockName'])) {
				$names[$block['blockName']] = true;
			}

			if (! empty($block['innerBlocks'])) {
				foreach ($this->collectBlockNames($block['innerBlocks']) as $name) {
					$names[$name] = true;
				}
			}
		}

		return array_keys($names);
	}
}
