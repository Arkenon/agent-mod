/**
 * Lightweight localStorage persistence for the provider model lists.
 *
 * The model lists rarely change, so caching them on the client means the
 * provider/model picker shows instantly on every admin page load — no network
 * round-trip and no loading state. The TTL matches the server-side transient
 * (6 hours) so the cache refreshes on roughly the same cadence; expired or
 * malformed data is ignored, letting the background prefetch repopulate it.
 */

const STORAGE_KEY = 'agentModChatProviderModels';
const TTL_MS      = 6 * 60 * 60 * 1000; // 6 hours, matches the server transient.

/**
 * Reads the cached provider models, keyed by provider id.
 *
 * @return {Object} Map of providerId -> [{ id, name }], or an empty object when
 *                  there is no usable (present, non-expired, valid) cache.
 */
export function loadProviderModels() {
	try {
		const raw = window.localStorage.getItem( STORAGE_KEY );

		if ( ! raw ) {
			return {};
		}

		const parsed = JSON.parse( raw );

		if ( ! parsed || typeof parsed !== 'object' ) {
			return {};
		}

		if ( ! parsed.ts || Date.now() - parsed.ts > TTL_MS ) {
			return {};
		}

		return parsed.models && typeof parsed.models === 'object' ? parsed.models : {};
	} catch {
		return {};
	}
}

/**
 * Persists the provider models map. Best-effort: storage errors (quota, private
 * mode, unavailable) are swallowed since the cache is purely an optimization.
 *
 * @param {Object} models Map of providerId -> [{ id, name }].
 */
export function saveProviderModels( models ) {
	try {
		window.localStorage.setItem(
			STORAGE_KEY,
			JSON.stringify( { ts: Date.now(), models } )
		);
	} catch {
		// Ignore — the in-memory store still works without persistence.
	}
}
