/**
 * AgentMod setup wizard.
 *
 * @package    AgentMod
 * @subpackage Setup
 * @since      1.3.0
 */
import AbilityList from '../ability-list/AbilityList';
import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Icon, layout, comment, widget, cloudDownload, people, starFilled, scheduled, code, pencil } from '@wordpress/icons';
import {
	Button,
	Card,
	CardBody,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
	FormTokenField,
} from '@wordpress/components';

const config = window.agentModSetup || {};
const NS = config.restNamespace || 'agent-mod/v1';

const STEPS = [
	{ id: 'welcome', label: __( 'Welcome', 'agent-mod' ) },
	{ id: 'provider', label: __( 'AI Provider', 'agent-mod' ) },
	{ id: 'persona', label: __( 'Agent', 'agent-mod' ) },
	{ id: 'features', label: __( 'Features', 'agent-mod' ) },
	{ id: 'prompts', label: __( 'Preset Prompts', 'agent-mod' ) },
	{ id: 'pro', label: __( 'Pro', 'agent-mod' ) },
	{ id: 'abilities', label: __( 'Abilities', 'agent-mod' ) },
	{ id: 'done', label: __( 'Done', 'agent-mod' ) },
];

const PRO_FEATURES = [
	{
		icon: layout,
		title: __( 'Full Screen AI Workspace', 'agent-mod' ),
		description: __( 'Manage all AI features in a distraction-free, full screen panel.', 'agent-mod' ),
	},
	{
		icon: people,
		title: __( 'Multiple Agents', 'agent-mod' ),
		description: __( 'Create and manage multiple AI agents, each with its own role and model.', 'agent-mod' ),
	},
	{
		icon: cloudDownload,
		title: __( 'AgentMod Hub', 'agent-mod' ),
		description: __( 'A shared library of ready-made skills and agents. Browse the collection and import what you need straight from GitHub, so you can start fast instead of building from scratch.', 'agent-mod' ),
	},
	{
		icon: starFilled,
		title: __( 'AI Skills', 'agent-mod' ),
		description: __( 'Add custom skills to your agents.', 'agent-mod' ),
	},
	{
		icon: comment,
		title: __( 'Conversation History', 'agent-mod' ),
		description: __( 'Retain previous conversations so agents can continue work with context, review prior decisions, and make every interaction easier to manage.', 'agent-mod' ),
	},
	{
		icon: widget,
		title: __( 'Frontend Widgets', 'agent-mod' ),
		description: __( 'Place intelligent site experiences where visitors need them—from contact forms and FAQs to helpful floating chat panels and more.', 'agent-mod' ),
	},
	{
		icon: scheduled,
		title: __( 'Scheduled Tasks', 'agent-mod' ),
		description: __( 'Create tasks that run automatically on the schedule you choose.', 'agent-mod' ),
	},
	{
		icon: code,
		title: __( 'Create Code Snippets', 'agent-mod' ),
		description: __( 'Let your agents save and manage code snippets.', 'agent-mod' ),
	},
	{
		icon: pencil,
		title: __( 'Edit Theme & Plugin Files', 'agent-mod' ),
		description: __( 'Allow the agent to edit theme and plugin files.', 'agent-mod' ),
	},
];

const post = ( path, data ) =>
	apiFetch( { path: `${ NS }/${ path }`, method: 'POST', data } );

// ---------------------------------------------------------------------------
// Steps
// ---------------------------------------------------------------------------
function WelcomeStep() {
	return (
		<>
			<h2>{ __( 'Welcome to AgentMod', 'agent-mod' ) }</h2>
			<p>
				{ __( 'AgentMod adds an AI assistant to your WordPress admin that can answer questions about your site and take actions for you, such as creating posts or managing content.', 'agent-mod' ) }
			</p>
			<p>{ __( 'Setup takes about two minutes:', 'agent-mod' ) }</p>
			<ol>
				<li>{ __( 'Connect an AI provider (needed so the assistant can think).', 'agent-mod' ) }</li>
				<li>{ __( 'Choose a few basic settings. Every one has a sensible default and can be skipped.', 'agent-mod' ) }</li>
				<li>{ __( 'See what the assistant is able to do.', 'agent-mod' ) }</li>
			</ol>
		</>
	);
}

function ProviderStep( { providers, loaded, recheck, checking } ) {
	const [ iframeLoaded, setIframeLoaded ] = useState( false );

	// Poll while no provider is connected, and re-check when the tab regains focus.
	useEffect( () => {
		if ( providers.length ) {
			return undefined;
		}
		const timer = setInterval( recheck, 5000 );
		window.addEventListener( 'focus', recheck );
		return () => {
			clearInterval( timer );
			window.removeEventListener( 'focus', recheck );
		};
	}, [ providers.length, recheck ] );

	if ( ! loaded ) {
		return <Spinner />;
	}

	if ( providers.length ) {
		return (
			<>
				<h2>{ __( 'AI provider connected', 'agent-mod' ) }</h2>
				<Notice status="success" isDismissible={ false }>
					{ __( 'Connected:', 'agent-mod' ) } <strong>{ providers.map( ( p ) => p.name ).join( ', ' ) }</strong>
				</Notice>
				<p>{ __( 'You are ready to go. You can add more providers any time from Settings → Connectors.', 'agent-mod' ) }</p>
			</>
		);
	}

	return (
		<>
			<h2>{ __( 'Connect an AI provider', 'agent-mod' ) }</h2>
			<p>
				{ __( 'AgentMod needs an AI provider (for example OpenAI, Anthropic or Google) to work. WordPress manages these connections on its Connectors screen:', 'agent-mod' ) }
			</p>
			<ol>
				<li>{ __( 'Pick one of the providers listed below.', 'agent-mod' ) }</li>
				<li>{ __( 'Follow the instructions there to get an API key from the provider.', 'agent-mod' ) }</li>
				<li>{ __( 'Paste the API key and save. This page detects it automatically.', 'agent-mod' ) }</li>
			</ol>
			<div className="agent-mod-setup__frame">
				{ ! iframeLoaded && <Spinner /> }
				<iframe
					title={ __( 'WordPress Connectors', 'agent-mod' ) }
					src={ config.connectorsUrl }
					onLoad={ () => setIframeLoaded( true ) }
				/>
			</div>
			<div className="agent-mod-setup__row">
				<Button variant="secondary" href={ config.connectorsUrl } target="_blank" rel="noopener noreferrer">
					{ __( 'Open Connectors in a new tab', 'agent-mod' ) }
				</Button>
				<Button variant="secondary" onClick={ recheck } isBusy={ checking }>
					{ __( 'I added a key – check again', 'agent-mod' ) }
				</Button>
			</div>
		</>
	);
}

function PersonaStep( { values, set } ) {
	return (
		<>
			<h2>{ __( 'Your agent', 'agent-mod' ) }</h2>
			<p>{ __( 'These are the defaults. Change them if you like, or just skip this step.', 'agent-mod' ) }</p>
			<TextareaControl label={ __( 'Role', 'agent-mod' ) } help={ __( 'What the agent is.', 'agent-mod' ) } rows={ 3 } value={ values.role } onChange={ ( v ) => set( { role: v } ) } />
			<TextareaControl label={ __( 'Goal', 'agent-mod' ) } help={ __( 'What the agent should achieve.', 'agent-mod' ) } rows={ 3 } value={ values.goal } onChange={ ( v ) => set( { goal: v } ) } />
			<TextareaControl label={ __( 'Base System Prompt', 'agent-mod' ) } help={ __( 'Core behaviour instructions sent with every message.', 'agent-mod' ) } rows={ 8 } value={ values.systemPrompt } onChange={ ( v ) => set( { systemPrompt: v } ) } />
			<FormTokenField
				label={ __( 'Personality Items', 'agent-mod' ) }
				value={ values.personalityTraits }
				suggestions={ config.traitSuggestions || [] }
				onChange={ ( v ) => set( { personalityTraits: v } ) }
				__experimentalExpandOnFocus
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
		</>
	);
}

function FeaturesStep( { values, set, models } ) {
	return (
		<>
			<h2>{ __( 'Features', 'agent-mod' ) }</h2>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Enable RAG (site context)', 'agent-mod' ) }
				help={ __( 'The agent automatically knows basic facts about your site (name, URL, WordPress version…).', 'agent-mod' ) }
				checked={ values.siteContextEnabled }
				onChange={ ( v ) => set( { siteContextEnabled: v } ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Enable Web Search', 'agent-mod' ) }
				help={ __( 'Lets the agent search the live web. Depends on the selected model.', 'agent-mod' ) }
				checked={ values.webSearchEnabled }
				onChange={ ( v ) => set( { webSearchEnabled: v } ) }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Default AI Model', 'agent-mod' ) }
				help={ __( 'Auto lets WordPress choose a model from your connected provider.', 'agent-mod' ) }
				value={ values.defaultModel }
				options={ [ { label: __( 'Auto', 'agent-mod' ), value: '' }, ...models ] }
				onChange={ ( v ) => set( { defaultModel: v } ) }
			/>
		</>
	);
}

function PresetPromptsStep( { values, set } ) {
	const prompts = values.presetPrompts;
	const update = ( i, patch ) => set( { presetPrompts: prompts.map( ( p, idx ) => ( idx === i ? { ...p, ...patch } : p ) ) } );

	return (
		<>
			<h2>{ __( 'Preset Prompts', 'agent-mod' ) }</h2>
			<p>{ __( 'Quick-start prompts shown above the chat input. Click one in the chat and its text fills the message box. Edit, remove or add your own, or skip to keep the defaults.', 'agent-mod' ) }</p>
			{ prompts.map( ( p, i ) => (
				<div className="agent-mod-setup__preset" key={ i }>
					<TextControl __next40pxDefaultSize __nextHasNoMarginBottom label={ __( 'Label', 'agent-mod' ) } value={ p.label } onChange={ ( v ) => update( i, { label: v } ) } />
					<TextareaControl __nextHasNoMarginBottom label={ __( 'Prompt', 'agent-mod' ) } rows={ 2 } value={ p.prompt } onChange={ ( v ) => update( i, { prompt: v } ) } />
					<Button variant="link" isDestructive onClick={ () => set( { presetPrompts: prompts.filter( ( _, idx ) => idx !== i ) } ) }>
						{ __( 'Remove', 'agent-mod' ) }
					</Button>
				</div>
			) ) }
			{ prompts.length < 10 && (
				<Button variant="secondary" onClick={ () => set( { presetPrompts: [ ...prompts, { label: '', prompt: '' } ] } ) }>
					{ __( 'Add prompt', 'agent-mod' ) }
				</Button>
			) }
		</>
	);
}

function ProStep() {
	return (
		<>
			<h2>{ __( 'More with Pro', 'agent-mod' ) }</h2>
			<p>{ __( 'These features are part of AgentMod Pro.', 'agent-mod' ) }</p>
			<div className="agent-mod-setup__grid">
				{ PRO_FEATURES.map( ( f ) => (
					<div className="agent-mod-setup__card" key={ f.title }>
						<div className="agent-mod-setup__card-top">
							<Icon icon={ f.icon } size={ 28 } />
							<a className="agent-mod-setup__badge" href={ config.proUrl } target="_blank" rel="noopener noreferrer">
								{ __( 'Get Pro', 'agent-mod' ) }
							</a>
						</div>
						<h3>{ f.title }</h3>
						<p>{ f.description }</p>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Pro feature', 'agent-mod' ) }
							checked={ false }
							// Locked: any attempt to switch it on sends the user to the Pro page.
							onChange={ () => window.open( config.proUrl, '_blank', 'noopener,noreferrer' ) }
						/>
					</div>
				) ) }
			</div>
		</>
	);
}

function AbilitiesStep() {
	return (
		<>
			<h2>{ __( 'What your agent can do', 'agent-mod' ) }</h2>
			<Notice className="agent-mod-setup__info" status="info" isDismissible={ false }>
				{ __( 'For information only. Each item below is an ability: a small action (a "tool") that the agent can run on its own, such as searching posts or creating a page. You do not have to do anything here.', 'agent-mod' ) }
			</Notice>
			<AbilityList embedded />
		</>
	);
}

function DoneStep( { providers } ) {
	return (
		<>
			<h2>{ __( 'You are all set!', 'agent-mod' ) }</h2>
			{ providers.length ? (
				<p>{ __( 'Click the AgentMod icon in the admin bar to start chatting with your agent.', 'agent-mod' ) }</p>
			) : (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'No AI provider is connected yet, so the agent cannot answer. Go back to the AI Provider step to connect one.', 'agent-mod' ) }
				</Notice>
			) }
			<p>{ __( 'You can change everything later under AgentMod → Settings.', 'agent-mod' ) }</p>
		</>
	);
}

// ---------------------------------------------------------------------------
// Wizard shell
// ---------------------------------------------------------------------------
export default function Setup() {
	const initial = Math.max( 0, STEPS.findIndex( ( s ) => s.id === config.initialStep ) );
	const [ index, setIndex ] = useState( initial );
	const [ providers, setProviders ] = useState( [] );
	const [ loaded, setLoaded ] = useState( false );
	const [ checking, setChecking ] = useState( false );
	const [ models, setModels ] = useState( [] );
	const [ values, setValues ] = useState( config.values || {} );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	const step = STEPS[ index ].id;
	const set = ( patch ) => setValues( ( v ) => ( { ...v, ...patch } ) );

	const recheck = useCallback( () => {
		setChecking( true );
		return apiFetch( { path: `${ NS }/providers` } )
			.then( ( list ) => setProviders( Array.isArray( list ) ? list : [] ) )
			.catch( () => setProviders( [] ) )
			.finally( () => {
				setLoaded( true );
				setChecking( false );
			} );
	}, [] );

	useEffect( () => {
		recheck();
	}, [ recheck ] );

	// Auto-advance once a provider shows up while waiting on the provider step.
	useEffect( () => {
		if ( 'provider' === step && providers.length && ! config.initialStep ) {
			setIndex( ( i ) => i + 1 );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ providers.length ] );

	useEffect( () => {
		if ( 'features' === step && providers.length ) {
			apiFetch( { path: `${ NS }/setup/models` } ).then( setModels ).catch( () => {} );
		}
	}, [ step, providers.length ] );

	const PERSONA_KEYS = [ 'role', 'goal', 'systemPrompt', 'personalityTraits' ];
	const PROMPT_KEYS = [ 'presetPrompts' ];
	const FEATURE_KEYS = [ 'siteContextEnabled', 'webSearchEnabled', 'defaultModel' ];

	const save = async ( keys ) => {
		const data = {};
		keys.forEach( ( k ) => {
			data[ k ] = values[ k ];
		} );
		setSaving( true );
		setError( '' );
		try {
			await post( 'setup/settings', data );
			return true;
		} catch ( e ) {
			setError( e?.message || __( 'Could not save settings.', 'agent-mod' ) );
			return false;
		} finally {
			setSaving( false );
		}
	};

	const next = async () => {
		if ( 'persona' === step && ! ( await save( PERSONA_KEYS ) ) ) {
			return;
		}
		if ( 'features' === step && ! ( await save( FEATURE_KEYS ) ) ) {
			return;
		}
		if ( 'prompts' === step && ! ( await save( PROMPT_KEYS ) ) ) {
			return;
		}
		setIndex( ( i ) => Math.min( i + 1, STEPS.length - 1 ) );
	};

	const finish = async () => {
		setSaving( true );
		try {
			await post( 'setup/complete', {} );
		} catch ( e ) {
			// Non-fatal: the reminder simply shows again.
		}
		window.location.href = config.dashboardUrl;
	};

	const skippable = [ 'persona', 'features', 'prompts', 'provider' ].includes( step );
	const nextLabel = {
		welcome: __( 'Get started', 'agent-mod' ),
		persona: __( 'Save & continue', 'agent-mod' ),
		features: __( 'Save & continue', 'agent-mod' ),
		prompts: __( 'Save & continue', 'agent-mod' ),
	}[ step ] || __( 'Continue', 'agent-mod' );

	return (
		<div className="agent-mod-setup">
			<header className="agent-mod-setup__header">
				{ config.logoUrl && <img src={ config.logoUrl } alt="" width="40" height="40" /> }
				<h1>{ __( 'AgentMod Setup', 'agent-mod' ) }</h1>
				<span className="agent-mod-setup__spacer" />
				<Button variant="tertiary" href={ config.dashboardUrl }>
					{ __( 'Exit setup', 'agent-mod' ) } ✕
				</Button>
			</header>
			<ol className="agent-mod-setup__steps">
				{ STEPS.map( ( s, i ) => (
					<li key={ s.id } className={ i === index ? 'is-current' : i < index ? 'is-done' : '' }>
						<span>{ i + 1 }</span> { s.label }
					</li>
				) ) }
			</ol>
			<Card>
				<CardBody>
					{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
					{ 'welcome' === step && <WelcomeStep /> }
					{ 'provider' === step && <ProviderStep providers={ providers } loaded={ loaded } recheck={ recheck } checking={ checking } /> }
					{ 'persona' === step && <PersonaStep values={ values } set={ set } /> }
					{ 'features' === step && <FeaturesStep values={ values } set={ set } models={ models } /> }
					{ 'prompts' === step && <PresetPromptsStep values={ values } set={ set } /> }
					{ 'pro' === step && <ProStep /> }
					{ 'abilities' === step && <AbilitiesStep /> }
					{ 'done' === step && <DoneStep providers={ providers } /> }
				</CardBody>
			</Card>
			<footer className="agent-mod-setup__nav">
				<Button variant="tertiary" disabled={ 0 === index || saving } onClick={ () => setIndex( index - 1 ) }>
					{ __( 'Back', 'agent-mod' ) }
				</Button>
				<span className="agent-mod-setup__spacer" />
				{ skippable && (
					<Button variant="tertiary" disabled={ saving } onClick={ () => setIndex( index + 1 ) }>
						{ 'provider' === step ? __( 'Skip for now', 'agent-mod' ) : __( 'Skip', 'agent-mod' ) }
					</Button>
				) }
				{ 'done' === step ? (
					<Button variant="primary" isBusy={ saving } onClick={ finish }>
						{ __( 'Finish', 'agent-mod' ) }
					</Button>
				) : (
					<Button variant="primary" isBusy={ saving } disabled={ 'provider' === step && ! providers.length } onClick={ next }>
						{ nextLabel }
					</Button>
				) }
			</footer>
		</div>
	);
}
