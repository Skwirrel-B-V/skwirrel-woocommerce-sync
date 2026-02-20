import {
	IAuthenticateGeneric,
	ICredentialType,
	INodeProperties,
} from 'n8n-workflow';

export class SkwirrelWcSyncApi implements ICredentialType {
	name = 'skwirrelWcSyncApi';
	displayName = 'Skwirrel WC Sync API';
	documentationUrl = 'https://github.com/Skwirrel-B-V/skwirrel-woocommerce-sync';

	properties: INodeProperties[] = [
		{
			displayName: 'WordPress Site URL',
			name: 'baseUrl',
			type: 'string',
			default: '',
			placeholder: 'https://jouw-webshop.nl',
			description: 'De basis-URL van je WordPress site (zonder /wp-json)',
			required: true,
		},
		{
			displayName: 'Authenticatie methode',
			name: 'authMethod',
			type: 'options',
			options: [
				{
					name: 'REST API Key',
					value: 'restKey',
					description: 'Gebruik de Skwirrel REST API key uit de plugin instellingen',
				},
				{
					name: 'WordPress Application Password',
					value: 'applicationPassword',
					description: 'Gebruik WordPress Application Passwords (Basic Auth)',
				},
			],
			default: 'restKey',
		},
		{
			displayName: 'REST API Key',
			name: 'restApiKey',
			type: 'string',
			typeOptions: { password: true },
			default: '',
			description: 'Te vinden in WooCommerce → Skwirrel Sync → REST API sectie',
			displayOptions: {
				show: {
					authMethod: ['restKey'],
				},
			},
		},
		{
			displayName: 'WordPress Gebruikersnaam',
			name: 'username',
			type: 'string',
			default: '',
			description: 'WordPress gebruikersnaam met manage_woocommerce rechten',
			displayOptions: {
				show: {
					authMethod: ['applicationPassword'],
				},
			},
		},
		{
			displayName: 'Application Password',
			name: 'applicationPassword',
			type: 'string',
			typeOptions: { password: true },
			default: '',
			description: 'Aan te maken via WordPress → Gebruikers → Profiel → Application Passwords',
			displayOptions: {
				show: {
					authMethod: ['applicationPassword'],
				},
			},
		},
	];

	authenticate: IAuthenticateGeneric = {
		type: 'generic',
		properties: {},
	};
}
