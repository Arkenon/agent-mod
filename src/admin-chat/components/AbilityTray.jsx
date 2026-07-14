/**
 * Ability tray (drop-up).
 *
 * Lists the abilities registered on the site (fetched lazily from the core
 * Abilities API endpoint and cached in the store). Picking one inserts an
 * "@ability-name" mention into the composer; on send the mentioned abilities
 * are emphasized in the system prompt so the assistant prefers them.
 *
 * The list mirrors what the assistant can actually call: in Ask/Plan modes
 * only read-only abilities are shown (the server resolves the tool list the
 * same way), in Execute mode every ability is shown.
 */
import { useState } from '@wordpress/element';
import { Dropdown, Button, TextControl, Spinner } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from '../store';

export default function AbilityTray( { onInsert, disabled } ) {
	const { fetchAbilities } = useDispatch( STORE_NAME );
	const [ search, setSearch ] = useState( '' );

	const { abilities, loading, selectedMode } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );
		return {
			abilities: storeSelect.getAbilities(),
			loading: storeSelect.isAbilitiesLoading(),
			selectedMode: storeSelect.getSelectedMode(),
		};
	}, [] );

	// Ask/Plan modes: only read-only abilities are callable, so only they are listed.
	const readonlyOnly = 'execute' !== selectedMode;
	const items = ( Array.isArray( abilities ) ? abilities : [] ).filter(
		( item ) => ! readonlyOnly || true === item.meta?.annotations?.readonly
	);

	const term = search.trim().toLowerCase();
	const filtered = term
		? items.filter( ( item ) =>
			( item.label || '' ).toLowerCase().includes( term ) ||
			( item.name || '' ).toLowerCase().includes( term )
		)
		: items;

	return (
		<Dropdown
			className="agent-mod-chat__ability-tray-picker"
			popoverProps={ { placement: 'top-start' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					variant="tertiary"
					size="small"
					icon="admin-tools"
					aria-expanded={ isOpen }
					disabled={ disabled }
					label={ __( 'Abilities', 'agent-mod' ) }
					onClick={ () => {
						if ( ! isOpen ) {
							fetchAbilities();
						}
						onToggle();
					} }
				>
					{ __( 'Abilities', 'agent-mod' ) }
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<div className="agent-mod-chat__ability-tray">
					<TextControl
						className="agent-mod-chat__ability-search"
						value={ search }
						onChange={ setSearch }
						placeholder={ __( 'Search abilities…', 'agent-mod' ) }
					/>

					<div className="agent-mod-chat__ability-list">
						{ loading && <Spinner /> }

						{ ! loading && 0 === filtered.length && (
							<p className="agent-mod-chat__ability-hint">
								{ __( 'No abilities found.', 'agent-mod' ) }
							</p>
						) }

						{ ! loading &&
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
