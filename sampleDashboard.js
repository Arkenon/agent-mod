import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
    Card,
    CardHeader,
    CardBody,
    __experimentalGrid as Grid,
    __experimentalVStack as VStack,
    __experimentalHStack as HStack,
    Icon,
    FlexItem,
    Flex,
    ExternalLink,
    Button,
} from '@wordpress/components';

import {
    lifesaver,
    bug,
    starFilled,
    globe,
    code,
    shield,
    check,
} from '@wordpress/icons';

import { withDashboardIcons, withIcons } from '@nativecustomfields/common/helper';

function DashboardCard({ title, description, icon, onClick }) {
    const [hovered, setHovered] = useState(false);

    return (
        <Card
            className="native-custom-fields-dashboard-card"
            onClick={onClick}
            onMouseEnter={() => setHovered(true)}
            onMouseLeave={() => setHovered(false)}
            style={{
                cursor: 'pointer',
                transition: 'box-shadow 0.2s ease, border-color 0.2s ease',
                borderColor: hovered ? 'var(--wp-admin-theme-color, #3858e9)' : undefined
            }}
        >
            <CardHeader
                style={{
                    background: hovered
                        ? 'linear-gradient(135deg, var(--wp-admin-theme-color, #3858e9) 0%, #7b2ff7 100%)'
                        : undefined,
                    transition: 'background 0.2s ease',
                }}
            >
                <Flex align="center" justify="flex-start" gap={3}>
                    <FlexItem style={{ display: 'flex', alignItems: 'center' }}>
                        <Icon icon={icon} size={24} style={{
                            fill: hovered ? '#fff' : 'var(--wp-admin-theme-color, #3858e9)',
                            transition: 'fill 0.2s ease'
                        }} />
                    </FlexItem>
                    <FlexItem style={{ display: 'flex', alignItems: 'center' }}>
                        <h3 className="native-custom-fields-card-title"
                            style={{ margin: 0, color: hovered ? '#fff' : undefined, transition: 'color 0.2s ease' }}>{title}</h3>
                    </FlexItem>
                </Flex>
            </CardHeader>
            <CardBody>
                <p>{description}</p>
            </CardBody>
        </Card>
    );
}

function DashboardSidebar() {
    const links = [
        {
            label: __('Documentation', 'native-custom-fields'),
            url: 'https://nativecustomfields.com/docs',
            icon: lifesaver
        },
        { label: __('Plugin Homepage', 'native-custom-fields'), url: 'https://nativecustomfields.com', icon: globe },
        {
            label: __('GitHub Repository', 'native-custom-fields'),
            url: 'https://github.com/Arkenon/native-custom-fields',
            icon: code
        },
        {
            label: __('Report a Bug', 'native-custom-fields'),
            url: 'https://github.com/Arkenon/native-custom-fields/issues',
            icon: bug
        },
        {
            label: __('Rate the Plugin', 'native-custom-fields'),
            url: 'https://wordpress.org/plugins/native-custom-fields/#reviews',
            icon: starFilled
        },
    ];

    return (
        <VStack spacing={4} style={{ width: '280px', flexShrink: 0 }}>
            <Card>
                <CardBody>
                    <VStack spacing={3}>
                        <div style={{ textAlign: 'center', padding: '8px 0' }}>
                            <img
                                src={`${window.nativeCustomFieldsData?.assets_url || ''}/img/ncf_logo.png`}
                                alt="Native Custom Fields"
                                style={{ maxWidth: '100%', height: 'auto', display: 'block', margin: '0 auto' }}
                            />
                        </div>

                        <p style={{ margin: 0, fontSize: '13px', color: '#646970', lineHeight: 1.6 }}>
                            {__('Create custom fields, post types, taxonomies and option pages using native WordPress Gutenberg components — no bloat, no dependencies.', 'native-custom-fields')}
                        </p>

                        <VStack spacing={2} style={{ borderTop: '1px solid #e2e4e7', paddingTop: '12px' }}>
                            <HStack>
                                <span style={{
                                    color: '#8c8f94',
                                    fontSize: '12px'
                                }}>{__('Author', 'native-custom-fields')}</span>
                                <ExternalLink href="https://profiles.wordpress.org/arkenon/" style={{ fontSize: '12px' }}>Kadim
                                    Gültekin</ExternalLink>
                            </HStack>
                            <HStack>
                                <span style={{
                                    color: '#8c8f94',
                                    fontSize: '12px'
                                }}>{__('License', 'native-custom-fields')}</span>
                                <ExternalLink href="https://www.gnu.org/licenses/gpl-2.0.html"
                                    style={{ fontSize: '12px' }}>GPL v2+</ExternalLink>
                            </HStack>
                        </VStack>
                    </VStack>
                </CardBody>
            </Card>

            {/* <Card style={{
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
                            {__('Unlock advanced features and take your custom fields to the next level.', 'native-custom-fields')}
                        </p>

                        <VStack spacing={1}>
                            {[
                                __('Options Pages & Fields', 'native-custom-fields'),
                                __('Import & Export', 'native-custom-fields'),
                                __('AI Integration & Abilities', 'native-custom-fields'),
                                __('Frontend Forms (Soon)', 'native-custom-fields'),
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
                            href="https://nativecustomfields.com/pro"
                            target="_blank"
                        >
                            {__('Upgrade to Pro →', 'native-custom-fields')}
                        </Button>
                    </VStack>
                </CardBody>
            </Card> */}

            <Card>
                <CardHeader>
                    <strong style={{
                        fontSize: '11px',
                        textTransform: 'uppercase',
                        letterSpacing: '0.5px',
                        color: '#1e1e1e'
                    }}>
                        {__('Quick Links', 'native-custom-fields')}
                    </strong>
                </CardHeader>
                <CardBody>
                    <VStack spacing={1}>
                        {links.map((link, index) => (
                            <HStack key={index} spacing={2} alignment="left">
                                <Icon icon={link.icon} size={16}
                                    style={{ fill: 'var(--wp-admin-theme-color, #3858e9)', flexShrink: 0 }} />
                                <ExternalLink href={link.url} style={{ fontSize: '13px' }}>
                                    {link.label}
                                </ExternalLink>
                            </HStack>
                        ))}
                    </VStack>
                </CardBody>
            </Card>

            <Card>
                <CardBody>
                    <VStack spacing={3} alignment="center">
                        <p style={{
                            margin: 0,
                            fontSize: '13px',
                            color: '#646970',
                            textAlign: 'center',
                            lineHeight: 1.5
                        }}>
                            {__('Enjoying the plugin? Consider giving it a ⭐ on WordPress.org!', 'native-custom-fields')}
                        </p>
                        <Button
                            variant="primary"
                            href="https://wordpress.org/plugins/native-custom-fields/#reviews"
                            target="_blank"
                        >
                            {__('Leave a Review', 'native-custom-fields')}
                        </Button>
                    </VStack>
                </CardBody>
            </Card>

        </VStack>
    );
}

function SupportedComponents() {
    const { field_types = [] } = window.nativeCustomFieldsData ?? {};

    const fieldTypes = withIcons(field_types);

    return (
        <Card style={{ marginTop: '24px' }}>
            <CardHeader>
                <strong
                    style={{ fontSize: '13px', textTransform: 'uppercase', letterSpacing: '0.5px', color: '#1e1e1e' }}>
                    {__('Supported Components', 'native-custom-fields')}
                </strong>
            </CardHeader>
            <CardBody>
                <div className="native-custom-fields-components-grid">
                    {fieldTypes.map((type) => (
                        <div key={type.value} className="native-custom-fields-component-item">
                            {type.icon && (
                                <Icon
                                    icon={type.icon}
                                    size={18}
                                    style={{ fill: 'var(--wp-admin-theme-color, #3858e9)', flexShrink: 0 }}
                                />
                            )}
                            <span>{type.label}</span>
                        </div>
                    ))}
                </div>
            </CardBody>
        </Card>
    );
}

function Dashboard() {
    const { dashboard_items = [] } = window.nativeCustomFieldsData ?? {};

    const dashboardItems = withDashboardIcons(dashboard_items).map(item => ({
        title: item.label,
        description: item.description,
        icon: item.icon,
        onClick: () => {
            window.location.href = `admin.php?page=${item.page}`;
        },
    }));

    return (
        <div className='native-custom-fields-app'>
            <div className="native-custom-fields-dashboard">
                <div className="native-custom-fields-dashboard-header">
                    <h1>{__('Welcome to Native Custom Fields', 'native-custom-fields')}</h1>
                    <p className="native-custom-fields-description">
                        {__('Create custom fields, post types, taxonomies and more with native WordPress components!', 'native-custom-fields')}
                    </p>
                </div>

                <div className="native-custom-fields-dashboard-layout">
                    <div className="native-custom-fields-dashboard-main">
                        <Grid columns={1} gap={5}>
                            {dashboardItems.map((item, index) => (
                                <DashboardCard
                                    key={index}
                                    title={item.title}
                                    description={item.description}
                                    icon={item.icon}
                                    onClick={item.onClick}
                                />
                            ))}
                        </Grid>
                        <SupportedComponents />
                    </div>

                    <DashboardSidebar />
                </div>
            </div>
        </div>
    );
}

export default Dashboard;