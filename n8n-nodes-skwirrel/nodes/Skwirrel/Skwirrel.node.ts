import {
	IExecuteFunctions,
	INodeExecutionData,
	INodeType,
	INodeTypeDescription,
	NodeConnectionType,
} from 'n8n-workflow';
import { skwirrelJsonRpcCall } from './transport';

export class Skwirrel implements INodeType {
	description: INodeTypeDescription = {
		displayName: 'Skwirrel',
		name: 'skwirrel',
		icon: 'file:skwirrel.svg',
		group: ['transform'],
		version: 1,
		subtitle: '={{$parameter["operation"] + ": " + $parameter["resource"]}}',
		description: 'Haal producten, groepen en categorieën op uit het Skwirrel ERP/PIM systeem via de JSON-RPC API.',
		defaults: {
			name: 'Skwirrel',
		},
		inputs: [NodeConnectionType.Main],
		outputs: [NodeConnectionType.Main],
		credentials: [
			{
				name: 'skwirrelApi',
				required: true,
			},
		],
		properties: [
			// ────────── Resource ──────────
			{
				displayName: 'Resource',
				name: 'resource',
				type: 'options',
				noDataExpression: true,
				options: [
					{ name: 'Product', value: 'product' },
					{ name: 'Grouped Product', value: 'groupedProduct' },
					{ name: 'Verbinding', value: 'connection' },
				],
				default: 'product',
			},

			// ────────── Product Operations ──────────
			{
				displayName: 'Actie',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				displayOptions: { show: { resource: ['product'] } },
				options: [
					{
						name: 'Ophalen',
						value: 'getAll',
						description: 'Haal alle producten op (gepagineerd)',
						action: 'Haal producten op',
					},
					{
						name: 'Ophalen (filter)',
						value: 'getByFilter',
						description: 'Haal producten op met een filter (bijv. gewijzigd sinds datum)',
						action: 'Haal gefilterde producten op',
					},
				],
				default: 'getAll',
			},

			// ────────── Grouped Product Operations ──────────
			{
				displayName: 'Actie',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				displayOptions: { show: { resource: ['groupedProduct'] } },
				options: [
					{
						name: 'Ophalen',
						value: 'getAll',
						description: 'Haal gegroepeerde producten op (variabele producten met ETIM)',
						action: 'Haal gegroepeerde producten op',
					},
				],
				default: 'getAll',
			},

			// ────────── Connection Operations ──────────
			{
				displayName: 'Actie',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				displayOptions: { show: { resource: ['connection'] } },
				options: [
					{
						name: 'Testen',
						value: 'test',
						description: 'Test of de Skwirrel API bereikbaar is',
						action: 'Test de verbinding',
					},
				],
				default: 'test',
			},

			// ────────── Paginatie (product + groupedProduct) ──────────
			{
				displayName: 'Pagina',
				name: 'page',
				type: 'number',
				default: 1,
				typeOptions: { minValue: 1 },
				displayOptions: {
					show: {
						resource: ['product', 'groupedProduct'],
						operation: ['getAll', 'getByFilter'],
					},
				},
			},
			{
				displayName: 'Limiet',
				name: 'limit',
				type: 'number',
				default: 100,
				typeOptions: { minValue: 1, maxValue: 500 },
				description: 'Producten per pagina',
				displayOptions: {
					show: {
						resource: ['product', 'groupedProduct'],
						operation: ['getAll', 'getByFilter'],
					},
				},
			},
			{
				displayName: 'Alle pagina\'s ophalen',
				name: 'returnAll',
				type: 'boolean',
				default: false,
				description: 'Whether to return all results or only up to a given limit',
				displayOptions: {
					show: {
						resource: ['product', 'groupedProduct'],
						operation: ['getAll', 'getByFilter'],
					},
				},
			},

			// ────────── Filter-parameters (getByFilter) ──────────
			{
				displayName: 'Gewijzigd sinds',
				name: 'updatedSince',
				type: 'dateTime',
				default: '',
				required: true,
				description: 'Haal alleen producten op die gewijzigd zijn na deze datum/tijd (ISO 8601)',
				displayOptions: {
					show: { resource: ['product'], operation: ['getByFilter'] },
				},
			},

			// ────────── Include opties ──────────
			{
				displayName: 'Opties',
				name: 'options',
				type: 'collection',
				placeholder: 'Optie toevoegen',
				default: {},
				displayOptions: {
					show: {
						resource: ['product'],
						operation: ['getAll', 'getByFilter'],
					},
				},
				options: [
					{
						displayName: 'Productstatus meenemen',
						name: 'includeProductStatus',
						type: 'boolean',
						default: true,
					},
					{
						displayName: 'Vertalingen meenemen',
						name: 'includeProductTranslations',
						type: 'boolean',
						default: true,
					},
					{
						displayName: 'Bijlagen meenemen',
						name: 'includeAttachments',
						type: 'boolean',
						default: true,
					},
					{
						displayName: 'Handelsartikelen meenemen',
						name: 'includeTradeItems',
						type: 'boolean',
						default: true,
					},
					{
						displayName: 'Prijzen meenemen',
						name: 'includeTradeItemPrices',
						type: 'boolean',
						default: true,
					},
					{
						displayName: 'Categorieën meenemen',
						name: 'includeCategories',
						type: 'boolean',
						default: false,
					},
					{
						displayName: 'Productgroepen meenemen',
						name: 'includeProductGroups',
						type: 'boolean',
						default: false,
					},
					{
						displayName: 'Gegroepeerde producten meenemen',
						name: 'includeGroupedProducts',
						type: 'boolean',
						default: false,
					},
					{
						displayName: 'ETIM meenemen',
						name: 'includeEtim',
						type: 'boolean',
						default: true,
					},
					{
						displayName: 'ETIM vertalingen meenemen',
						name: 'includeEtimTranslations',
						type: 'boolean',
						default: true,
					},
					{
						displayName: 'Talen',
						name: 'includeLanguages',
						type: 'string',
						default: 'nl-NL,nl',
						description: 'Comma-separated taalcodes (bijv. nl-NL,nl,en)',
					},
					{
						displayName: 'Contexten',
						name: 'includeContexts',
						type: 'string',
						default: '1',
						description: 'Comma-separated context IDs',
					},
					{
						displayName: 'Collectie IDs',
						name: 'collectionIds',
						type: 'string',
						default: '',
						description: 'Comma-separated collectie IDs om te filteren (leeg = alles)',
					},
				],
			},

			// ────────── Include opties voor grouped products ──────────
			{
				displayName: 'Opties',
				name: 'groupedOptions',
				type: 'collection',
				placeholder: 'Optie toevoegen',
				default: {},
				displayOptions: {
					show: {
						resource: ['groupedProduct'],
						operation: ['getAll'],
					},
				},
				options: [
					{
						displayName: 'Producten meenemen',
						name: 'includeProducts',
						type: 'boolean',
						default: true,
					},
					{
						displayName: 'ETIM features meenemen',
						name: 'includeEtimFeatures',
						type: 'boolean',
						default: true,
					},
					{
						displayName: 'Collectie IDs',
						name: 'collectionIds',
						type: 'string',
						default: '',
						description: 'Comma-separated collectie IDs om te filteren (leeg = alles)',
					},
				],
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
				if (resource === 'product' && operation === 'getAll') {
					const results = await getProducts.call(this, i, false);
					for (const product of results) {
						returnData.push({ json: product as Record<string, unknown> });
					}
				} else if (resource === 'product' && operation === 'getByFilter') {
					const results = await getProducts.call(this, i, true);
					for (const product of results) {
						returnData.push({ json: product as Record<string, unknown> });
					}
				} else if (resource === 'groupedProduct' && operation === 'getAll') {
					const results = await getGroupedProducts.call(this, i);
					for (const group of results) {
						returnData.push({ json: group as Record<string, unknown> });
					}
				} else if (resource === 'connection' && operation === 'test') {
					const result = await skwirrelJsonRpcCall(this, 'getProducts', {
						page: 1,
						limit: 1,
						include_product_status: false,
						include_product_translations: false,
						include_attachments: false,
						include_trade_items: false,
						include_categories: false,
					});
					returnData.push({
						json: {
							success: true,
							message: 'Verbinding met Skwirrel API is geslaagd.',
							result,
						},
					});
				}
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

// ────────── Helpers ──────────

function parseCommaList(value: string): string[] {
	return value
		.split(',')
		.map((s) => s.trim())
		.filter(Boolean);
}

function parseNumericList(value: string): number[] {
	return parseCommaList(value)
		.map(Number)
		.filter((n) => !isNaN(n));
}

/**
 * Bouw de include-parameters voor getProducts / getProductsByFilter.
 */
function buildProductParams(ctx: IExecuteFunctions, i: number): Record<string, unknown> {
	const options = ctx.getNodeParameter('options', i, {}) as Record<string, unknown>;

	const params: Record<string, unknown> = {
		include_product_status: options.includeProductStatus !== false,
		include_product_translations: options.includeProductTranslations !== false,
		include_attachments: options.includeAttachments !== false,
		include_trade_items: options.includeTradeItems !== false,
		include_trade_item_prices: options.includeTradeItemPrices !== false,
		include_categories: options.includeCategories === true,
		include_product_groups: options.includeProductGroups === true,
		include_grouped_products: options.includeGroupedProducts === true,
		include_etim: options.includeEtim !== false,
		include_etim_translations: options.includeEtimTranslations !== false,
	};

	const langs = (options.includeLanguages as string) || 'nl-NL,nl';
	params.include_languages = parseCommaList(langs);

	const contexts = (options.includeContexts as string) || '1';
	params.include_contexts = parseNumericList(contexts);

	const collectionIds = (options.collectionIds as string) || '';
	if (collectionIds) {
		params.collection_ids = parseNumericList(collectionIds);
	}

	return params;
}

/**
 * Haal producten op via getProducts of getProductsByFilter, met optionele auto-paginatie.
 */
async function getProducts(
	this: IExecuteFunctions,
	itemIndex: number,
	useFilter: boolean,
): Promise<unknown[]> {
	const returnAll = this.getNodeParameter('returnAll', itemIndex, false) as boolean;
	const limit = this.getNodeParameter('limit', itemIndex, 100) as number;
	let page = this.getNodeParameter('page', itemIndex, 1) as number;
	const baseParams = buildProductParams(this, itemIndex);
	const allProducts: unknown[] = [];

	do {
		let result: unknown;

		if (useFilter) {
			const updatedSince = this.getNodeParameter('updatedSince', itemIndex) as string;
			const options = { ...baseParams };
			result = await skwirrelJsonRpcCall(this, 'getProductsByFilter', {
				filter: {
					updated_on: {
						datetime: updatedSince,
						operator: '>=',
					},
				},
				options,
				page,
				limit,
			});
		} else {
			result = await skwirrelJsonRpcCall(this, 'getProducts', {
				...baseParams,
				page,
				limit,
			});
		}

		const data = (result as Record<string, unknown>) || {};
		const products = (data.products as unknown[]) || [];
		allProducts.push(...products);

		// Stop als we niet alle pagina's hoeven
		if (!returnAll || products.length < limit) {
			break;
		}

		// Controleer paginatie info
		const pageInfo = (data.page as Record<string, unknown>) || {};
		const totalPages = Number(pageInfo.number_of_pages || 1);
		if (page >= totalPages) {
			break;
		}

		page++;
	} while (returnAll);

	return allProducts;
}

/**
 * Haal gegroepeerde producten op via getGroupedProducts, met optionele auto-paginatie.
 */
async function getGroupedProducts(
	this: IExecuteFunctions,
	itemIndex: number,
): Promise<unknown[]> {
	const returnAll = this.getNodeParameter('returnAll', itemIndex, false) as boolean;
	const limit = this.getNodeParameter('limit', itemIndex, 100) as number;
	let page = this.getNodeParameter('page', itemIndex, 1) as number;
	const options = this.getNodeParameter('groupedOptions', itemIndex, {}) as Record<string, unknown>;
	const allGroups: unknown[] = [];

	do {
		const params: Record<string, unknown> = {
			page,
			limit,
			include_products: options.includeProducts !== false,
			include_etim_features: options.includeEtimFeatures !== false,
		};

		const collectionIds = (options.collectionIds as string) || '';
		if (collectionIds) {
			params.collection_ids = parseNumericList(collectionIds);
		}

		const result = await skwirrelJsonRpcCall(this, 'getGroupedProducts', params);

		const data = (result as Record<string, unknown>) || {};
		const groups =
			(data.grouped_products as unknown[]) ||
			(data.groups as unknown[]) ||
			(data.products as unknown[]) ||
			[];
		allGroups.push(...groups);

		if (!returnAll || groups.length < limit) {
			break;
		}

		const pageInfo = (data.page as Record<string, unknown>) || {};
		const totalPages = Number(pageInfo.number_of_pages || 1);
		if (page >= totalPages) {
			break;
		}

		page++;
	} while (returnAll);

	return allGroups;
}
