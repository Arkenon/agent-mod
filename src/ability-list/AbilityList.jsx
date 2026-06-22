/**
 * Abilities admin page — lists all registered WordPress abilities via DataViews.
 *
 * @package    AgentMod
 * @subpackage AbilityList
 * @since      1.0.0
 */
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { __ } from '@wordpress/i18n';

const DEFAULT_VIEW = {
	type: 'table',
	perPage: 20,
	page: 1,
	search: '',
	filters: [],
	sort: { field: 'label', direction: 'asc' },
	fields: [ 'label', 'category', 'description', 'readonly', 'destructive', 'idempotent' ],
	layout: {},
};

// ---------------------------------------------------------------------------
// Schema renderer — properties as table, raw JSON as fallback.
// ---------------------------------------------------------------------------
function SchemaTable( { schema } ) {
	if ( ! schema ) {
		return null;
	}

	const properties = schema.properties;

	if ( ! properties || Object.keys( properties ).length === 0 ) {
		return (
			<pre className="agent-mod-ability-details__json">
				{ JSON.stringify( schema, null, 2 ) }
			</pre>
		);
	}

	return (
		<table className="agent-mod-ability-details__schema-table">
			<thead>
				<tr>
					<th>{ __( 'Property', 'agent-mod' ) }</th>
					<th>{ __( 'Type', 'agent-mod' ) }</th>
					<th>{ __( 'Description', 'agent-mod' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ Object.entries( properties ).map( ( [ key, prop ] ) => (
					<tr key={ key }>
						<td>
							<code>{ key }</code>
						</td>
						<td>
							{ prop.format
								? `${ prop.type } (${ prop.format })`
								: prop.type || '—' }
						</td>
						<td>{ prop.description || '—' }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

// ---------------------------------------------------------------------------
// Details modal content — rendered by DataViews inside a WP Modal.
// ---------------------------------------------------------------------------
function AbilityDetailsModal( { items } ) {
	const item = items[ 0 ];
	if ( ! item ) {
		return null;
	}

	const annotations = item.meta?.annotations || {};

	return (
		<div className="agent-mod-ability-details">
			{ /* ── Basic info ── */ }
			<section className="agent-mod-ability-details__section">
				{ item.description && (
					<p className="agent-mod-ability-details__lead">
						{ item.description }
					</p>
				) }
				<dl className="agent-mod-ability-details__dl">
					<dt>{ __( 'Name', 'agent-mod' ) }</dt>
					<dd>
						<code>{ item.name }</code>
					</dd>
					<dt>{ __( 'Category', 'agent-mod' ) }</dt>
					<dd>{ item.category || '—' }</dd>
				</dl>
			</section>

			{ /* ── Annotations ── */ }
			<section className="agent-mod-ability-details__section">
				<h3 className="agent-mod-ability-details__heading">
					{ __( 'Annotations', 'agent-mod' ) }
				</h3>
				<dl className="agent-mod-ability-details__dl agent-mod-ability-details__dl--grid">
					<dt>{ __( 'Read-only', 'agent-mod' ) }</dt>
					<dd>{ annotations.readonly ? __( 'Yes', 'agent-mod' ) : __( 'No', 'agent-mod' ) }</dd>

					<dt>{ __( 'Destructive', 'agent-mod' ) }</dt>
					<dd>
						{ annotations.destructive ? (
							<span className="agent-mod-abilities__badge agent-mod-abilities__badge--danger">
								{ __( 'Yes', 'agent-mod' ) }
							</span>
						) : (
							__( 'No', 'agent-mod' )
						) }
					</dd>

					<dt>{ __( 'Idempotent', 'agent-mod' ) }</dt>
					<dd>{ annotations.idempotent ? __( 'Yes', 'agent-mod' ) : __( 'No', 'agent-mod' ) }</dd>

					{ annotations.instructions && (
						<>
							<dt>{ __( 'Instructions', 'agent-mod' ) }</dt>
							<dd>{ annotations.instructions }</dd>
						</>
					) }
				</dl>
			</section>

			{ /* ── Input schema ── */ }
			{ item.input_schema && (
				<section className="agent-mod-ability-details__section">
					<h3 className="agent-mod-ability-details__heading">
						{ __( 'Input Schema', 'agent-mod' ) }
					</h3>
					<SchemaTable schema={ item.input_schema } />
				</section>
			) }

			{ /* ── Output schema ── */ }
			{ item.output_schema && (
				<section className="agent-mod-ability-details__section">
					<h3 className="agent-mod-ability-details__heading">
						{ __( 'Output Schema', 'agent-mod' ) }
					</h3>
					<SchemaTable schema={ item.output_schema } />
				</section>
			) }
		</div>
	);
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
const ACTIONS = [
	{
		id: 'details',
		label: __( 'Details', 'agent-mod' ),
		isPrimary: true,
		modalHeader: ( items ) => items[ 0 ]?.label || __( 'Ability Details', 'agent-mod' ),
		modalSize: 'large',
		RenderModal: AbilityDetailsModal,
	},
];

// ---------------------------------------------------------------------------
// Field definitions
// ---------------------------------------------------------------------------
const FIELDS = [
	{
		id: 'label',
		label: __( 'Label', 'agent-mod' ),
		enableSorting: true,
		enableHiding: false,
		enableGlobalSearch: true,
		getValue: ( { item } ) => item.label || item.name || '',
	},
	{
		id: 'category',
		label: __( 'Category', 'agent-mod' ),
		enableSorting: true,
		enableGlobalSearch: true,
		getValue: ( { item } ) => item.category || '',
	},
	{
		id: 'description',
		label: __( 'Description', 'agent-mod' ),
		enableSorting: false,
		enableGlobalSearch: true,
		getValue: ( { item } ) => item.description || '',
		render: ( { item } ) => (
			<span
				className="agent-mod-abilities__description"
				title={ item.description || '' }
			>
				{ item.description || '—' }
			</span>
		),
	},
	{
		id: 'readonly',
		label: __( 'Read-only', 'agent-mod' ),
		enableSorting: true,
		enableGlobalSearch: false,
		getValue: ( { item } ) => item.meta?.annotations?.readonly ? 'Yes' : 'No',
		render: ( { item } ) =>
			item.meta?.annotations?.readonly
				? __( 'Yes', 'agent-mod' )
				: __( 'No', 'agent-mod' ),
	},
	{
		id: 'destructive',
		label: __( 'Destructive', 'agent-mod' ),
		enableSorting: true,
		enableGlobalSearch: false,
		getValue: ( { item } ) => item.meta?.annotations?.destructive ? 'Yes' : 'No',
		render: ( { item } ) =>
			item.meta?.annotations?.destructive ? (
				<span className="agent-mod-abilities__badge agent-mod-abilities__badge--danger">
					{ __( 'Yes', 'agent-mod' ) }
				</span>
			) : (
				__( 'No', 'agent-mod' )
			),
	},
	{
		id: 'idempotent',
		label: __( 'Idempotent', 'agent-mod' ),
		enableSorting: true,
		enableGlobalSearch: false,
		getValue: ( { item } ) => item.meta?.annotations?.idempotent ? 'Yes' : 'No',
		render: ( { item } ) =>
			item.meta?.annotations?.idempotent
				? __( 'Yes', 'agent-mod' )
				: __( 'No', 'agent-mod' ),
	},
];

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------
export default function AbilityList() {
	const [ abilities, setAbilities ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const config = window.agentModAbilityList || {};

	useEffect( () => {
		apiFetch( { path: config.abilitiesEndpoint || '/wp-abilities/v1/abilities' } )
			.then( ( data ) => {
				setAbilities( Array.isArray( data ) ? data : [] );
			} )
			.catch( ( err ) => {
				setError( err?.message || __( 'Failed to load abilities.', 'agent-mod' ) );
			} )
			.finally( () => setIsLoading( false ) );
	}, [] );

	const { data: processedData, paginationInfo } = filterSortAndPaginate(
		abilities,
		view,
		FIELDS
	);

	return (
		<div className="agent-mod-abilities">
			<h1 className="agent-mod-abilities__title">
				{ __( 'Abilities', 'agent-mod' ) }
			</h1>

			{ isLoading && <p>{ __( 'Loading…', 'agent-mod' ) }</p> }

			{ error && (
				<div className="notice notice-error is-dismissible">
					<p>{ error }</p>
				</div>
			) }

			{ ! isLoading && ! error && (
				<div className="notice notice-info agent-mod-abilities__notice">
					<p>
						{ __(
							'AgentMod can read and execute all abilities registered on WordPress. The list below is for informational purposes only. You can run the required ability tools by giving instructions to the AI assistant via the Chat panel.',
							'agent-mod'
						) }
					</p>
				</div>
			) }

			{ ! isLoading && ! error && (
				<DataViews
					data={ processedData }
					fields={ FIELDS }
					actions={ ACTIONS }
					view={ view }
					onChangeView={ setView }
					paginationInfo={ paginationInfo }
					getItemId={ ( item ) => item.name }
					defaultLayouts={ { table: {} } }
				/>
			) }
		</div>
	);
}
