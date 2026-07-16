/**
 * Ability tray (drop-up).
 *
 * Lists abilities the assistant can call. Picking one inserts an
 * "@ability-name" mention into the composer; on send the mentioned abilities
 * are emphasized in the system prompt so the assistant prefers them.
 *
 * Data source depends on the "Ability Source" setting:
 * - "Selected Abilities": the list is already fully known from settings
 *   (localized server-side via wp_get_ability(), which — unlike the REST
 *   abilities list — is not subject to the show_in_rest visibility
 *   restriction). No request is made at all.
 * - "All Abilities": the full site-wide list is loaded lazily via the
 *   official @wordpress/abilities client, bootstrapped on first open by
 *   dynamically importing @wordpress/core-abilities.
 *
 * The list mirrors what the assistant can actually call: in Ask/Plan modes
 * only read-only abilities are shown (the server resolves the tool list the
 * same way), in Execute mode every eligible ability is shown.
 *
 * The open state and search term can both be driven externally (see
 * `forceOpen`/`search` props) so Composer.jsx can open and filter this same
 * tray when the user types "@" directly into the message textarea, instead
 * of duplicating the ability list/filtering logic there.
 */
import { useEffect, useState } from '@wordpress/element';
import { Dropdown, Button, TextControl, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { store as abilitiesStore } from '@wordpress/abilities';

import { STORE_NAME } from '../store';

export default function AbilityTray( {
	onInsert,
	disabled,
	search: controlledSearch,
	onSearchChange,
	forceOpen = false,
	onForceOpenChange,
} ) {
	const [ internalSearch, setInternalSearch ] = useState( '' );
	const [ manualOpen, setManualOpen ]         = useState( false );
	const [ bootstrapped, setBootstrapped ]     = useState( false );
	const [ loadingAll, setLoadingAll ]         = useState( false );

	// Controlled when a `search` prop is passed (composer-driven "@" filter);
	// otherwise the tray manages its own search box as before.
	const isSearchControlled = undefined !== controlledSearch;
	const search    = isSearchControlled ? controlledSearch : internalSearch;
	const setSearch = isSearchControlled ? ( onSearchChange || ( () => {} ) ) : setInternalSearch;

	const isOpen = forceOpen || manualOpen;

	const { selectedMode, abilitySource, selectedAbilities, allAbilities } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );
		return {
			selectedMode: storeSelect.getSelectedMode(),
			abilitySource: storeSelect.getAbilitySource(),
			selectedAbilities: storeSelect.getSelectedAbilities(),
			allAbilities: select( abilitiesStore ).getAbilities(),
		};
	}, [] );

	const selectedOnly = 'selected' === abilitySource;
	const source        = selectedOnly ? selectedAbilities : allAbilities;

	// Ask/Plan modes: only read-only abilities are callable, so only they are listed.
	const readonlyOnly = 'execute' !== selectedMode;
	const items = source.filter(
		( item ) => ! readonlyOnly || true === item.meta?.annotations?.readonly
	);

	const term = search.trim().toLowerCase();
	const filtered = term
		? items.filter( ( item ) =>
			( item.label || '' ).toLowerCase().includes( term ) ||
			( item.name || '' ).toLowerCase().includes( term )
		)
		: items;

	// The full site-wide list is only needed for "All Abilities"; loaded lazily
	// (once) whenever the tray opens — whether via the toolbar button or a
	// composer-driven "@" trigger — so "Selected Abilities" never triggers a
	// request.
	useEffect( () => {
		if ( ! isOpen || selectedOnly || bootstrapped ) {
			return;
		}
		setBootstrapped( true );
		setLoadingAll( true );
		( async () => {
			try {
				const { initialize } = await import( '@wordpress/core-abilities' );
				await initialize();
			} finally {
				setLoadingAll( false );
			}
		} )();
	}, [ isOpen, selectedOnly, bootstrapped ] );

	return (
		<Dropdown
			className="agent-mod-chat__ability-tray-picker"
			popoverProps={ { placement: 'top-start' } }
			open={ isOpen }
			onToggle={ ( willOpen ) => {
				setManualOpen( willOpen );
				if ( ! willOpen ) {
					onForceOpenChange?.( false );
				}
			} }
			renderToggle={ ( { isOpen: toggleOpen, onToggle } ) => (
				<Button
					variant="tertiary"
					size="small"
					icon="admin-tools"
					aria-expanded={ toggleOpen }
					disabled={ disabled }
					label={ __( 'Abilities', 'agent-mod' ) }
					onClick={ onToggle }
				>
					{ __( 'Abilities', 'agent-mod' ) }
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<div className="agent-mod-chat__ability-tray">
					<p className="agent-mod-chat__ability-tray-title">
						{ __( 'Highlight Abilities', 'agent-mod' ) }
					</p>

					<TextControl
						className="agent-mod-chat__ability-search"
						value={ search }
						onChange={ setSearch }
						placeholder={ __( 'Search abilities…', 'agent-mod' ) }
					/>

					<div className="agent-mod-chat__ability-list">
						{ loadingAll && <Spinner /> }

						{ ! loadingAll && 0 === filtered.length && (
							<p className="agent-mod-chat__ability-hint">
								{ __( 'No abilities found.', 'agent-mod' ) }
							</p>
						) }

						{ ! loadingAll &&
							filtered.map( ( item ) => (
								<Button
									key={ item.name }
									className="agent-mod-chat__ability-item"
									onClick={ () => {
										onInsert( item.name );
										onClose();
									} }
								>
									<span className="agent-mod-chat__ability-label">
										{ item.label || item.name }
										{ item.meta?.annotations?.readonly && (
											<span className="agent-mod-chat__ability-badge">
												{ __( 'Read-only', 'agent-mod' ) }
											</span>
										) }
									</span>
									<code className="agent-mod-chat__ability-name">
										@{ item.name }
									</code>
								</Button>
							) ) }
					</div>
				</div>
			) }
		/>
	);
}
