import { __ } from '@wordpress/i18n';
import { dispatch } from '@wordpress/data';
import {
	Card,
	CardHeader,
	CardBody,
	__experimentalVStack as VStack,
	ExternalLink,
	Button,
} from '@wordpress/components';

function DashboardSidebar() {
	const links = [
		{ label: __( 'Documentation', 'agent-mod' ),    url: '#' },
		{ label: __( 'Plugin Homepage', 'agent-mod' ),  url: '#' },
		{ label: __( 'GitHub Repository', 'agent-mod' ), url: '#' },
		{ label: __( 'Report a Bug', 'agent-mod' ),     url: '#' },
		{ label: __( 'Rate the Plugin', 'agent-mod' ),  url: '#' },
	];

	return (
		<VStack spacing={ 4 } style={ { width: '280px', flexShrink: 0 } }>
			<Card>
				<CardBody>
					<VStack spacing={ 3 }>
						<div style={ { textAlign: 'center', padding: '8px 0' } }>
							<img
								src={ window.agentModDashboard?.logoUrl || '' }
								alt="AgentMod"
								style={ { maxWidth: '100%', height: 'auto', display: 'block', margin: '0 auto', borderRadius: '6px' } }
							/>
						</div>
						<p style={ { margin: 0, fontSize: '13px', color: '#646970', lineHeight: 1.6 } }>
							{ __( 'AgentMod brings AI-powered assistant capabilities to WordPress, connecting your site to AI providers through a clean, extensible hook system.', 'agent-mod' ) }
						</p>
					</VStack>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<strong style={ { fontSize: '11px', textTransform: 'uppercase', letterSpacing: '0.5px', color: '#1e1e1e' } }>
						{ __( 'Quick Links', 'agent-mod' ) }
					</strong>
				</CardHeader>
				<CardBody>
					<VStack spacing={ 1 }>
						{ links.map( ( link, index ) => (
							<ExternalLink key={ index } href={ link.url } style={ { fontSize: '13px', display: 'block' } }>
								{ link.label }
							</ExternalLink>
						) ) }
					</VStack>
				</CardBody>
			</Card>

			<Card>
				<CardBody>
					<VStack spacing={ 3 } alignment="center">
						<p style={ { margin: 0, fontSize: '13px', color: '#646970', textAlign: 'center', lineHeight: 1.5 } }>
							{ __( 'Enjoying the plugin? Consider giving it a ⭐ on WordPress.org!', 'agent-mod' ) }
						</p>
						<Button variant="primary" href="#" target="_blank">
							{ __( 'Leave a Review', 'agent-mod' ) }
						</Button>
					</VStack>
				</CardBody>
			</Card>
		</VStack>
	);
}

export default function Dashboard() {
	return (
		<div className="agent-mod-dashboard-container">
				<div className="agent-mod-dashboard">
					<div className="agent-mod-dashboard__app">
						<div className="agent-mod-dashboard__header">
							<h1>{ __( 'Welcome to AgentMod', 'agent-mod' ) }</h1>
							<p className="agent-mod-dashboard__subtitle">
								{ __( 'AI-powered assistant platform for WordPress.', 'agent-mod' ) }
							</p>
						</div>

						<div className="agent-mod-dashboard__layout">
							<div className="agent-mod-dashboard__main">
								<div className="agent-mod-dashboard__description">
									<p>
										{ __( 'AgentMod is a WordPress AI Agent Platform. Unlike ordinary chatbot plugins, it acts as an orchestration layer built on WordPress\'s native AI infrastructure — connecting your site to AI providers through a clean, extensible architecture.', 'agent-mod' ) }
									</p>

									<h3 style={ { marginTop: '24px', marginBottom: '12px', fontSize: '14px', fontWeight: 600, color: '#1e1e1e' } }>
										{ __( 'What\'s included in the Free version:', 'agent-mod' ) }
									</h3>

									<ul style={ { margin: '0 0 24px', paddingLeft: '20px', color: '#646970', fontSize: '14px', lineHeight: 1.8 } }>
										<li>{ __( 'AI Engine — Stateless, model-agnostic orchestration layer built on WordPress native AI Client.', 'agent-mod' ) }</li>
										<li>{ __( 'Multiple Tool Calls — Sequential and parallel ability execution in a single conversation turn.', 'agent-mod' ) }</li>
										<li>{ __( 'Admin Chat Widget — React-based modal, triggered from the Admin Bar.', 'agent-mod' ) }</li>
										<li>{ __( 'Read-only Abilities — Built-in site info and recent posts abilities for safe data retrieval.', 'agent-mod' ) }</li>
										<li>{ __( 'Context Scope Selector — Switch between "Site Context" (RAG) and "General Knowledge" per message.', 'agent-mod' ) }</li>
										<li>{ __( 'New Topic — Clear the conversation and start fresh at any time.', 'agent-mod' ) }</li>
										<li>{ __( 'Safety Guardrails — Destructive actions are blocked; the agent asks clarifying questions when unsure.', 'agent-mod' ) }</li>
										<li>{ __( 'Hard Limits — Max 20 results per search query, max 5 posts for full content reading, max 10 tool calls per turn.', 'agent-mod' ) }</li>
									</ul>

									<Button
										variant="primary"
										onClick={ () => dispatch( 'agent-mod/chat' ).openChat() }
									>
										{ __( 'Chat with Your Agent', 'agent-mod' ) }
									</Button>
								</div>
							</div>
							<DashboardSidebar />
						</div>
					</div>					
				</div>	
		</div>
		
	);
}
