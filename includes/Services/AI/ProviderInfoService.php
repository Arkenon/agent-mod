<?php

/**
 * Provider info service.
 *
 * Reads which AI provider(s) the site is currently connected to through the
 * native WordPress Connectors API. AgentMod stays provider-agnostic and lets the
 * AI Client auto-select at request time; this service is purely informational,
 * so the chat UI can show the connected provider to the user.
 *
 * It only consumes the public Connectors / WP AI Client functions — it never
 * touches third-party plugin internals.
 *
 * @package AgentMod
 * @subpackage Services\AI
 * @since 1.0.0
 */

namespace AgentMod\Services\AI;

use Throwable;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

defined('ABSPATH') || exit;

class ProviderInfoService
{
	/**
	 * Transient key prefix for cached model lists.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private const MODELS_CACHE_PREFIX = 'agent_mod_models_';

	/**
	 * Returns the list of connected (configured) AI providers.
	 *
	 * The authoritative list and "configured" state come straight from the
	 * WordPress AI Client registry (core, since WP 7.0) — the same source the
	 * core code uses. AgentMod deliberately does NOT call the separate "AI"
	 * plugin's helpers (e.g. WordPress\AI\is_connector_configured), so the chat
	 * widget keeps working whether or not that plugin is installed.
	 *
	 * The provider's display name and logo are enriched from the core Connectors
	 * API (wp_get_connectors) when available, falling back to the provider's own
	 * metadata. Both sources are WordPress core.
	 *
	 * Each entry is ['id' => ..., 'name' => ..., 'logoUrl' => ...].
	 *
	 * @return array<int, array<string, string>>
	 * @since 1.0.0
	 */
	public function getConnectedProviders(): array
	{
		if (! class_exists(AiClient::class)) {
			return [];
		}

		try {
			$registry  = AiClient::defaultRegistry();
			$labels    = $this->connectorLabels();
			$providers = [];

			foreach ($registry->getRegisteredProviderIds() as $id) {
				// "Connected" means the provider has usable credentials. Core wires
				// the saved connector keys into the AI Client registry on init, so
				// this resolves correctly without the "AI" plugin.
				if (! $registry->isProviderConfigured($id)) {
					continue;
				}

				$name    = $labels[$id]['name'] ?? '';
				$logoUrl = $labels[$id]['logoUrl'] ?? '';

				if ('' === $name || '' === $logoUrl) {
					$metadata = $this->providerMetadata($registry, $id);

					if (null !== $metadata) {
						if ('' === $name) {
							$name = (string) $metadata->getName();
						}

						if ('' === $logoUrl) {
							$logoUrl = $this->providerLogoUrl($metadata);
						}
					}
				}

				$providers[] = [
					'id'      => $id,
					'name'    => '' !== $name ? $name : $id,
					'logoUrl' => $logoUrl,
				];
			}

			return $providers;
		} catch (Throwable $e) {
			return [];
		}
	}

	/**
	 * Returns the text-generation models offered by a connected provider.
	 *
	 * The list is fetched from the provider via the AI Client (a network call)
	 * and cached in a transient, since the chat UI requests it lazily whenever the
	 * user opens the provider/model picker.
	 *
	 * @param string $providerId Provider id (e.g. 'google', 'openai').
	 *
	 * @return array<int, array<string, string>> Each entry is ['id' => ..., 'name' => ...].
	 * @since 1.0.0
	 */
	public function getTextModels(string $providerId): array
	{
		$providerId = sanitize_key($providerId);

		if ('' === $providerId) {
			return [];
		}

		$cacheKey = self::MODELS_CACHE_PREFIX . $providerId;
		$cached   = get_transient($cacheKey);

		if (is_array($cached) && ! empty($cached)) {
			return $cached;
		}

		$models = $this->fetchTextModels($providerId);

		// Only cache a non-empty result. Empty results are never cached so a
		// transient failure (e.g. a missing key or a hiccup in the provider's
		// live availability check) recovers on the very next request instead of
		// being "poisoned" for the cache lifetime.
		if (! empty($models)) {
			set_transient($cacheKey, $models, 6 * HOUR_IN_SECONDS);
		}

		return $models;
	}

	/**
	 * Fetches the text-generation models for a provider from the AI Client.
	 *
	 * @param string $providerId Provider id.
	 *
	 * @return array<int, array<string, string>>
	 * @since 1.0.0
	 */
	private function fetchTextModels(string $providerId): array
	{
		if (! class_exists(AiClient::class)) {
			return [];
		}

		try {
			$registry = AiClient::defaultRegistry();

			// Mirror the core AI plugin's Models_Controller: don't pre-gate with a
			// separate hasProvider()/isProviderConfigured() check. findProviderModels‐
			// MetadataForSupport() already returns [] for unknown or unconfigured
			// providers, so the extra gate only adds a redundant live availability
			// call (a second failure point) without changing the outcome.
			$requirements = new ModelRequirements([CapabilityEnum::textGeneration()], []);
			$metadata     = $registry->findProviderModelsMetadataForSupport($providerId, $requirements);

			$models = [];

			foreach ($metadata as $model) {
				$id = (string) $model->getId();

				if ('' === $id) {
					continue;
				}

				$models[] = [
					'id'   => $id,
					'name' => (string) $model->getName(),
				];
			}

			return $models;
		} catch (Throwable $e) {
			return [];
		}
	}

	/**
	 * Returns display labels (name + logo URL) keyed by provider id, read from the
	 * core Connectors API.
	 *
	 * This is cosmetic enrichment only; it never decides which providers are
	 * connected. wp_get_connectors() is a WordPress core function (wp-includes/
	 * connectors.php), so this does not couple AgentMod to the "AI" plugin.
	 *
	 * @return array<string, array{name: string, logoUrl: string}>
	 * @since 1.0.0
	 */
	private function connectorLabels(): array
	{
		if (! function_exists('wp_get_connectors')) {
			return [];
		}

		$labels = [];

		foreach (wp_get_connectors() as $id => $data) {
			if (! is_string($id) || ! is_array($data)) {
				continue;
			}

			$labels[$id] = [
				'name'    => isset($data['name']) ? (string) $data['name'] : '',
				'logoUrl' => isset($data['logo_url']) ? (string) $data['logo_url'] : '',
			];
		}

		return $labels;
	}

	/**
	 * Resolves a provider's metadata from the registry, or null on failure.
	 *
	 * @param ProviderRegistry $registry The AI Client provider registry.
	 * @param string           $id       Provider id.
	 *
	 * @return ProviderMetadata|null
	 * @since 1.0.0
	 */
	private function providerMetadata(ProviderRegistry $registry, string $id): ?ProviderMetadata
	{
		try {
			$className = $registry->getProviderClassName($id);

			return $className::metadata();
		} catch (Throwable $e) {
			return null;
		}
	}

	/**
	 * Derives a browser-usable logo URL from a provider's metadata logo path.
	 *
	 * The AI Client exposes the logo as a filesystem path (getLogoPath, added in
	 * a later AI Client version); this maps it to a URL under wp-content when
	 * possible. Returns an empty string when no usable logo is available.
	 *
	 * @param ProviderMetadata $metadata Provider metadata.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function providerLogoUrl(ProviderMetadata $metadata): string
	{
		if (! method_exists($metadata, 'getLogoPath')) {
			return '';
		}

		$logoPath = $metadata->getLogoPath();

		if (! is_string($logoPath) || '' === $logoPath || ! file_exists($logoPath)) {
			return '';
		}

		$logoPath   = wp_normalize_path($logoPath);
		$contentDir = wp_normalize_path(WP_CONTENT_DIR);

		if (0 === strpos($logoPath, $contentDir)) {
			return content_url(substr($logoPath, strlen($contentDir)));
		}

		return '';
	}
}
