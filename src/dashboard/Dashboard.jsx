import { __ } from '@wordpress/i18n';
import { dispatch } from '@wordpress/data';
import {
	Card,
	CardHeader,
	CardBody,
	Icon,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	ExternalLink,
	Button,
} from '@wordpress/components';

import {
	shield,
	check,
} from '@wordpress/icons';

function DashboardSidebar() {
	const links = [
		//{ label: __('Plugin Homepage', 'agent-mod'), url: 'https://agentmodwp.com' },
		{ label: __('GitHub Repository', 'agent-mod'), url: 'https://github.com/Arkenon/agent-mod' },
		{ label: __('Report a Bug', 'agent-mod'), url: 'https://wordpress.org/support/plugin/agent-mod' },
		{ label: __('Rate the Plugin', 'agent-mod'), url: 'https://wordpress.org/plugins/agent-mod/#reviews' },
	];

	return (
		<VStack spacing={4} style={{ width: '280px', flexShrink: 0 }}>
			<Card>
				<CardBody>
					<VStack spacing={3}>
						<div style={{ textAlign: 'center', padding: '8px 0' }}>
							<img
								src={window.agentModDashboard?.logoUrl || ''}
								alt="AgentMod"
								style={{ maxWidth: '100%', height: 'auto', display: 'block', margin: '0 auto', borderRadius: '6px' }}
							/>
						</div>
						<p style={{ margin: 0, fontSize: '13px', color: '#646970', lineHeight: 1.6 }}>
							{__('AgentMod brings AI-powered assistant capabilities to WordPress, connecting your site to AI providers through a clean, extensible hook system.', 'agent-mod')}
						</p>
					</VStack>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<strong style={{ fontSize: '11px', textTransform: 'uppercase', letterSpacing: '0.5px', color: '#1e1e1e' }}>
						{__('Quick Links', 'agent-mod')}
					</strong>
				</CardHeader>
				<CardBody>
					<VStack spacing={1}>
						{links.map((link, index) => (
							<ExternalLink key={index} href={link.url} style={{ fontSize: '13px', display: 'block' }}>
								{link.label}
							</ExternalLink>
						))}
					</VStack>
				</CardBody>
			</Card>
			<Card style={{
				background: 'linear-gradient(135deg, var(--wp-admin-theme-color, #3858e9) 0%, #7b2ff7 100%)',
				border: 'none'
			}}>
				<CardBody>
					<VStack spacing={3}>
						<HStack spacing={2} alignment="left">
							<Icon icon={shield} size={20} style={{ fill: '#fff' }} />
							<strong style={{ fontSize: '15px', color: '#fff' }}>
								{__('Go Pro', 'native-custom-fields')}
							</strong>
						</HStack>

						<p style={{ margin: 0, fontSize: '13px', color: 'rgba(255,255,255,0.85)', lineHeight: 1.6 }}>
							{__('Unlock advanced features and take your AI Agents to the next level.', 'agent-mod')}
						</p>

						<VStack spacing={1}>
							{[
								__('Multi Agent System', 'agent-mod'),
								__('Conversation History', 'agent-mod'),
								__('AI Skills', 'agent-mod'),
								__('Full-Screen Workspace', 'agent-mod'),
							].map((feature, i) => (
								<HStack key={i} spacing={2} alignment="left">
									<Icon icon={check} size={14} style={{ fill: '#fff', flexShrink: 0 }} />
									<span style={{ fontSize: '12px', color: 'rgba(255,255,255,0.9)' }}>{feature}</span>
								</HStack>
							))}
						</VStack>

						<Button
							style={{
								background: '#fff',
								color: 'var(--wp-admin-theme-color, #3858e9)',
								fontWeight: 600,
								width: '100%',
								justifyContent: 'center',
								border: 'none',
							}}
							href="https://checkout.freemius.com/plugin/35032/plan/57576/"
							target="_blank"
						>
							{__('Upgrade to Pro →', 'agent-mod')}
						</Button>
					</VStack>
				</CardBody>
			</Card>
			<Card>
				<CardBody>
					<VStack spacing={3} alignment="center">
						<p style={{ margin: 0, fontSize: '13px', color: '#646970', textAlign: 'center', lineHeight: 1.5 }}>
							{__('Enjoying the plugin? Consider giving it a ⭐ on WordPress.org!', 'agent-mod')}
						</p>
						<Button variant="primary" href="https://wordpress.org/support/plugin/agent-mod/reviews/#new-post" target="_blank">
							{__('Leave a Review', 'agent-mod')}
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
						<h1>{__('Welcome to AgentMod', 'agent-mod')}</h1>
						<p className="agent-mod-dashboard__subtitle">
							{__('AI-powered assistant platform for WordPress.', 'agent-mod')}
						</p>
					</div>

					<div className="agent-mod-dashboard__layout">
						<div className="agent-mod-dashboard__main">
							<div className="agent-mod-dashboard__description">
								<p>
									{__('AgentMod is a WordPress AI Agent Platform. Unlike ordinary chatbot plugins, it acts as an orchestration layer built on WordPress\'s native AI infrastructure — connecting your site to AI providers through a clean, extensible architecture.', 'agent-mod')}
								</p>

								<Button
									variant="primary"
									onClick={() => dispatch('agent-mod/chat').openChat()}
									style={{marginTop:15}}
								>
									{__('Chat with Your Agent', 'agent-mod')}
								</Button>

								<h3 style={{ marginTop: '32px', marginBottom: '16px', fontSize: '16px', fontWeight: 600, color: '#1e1e1e' }}>
									{__('Free vs. Pro Features', 'agent-mod')}
								</h3>

								<div style={{ marginBottom: '32px', overflow: 'hidden', borderRadius: '8px', border: '1px solid #e2e4e7', boxShadow: '0 1px 3px rgba(0,0,0,0.04)' }}>
									<table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '13px' }}>
										<thead>
											<tr style={{ backgroundColor: '#f6f7f7', borderBottom: '1px solid #e2e4e7' }}>
												<th style={{ padding: '12px 16px', fontWeight: 600, color: '#1e1e1e' }}>{__('Feature', 'agent-mod')}</th>
												<th style={{ padding: '12px 16px', fontWeight: 600, color: '#1e1e1e', width: '20%', textAlign: 'center' }}>{__('Free', 'agent-mod')}</th>
												<th style={{ padding: '12px 16px', fontWeight: 600, color: 'var(--wp-admin-theme-color, #3858e9)', width: '20%', textAlign: 'center' }}>{__('Pro', 'agent-mod')}</th>
											</tr>
										</thead>
										<tbody>
											{[
												{ feature: __('AI Engine & Orchestration', 'agent-mod'), free: true, pro: true },
												{ feature: __('Multiple Tool Calls', 'agent-mod'), free: true, pro: true },
												{ feature: __('Admin Chat Widget', 'agent-mod'), free: true, pro: true },
												{ feature: __('Safety Guardrails', 'agent-mod'), free: true, pro: true },
												{ feature: __('Hard Limits (Cost Control)', 'agent-mod'), free: true, pro: true },
												{ feature: __('Multi-Agent System', 'agent-mod'), free: false, pro: true },
												{ feature: __('Conversation History', 'agent-mod'), free: false, pro: true },
												{ feature: __('Custom AI Skills', 'agent-mod'), free: false, pro: true },
												{ feature: __('Full-Screen AI Workspace', 'agent-mod'), free: false, pro: true }
											].map((row, index) => (
												<tr key={index} style={{ borderBottom: index === 9 ? 'none' : '1px solid #f0f0f1', backgroundColor: index % 2 === 0 ? '#fff' : '#fafafa' }}>
													<td style={{ padding: '12px 16px', color: '#3c434a', fontWeight: row.free ? 400 : 500 }}>{row.feature}</td>
													<td style={{ padding: '12px 16px', textAlign: 'center' }}>
														{row.free ? <span style={{ color: '#00a32a', fontWeight: 'bold', fontSize: '16px' }}>✓</span> : <span style={{ color: '#8c8f94', fontWeight: 'bold' }}>—</span>}
													</td>
													<td style={{ padding: '12px 16px', textAlign: 'center' }}>
														{row.pro ? <span style={{ color: 'var(--wp-admin-theme-color, #3858e9)', fontWeight: 'bold', fontSize: '16px' }}>✓</span> : <span style={{ color: '#8c8f94', fontWeight: 'bold' }}>—</span>}
													</td>
												</tr>
											))}
										</tbody>
									</table>
								</div>

								
							</div>
						</div>
						<DashboardSidebar />
					</div>
				</div>
			</div>
		</div>

	);
}
