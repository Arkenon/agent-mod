/**
 * Provider + model picker (drop-up).
 *
 * Lists the connected AI providers (from agentModChat.providers) in a menu that
 * opens upward. Selecting a provider lazily loads its text-generation models and
 * shows them in a side column; picking a model stores the provider+model pair,
 * which sendMessage forwards so the WP AI Client uses exactly that model.
 *
 * An "Auto" entry clears the selection and lets the AI Client auto-select.
 */
import { useEffect, useState } from '@wordpress/element';
import { Dropdown, Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from '../store';

export default function ProviderModelSelector() {
	const { fetchProviderModels, selectProviderModel } = useDispatch( STORE_NAME );

	const { selectedProvider, selectedModel, providers, connectorsUrl } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );
		return {
			selectedProvider: storeSelect.getSelectedProvider(),
			selectedModel:    storeSelect.getSelectedModel(),
			providers:        storeSelect.getProviders(),
			connectorsUrl:    storeSelect.getConnectorsUrl(),
		};
	}, [] );

	// Which provider's models are shown in the right-hand column.
	const [ activeProvider, setActiveProvider ] = useState( null );

	const { models, loading } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );
		return {
			models:  activeProvider ? storeSelect.getProviderModels( activeProvider ) : null,
			loading: activeProvider === storeSelect.getModelsLoading(),
		};
	}, [ activeProvider ] );

	// Warm the cache in the background as soon as the panel mounts, so the picker
	// shows models instantly (no spinner) when the user opens it. Providers whose
	// models are already cached (in the store or hydrated from localStorage) are a
	// no-op inside the thunk, so this is cheap to run on every open.
	useEffect( () => {
		providers.forEach( ( provider ) => fetchProviderModels( provider.id ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// No provider connected: link to the Connectors settings screen.
	if ( 0 === providers.length ) {
		return (
			<a
				className="agent-mod-chat__provider agent-mod-chat__provider--empty"
				href={ connectorsUrl || '#' }
				title={ __( 'No AI provider connected. Click to configure.', 'agent-mod' ) }
			>
				<span className="dashicons dashicons-warning" aria-hidden="true" />
				<span>{ __( 'No provider', 'agent-mod' ) }</span>
			</a>
		);
	}

	const openProvider = ( id ) => {
		setActiveProvider( id );
		fetchProviderModels( id );
	};

	const current     = providers.find( ( provider ) => provider.id === selectedProvider );
	const toggleLabel = current
		? ( selectedModel ? `${ current.name }: ${ selectedModel }` : current.name )
		: __( 'Auto model', 'agent-mod' );

	return (
		<Dropdown
			className="agent-mod-chat__provider-picker"
			popoverProps={ { placement: 'top-start' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					variant="tertiary"
					size="small"
					icon="superhero-alt"
					aria-expanded={ isOpen }
					onClick={ () => {
						if ( ! isOpen ) {
							openProvider( selectedProvider || providers[ 0 ].id );
						}
						onToggle();
					} }
				>
					{ toggleLabel }
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<div className="agent-mod-chat__provider-menu">
					<ul className="agent-mod-chat__provider-list">
						<li>
							<Button
								className={ ! selectedProvider ? 'is-selected' : '' }
								onClick={ () => {
									selectProviderModel( null, null );
									onClose();
								} }
							>
								{ __( 'Auto (default)', 'agent-mod' ) }
							</Button>
						</li>

						{ providers.map( ( provider ) => (
							<li key={ provider.id }>
								<Button
									className={ [
										activeProvider === provider.id ? 'is-active' : '',
										selectedProvider === provider.id ? 'is-selected' : '',
									].join( ' ' ).trim() }
									onClick={ () => openProvider( provider.id ) }
								>
									<span className="agent-mod-chat__provider-name">
										{ provider.name }
									</span>
									<span
										className="dashicons dashicons-arrow-right-alt2"
										aria-hidden="true"
									/>
								</Button>
							</li>
						) ) }
					</ul>

					<div className="agent-mod-chat__model-list">
						{ ! activeProvider && (
							<p className="agent-mod-chat__model-hint">
								{ __( 'Select a provider to see its models.', 'agent-mod' ) }
							</p>
						) }

						{ activeProvider && ! loading && Array.isArray( models ) &&
							0 === models.length && (
								<p className="agent-mod-chat__model-hint">
									{ __( 'No models found.', 'agent-mod' ) }
								</p>
							) }

						{ activeProvider && Array.isArray( models ) &&
							models.map( ( model ) => (
								<Button
									key={ model.id }
									className={
										selectedProvider === activeProvider &&
										selectedModel === model.id
											? 'is-selected'
											: ''
									}
									onClick={ () => {
										selectProviderModel( activeProvider, model.id );
										onClose();
									} }
								>
									{ model.name || model.id }
								</Button>
							) ) }
					</div>
				</div>
			) }
		/>
	);
}
