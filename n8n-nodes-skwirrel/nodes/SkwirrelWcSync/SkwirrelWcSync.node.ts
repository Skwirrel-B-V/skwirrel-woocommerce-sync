import {
	IExecuteFunctions,
	INodeExecutionData,
	INodeType,
	INodeTypeDescription,
	IRequestOptions,
	NodeConnectionType,
} from 'n8n-workflow';

export class SkwirrelWcSync implements INodeType {
	description: INodeTypeDescription = {
		displayName: 'Skwirrel WC Sync',
		name: 'skwirrelWcSync',
		icon: 'file:skwirrel.svg',
		group: ['transform'],
		version: 1,
		subtitle: '={{$parameter["operation"] + ": " + $parameter["resource"]}}',
		description: 'Beheer Skwirrel WooCommerce Sync — trigger syncs, bekijk resultaten en monitor producten.',
		defaults: {
			name: 'Skwirrel WC Sync',
		},
		inputs: [NodeConnectionType.Main],
		outputs: [NodeConnectionType.Main],
		credentials: [
			{
				name: 'skwirrelWcSyncApi',
				required: true,
			},
		],
		properties: [
			// ------ Resource ------
			{
				displayName: 'Resource',
				name: 'resource',
				type: 'options',
				noDataExpression: true,
				options: [
					{ name: 'Sync', value: 'sync', description: 'Synchronisatie starten en monitoren' },
					{ name: 'Verbinding', value: 'connection', description: 'API verbinding testen' },
					{ name: 'Producten', value: 'products', description: 'Gesynchroniseerde producten ophalen' },
					{ name: 'Instellingen', value: 'settings', description: 'Plugin instellingen ophalen' },
				],
				default: 'sync',
			},

			// ------ Sync Operations ------
			{
				displayName: 'Actie',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				displayOptions: { show: { resource: ['sync'] } },
				options: [
					{ name: 'Starten', value: 'trigger', description: 'Start een volledige of delta sync', action: 'Start een sync' },
					{ name: 'Status', value: 'status', description: 'Controleer of er een sync draait', action: 'Haal sync status op' },
					{ name: 'Laatste resultaat', value: 'lastResult', description: 'Haal het laatste sync resultaat op', action: 'Haal laatste resultaat op' },
					{ name: 'Geschiedenis', value: 'history', description: 'Bekijk de sync geschiedenis', action: 'Haal sync geschiedenis op' },
				],
				default: 'trigger',
			},
			{
				displayName: 'Sync modus',
				name: 'syncMode',
				type: 'options',
				displayOptions: { show: { resource: ['sync'], operation: ['trigger'] } },
				options: [
					{ name: 'Volledig', value: 'full', description: 'Alle producten ophalen en synchroniseren' },
					{ name: 'Delta', value: 'delta', description: 'Alleen gewijzigde producten sinds laatste sync' },
				],
				default: 'full',
			},
			{
				displayName: 'Limiet',
				name: 'historyLimit',
				type: 'number',
				displayOptions: { show: { resource: ['sync'], operation: ['history'] } },
				default: 20,
				typeOptions: { minValue: 1, maxValue: 100 },
				description: 'Maximum aantal geschiedenis-items om op te halen',
			},

			// ------ Connection Operations ------
			{
				displayName: 'Actie',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				displayOptions: { show: { resource: ['connection'] } },
				options: [
					{ name: 'Testen', value: 'test', description: 'Test de verbinding met de Skwirrel API', action: 'Test de verbinding' },
				],
				default: 'test',
			},

			// ------ Products Operations ------
			{
				displayName: 'Actie',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				displayOptions: { show: { resource: ['products'] } },
				options: [
					{ name: 'Ophalen', value: 'getAll', description: 'Haal gesynchroniseerde producten op', action: 'Haal producten op' },
				],
				default: 'getAll',
			},
			{
				displayName: 'Pagina',
				name: 'page',
				type: 'number',
				displayOptions: { show: { resource: ['products'], operation: ['getAll'] } },
				default: 1,
				typeOptions: { minValue: 1 },
				description: 'Paginanummer',
			},
			{
				displayName: 'Per pagina',
				name: 'perPage',
				type: 'number',
				displayOptions: { show: { resource: ['products'], operation: ['getAll'] } },
				default: 20,
				typeOptions: { minValue: 1, maxValue: 100 },
				description: 'Aantal producten per pagina',
			},

			// ------ Settings Operations ------
			{
				displayName: 'Actie',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				displayOptions: { show: { resource: ['settings'] } },
				options: [
					{ name: 'Ophalen', value: 'get', description: 'Haal de plugin instellingen op', action: 'Haal instellingen op' },
				],
				default: 'get',
			},
		],
	};

	async execute(this: IExecuteFunctions): Promise<INodeExecutionData[][]> {
		const items = this.getInputData();
		const returnData: INodeExecutionData[] = [];

		const resource = this.getNodeParameter('resource', 0) as string;
		const operation = this.getNodeParameter('operation', 0) as string;

		for (let i = 0; i < items.length; i++) {
			try {
				let responseData: object;

				if (resource === 'sync') {
					if (operation === 'trigger') {
						const mode = this.getNodeParameter('syncMode', i) as string;
						responseData = await skwirrelApiRequest.call(this, 'POST', '/sync', { mode });
					} else if (operation === 'status') {
						responseData = await skwirrelApiRequest.call(this, 'GET', '/sync/status');
					} else if (operation === 'lastResult') {
						responseData = await skwirrelApiRequest.call(this, 'GET', '/sync/last-result');
					} else if (operation === 'history') {
						const limit = this.getNodeParameter('historyLimit', i, 20) as number;
						responseData = await skwirrelApiRequest.call(this, 'GET', `/sync/history?limit=${limit}`);
					} else {
						throw new Error(`Onbekende operatie: ${operation}`);
					}
				} else if (resource === 'connection') {
					responseData = await skwirrelApiRequest.call(this, 'POST', '/connection/test');
				} else if (resource === 'products') {
					const page = this.getNodeParameter('page', i, 1) as number;
					const perPage = this.getNodeParameter('perPage', i, 20) as number;
					responseData = await skwirrelApiRequest.call(
						this, 'GET', `/products?page=${page}&per_page=${perPage}`,
					);
				} else if (resource === 'settings') {
					responseData = await skwirrelApiRequest.call(this, 'GET', '/settings');
				} else {
					throw new Error(`Onbekende resource: ${resource}`);
				}

				returnData.push({ json: responseData as Record<string, unknown> });
			} catch (error) {
				if (this.continueOnFail()) {
					returnData.push({
						json: { error: (error as Error).message },
						pairedItem: { item: i },
					});
					continue;
				}
				throw error;
			}
		}

		return [returnData];
	}
}

/**
 * Voer een API request uit naar de Skwirrel WC Sync REST API.
 */
async function skwirrelApiRequest(
	this: IExecuteFunctions,
	method: string,
	endpoint: string,
	body?: object,
): Promise<object> {
	const credentials = await this.getCredentials('skwirrelWcSyncApi');

	const baseUrl = (credentials.baseUrl as string).replace(/\/+$/, '');
	const url = `${baseUrl}/wp-json/skwirrel-wc-sync/v1${endpoint}`;

	const options: IRequestOptions = {
		method,
		url,
		json: true,
	};

	if (body && method !== 'GET') {
		options.body = body;
	}

	// Authenticatie headers instellen
	const authMethod = credentials.authMethod as string;

	if (authMethod === 'restKey') {
		options.headers = {
			'X-Skwirrel-Rest-Key': credentials.restApiKey as string,
		};
	} else if (authMethod === 'applicationPassword') {
		const username = credentials.username as string;
		const password = credentials.applicationPassword as string;
		const token = Buffer.from(`${username}:${password}`).toString('base64');
		options.headers = {
			Authorization: `Basic ${token}`,
		};
	}

	return await this.helpers.request(options);
}
