import {
	ICredentialType,
	INodeProperties,
} from 'n8n-workflow';

export class SkwirrelApi implements ICredentialType {
	name = 'skwirrelApi';
	displayName = 'Skwirrel API';
	documentationUrl = 'https://github.com/Skwirrel-B-V/skwirrel-woocommerce-sync';

	properties: INodeProperties[] = [
		{
			displayName: 'JSON-RPC Endpoint URL',
			name: 'endpoint',
			type: 'string',
			default: '',
			placeholder: 'https://xxx.skwirrel.eu/jsonrpc',
			description: 'Volledige URL naar het Skwirrel JSON-RPC endpoint',
			required: true,
		},
		{
			displayName: 'Authenticatie methode',
			name: 'authType',
			type: 'options',
			options: [
				{
					name: 'Bearer Token',
					value: 'bearer',
					description: 'Authorization: Bearer header',
				},
				{
					name: 'Static Token',
					value: 'token',
					description: 'X-Skwirrel-Api-Token header',
				},
			],
			default: 'bearer',
		},
		{
			displayName: 'API Token',
			name: 'apiToken',
			type: 'string',
			typeOptions: { password: true },
			default: '',
			required: true,
		},
		{
			displayName: 'Timeout (seconden)',
			name: 'timeout',
			type: 'number',
			default: 30,
			typeOptions: { minValue: 5, maxValue: 120 },
			description: 'HTTP timeout in seconden',
		},
	];
}
