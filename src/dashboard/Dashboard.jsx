import { __ } from '@wordpress/i18n';
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
							<div className="agent-mod-dashboard__logo-placeholder" />
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
										{ __( 'AgentMod is a WordPress AI agent platform that lets you configure AI assistants, connect to multiple AI providers, and extend functionality through a hook-based architecture. The free version provides the core AI chat infrastructure; AgentMod Pro adds conversation persistence, agent management, and advanced workspace features.', 'agent-mod' ) }
									</p>
								</div>
							</div>
							<DashboardSidebar />
						</div>
					</div>					
				</div>	
		</div>
		
	);
}
