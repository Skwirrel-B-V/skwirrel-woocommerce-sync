import type {
	IExecuteFunctions,
	INodeExecutionData,
	INodeType,
	INodeTypeDescription,
	IDataObject,
} from 'n8n-workflow';
import { skwirrelJsonRpcCall, parseCommaList, parseNumericList } from './transport';

export class Skwirrel implements INodeType {
	description: INodeTypeDescription = {
		displayName: 'Skwirrel',
		name: 'skwirrel',
		icon: 'file:skwirrel.svg',
		group: ['transform'],
		version: 1,
		subtitle: '={{$parameter["operation"] + " " + $parameter["resource"]}}',
		description: 'Skwirrel ERP/PIM — haal producten, groepen, categorieën, media, ETIM en meer op via de JSON-RPC API.',
		defaults: {
			name: 'Skwirrel',
		},
		inputs: ['main'] as any,
		outputs: ['main'] as any,
		credentials: [
			{
				name: 'skwirrelApi',
				required: true,
			},
		],
		properties: [
			// ═══════════════════════════════════════════
			// Resource
			// ═══════════════════════════════════════════
			{
				displayName: 'Resource',
				name: 'resource',
				type: 'options',
				noDataExpression: true,
				options: [
					{ name: 'Product', value: 'product' },
					{ name: 'Grouped Product', value: 'groupedProduct' },
					{ name: 'Verbinding', value: 'connection' },
					{ name: 'Custom API Call', value: 'custom' },
				],
				default: 'product',
			},

			// ═══════════════════════════════════════════
			// Product Operations
			// ═══════════════════════════════════════════
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
						description: 'Haal producten op via getProducts (gepagineerd)',
						action: 'Haal producten op',
					},
					{
						name: 'Ophalen (filter)',
						value: 'getByFilter',
						description: 'Haal producten op via getProductsByFilter (bijv. gewijzigd sinds datum)',
						action: 'Haal gefilterde producten op',
					},
				],
				default: 'getAll',
			},

			// ═══════════════════════════════════════════
			// Grouped Product Operations
			// ═══════════════════════════════════════════
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
						description: 'Haal gegroepeerde producten op via getGroupedProducts (variabele producten met ETIM)',
						action: 'Haal gegroepeerde producten op',
					},
				],
				default: 'getAll',
			},

			// ═══════════════════════════════════════════
			// Connection Operations
			// ═══════════════════════════════════════════
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

			// ═══════════════════════════════════════════
			// Custom API Call Operations
			// ═══════════════════════════════════════════
			{
				displayName: 'Actie',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				displayOptions: { show: { resource: ['custom'] } },
				options: [
					{
						name: 'JSON-RPC Call',
						value: 'call',
						description: 'Voer een willekeurige JSON-RPC methode uit op de Skwirrel API',
						action: 'Voer een custom API call uit',
					},
				],
				default: 'call',
			},
			{
				displayName: 'Methode',
				name: 'customMethod',
				type: 'string',
				default: '',
				required: true,
				placeholder: 'getProducts',
				description: 'JSON-RPC methode naam',
				displayOptions: {
					show: { resource: ['custom'], operation: ['call'] },
				},
			},
			{
				displayName: 'Parameters (JSON)',
				name: 'customParams',
				type: 'json',
				default: '{}',
				description: 'JSON object met parameters voor de methode',
				displayOptions: {
					show: { resource: ['custom'], operation: ['call'] },
				},
			},

			// ═══════════════════════════════════════════
			// Paginatie (product + groupedProduct)
			// ═══════════════════════════════════════════
			{
				displayName: 'Alle pagina\'s ophalen',
				name: 'returnAll',
				type: 'boolean',
				default: false,
				description: 'Whether to return all results or only up to a given limit',
				displayOptions: {
					show: {
						resource: ['product', 'groupedProduct'],
					},
				},
			},
			{
				displayName: 'Pagina',
				name: 'page',
				type: 'number',
				default: 1,
				typeOptions: { minValue: 1 },
				displayOptions: {
					show: {
						resource: ['product', 'groupedProduct'],
						returnAll: [false],
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
						returnAll: [false],
					},
				},
			},

			// ═══════════════════════════════════════════
			// Filter: gewijzigd sinds (getProductsByFilter)
			// ═══════════════════════════════════════════
			{
				displayName: 'Gewijzigd sinds',
				name: 'updatedSince',
				type: 'dateTime',
				default: '',
				required: true,
				description: 'Producten gewijzigd op of na deze datum/tijd (ISO 8601)',
				displayOptions: {
					show: { resource: ['product'], operation: ['getByFilter'] },
				},
			},
			{
				displayName: 'Filter operator',
				name: 'filterOperator',
				type: 'options',
				default: '>=',
				options: [
					{ name: '>= (op of na)', value: '>=' },
					{ name: '> (na)', value: '>' },
					{ name: '<= (op of voor)', value: '<=' },
					{ name: '< (voor)', value: '<' },
					{ name: '== (exact)', value: '==' },
				],
				displayOptions: {
					show: { resource: ['product'], operation: ['getByFilter'] },
				},
			},

			// ═══════════════════════════════════════════
			// Product include-opties
			// ═══════════════════════════════════════════
			{
				displayName: 'Include opties',
				name: 'includes',
				type: 'collection',
				placeholder: 'Optie toevoegen',
				default: {},
				displayOptions: {
					show: {
						resource: ['product'],
					},
				},
				options: [
					{
						displayName: 'Productstatus',
						name: 'includeProductStatus',
						type: 'boolean',
						default: true,
						description: 'Whether to include product status (_product_status met product_status_description)',
					},
					{
						displayName: 'Vertalingen',
						name: 'includeProductTranslations',
						type: 'boolean',
						default: true,
						description: 'Whether to include product translations (_product_translations met product_model, product_description, product_long_description, etc.)',
					},
					{
						displayName: 'Bijlagen (afbeeldingen & documenten)',
						name: 'includeAttachments',
						type: 'boolean',
						default: true,
						description: 'Whether to include attachments like images (IMG, PPI, PHI, LOG, SCH, PRT, OTV) and documents (MAN, DAT, CER, WAR)',
					},
					{
						displayName: 'Handelsartikelen',
						name: 'includeTradeItems',
						type: 'boolean',
						default: true,
						description: 'Whether to include trade items (_trade_items)',
					},
					{
						displayName: 'Prijzen',
						name: 'includeTradeItemPrices',
						type: 'boolean',
						default: true,
						description: 'Whether to include trade item prices (_trade_item_prices met net_price, price_on_request)',
					},
					{
						displayName: 'Categorieën',
						name: 'includeCategories',
						type: 'boolean',
						default: true,
						description: 'Whether to include categories (_categories met category_id, category_name, parent, vertalingen)',
					},
					{
						displayName: 'Productgroepen',
						name: 'includeProductGroups',
						type: 'boolean',
						default: true,
						description: 'Whether to include product groups (_product_groups met ETIM data)',
					},
					{
						displayName: 'Gegroepeerde producten',
						name: 'includeGroupedProducts',
						type: 'boolean',
						default: false,
						description: 'Whether to include grouped product references',
					},
					{
						displayName: 'ETIM kenmerken',
						name: 'includeEtim',
						type: 'boolean',
						default: true,
						description: 'Whether to include ETIM attributes (_etim met feature types A/L/N/R/C/M)',
					},
					{
						displayName: 'ETIM vertalingen',
						name: 'includeEtimTranslations',
						type: 'boolean',
						default: true,
						description: 'Whether to include ETIM feature and value translations',
					},
					{
						displayName: 'Talen',
						name: 'includeLanguages',
						type: 'string',
						default: 'nl-NL,nl',
						description: 'Comma-separated taalcodes voor vertalingen (bijv. nl-NL,nl,en,de)',
					},
					{
						displayName: 'Contexten',
						name: 'includeContexts',
						type: 'string',
						default: '1',
						description: 'Comma-separated context IDs',
					},
				],
			},

			// ═══════════════════════════════════════════
			// Collectie filter (product + groupedProduct)
			// ═══════════════════════════════════════════
			{
				displayName: 'Collectie IDs',
				name: 'collectionIds',
				type: 'string',
				default: '',
				placeholder: '123, 456',
				description: 'Comma-separated collectie IDs om te filteren (leeg = alles)',
				displayOptions: {
					show: {
						resource: ['product', 'groupedProduct'],
					},
				},
			},

			// ═══════════════════════════════════════════
			// Grouped Product include-opties
			// ═══════════════════════════════════════════
			{
				displayName: 'Include opties',
				name: 'groupedIncludes',
				type: 'collection',
				placeholder: 'Optie toevoegen',
				default: {},
				displayOptions: {
					show: {
						resource: ['groupedProduct'],
					},
				},
				options: [
					{
						displayName: 'Producten in groep',
						name: 'includeProducts',
						type: 'boolean',
						default: true,
						description: 'Whether to include child products per groep (_products met product_id, internal_product_code, order)',
					},
					{
						displayName: 'ETIM features',
						name: 'includeEtimFeatures',
						type: 'boolean',
						default: true,
						description: 'Whether to include ETIM variatie-features per groep (_etim_features)',
					},
				],
			},

			// ═══════════════════════════════════════════
			// Output opties
			// ═══════════════════════════════════════════
			{
				displayName: 'Output modus',
				name: 'outputMode',
				type: 'options',
				default: 'items',
				description: 'Hoe de resultaten worden teruggegeven',
				displayOptions: {
					show: {
						resource: ['product', 'groupedProduct'],
					},
				},
				options: [
					{
						name: 'Afzonderlijke items',
						value: 'items',
						description: 'Elk product/groep als apart n8n item (handig voor verdere verwerking)',
					},
					{
						name: 'Volledige API response',
						value: 'raw',
						description: 'De complete API response inclusief paginatie-info als één item',
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
				if (resource === 'product') {
					const useFilter = operation === 'getByFilter';
					const results = await executeGetProducts.call(this, i, useFilter);
					returnData.push(...results);
				} else if (resource === 'groupedProduct') {
					const results = await executeGetGroupedProducts.call(this, i);
					returnData.push(...results);
				} else if (resource === 'connection') {
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
							sample: result,
						},
					});
				} else if (resource === 'custom') {
					const method = this.getNodeParameter('customMethod', i) as string;
					const paramsRaw = this.getNodeParameter('customParams', i, '{}') as string;
					let params: Record<string, unknown>;
					try {
						params = typeof paramsRaw === 'string' ? JSON.parse(paramsRaw) : paramsRaw as Record<string, unknown>;
					} catch {
						throw new Error(`Ongeldige JSON in parameters: ${paramsRaw}`);
					}
					const result = await skwirrelJsonRpcCall(this, method, params);
					returnData.push({ json: result });
				}
			} catch (error) {
				if (this.continueOnFail()) {
					returnData.push({
						json: { error: (error as Error).message } as IDataObject,
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

// ═══════════════════════════════════════════════════════
// Product helpers
// ═══════════════════════════════════════════════════════

function buildProductParams(ctx: IExecuteFunctions, i: number): Record<string, unknown> {
	const inc = ctx.getNodeParameter('includes', i, {}) as IDataObject;

	const params: Record<string, unknown> = {
		include_product_status: inc.includeProductStatus !== false,
		include_product_translations: inc.includeProductTranslations !== false,
		include_attachments: inc.includeAttachments !== false,
		include_trade_items: inc.includeTradeItems !== false,
		include_trade_item_prices: inc.includeTradeItemPrices !== false,
		include_categories: inc.includeCategories === true,
		include_product_groups: inc.includeProductGroups === true,
		include_grouped_products: inc.includeGroupedProducts === true,
		include_etim: inc.includeEtim !== false,
		include_etim_translations: inc.includeEtimTranslations !== false,
	};

	const langs = (inc.includeLanguages as string) || 'nl-NL,nl';
	params.include_languages = parseCommaList(langs);

	const contexts = (inc.includeContexts as string) || '1';
	params.include_contexts = parseNumericList(contexts);

	const collectionIds = ctx.getNodeParameter('collectionIds', i, '') as string;
	if (collectionIds) {
		params.collection_ids = parseNumericList(collectionIds);
	}

	return params;
}

async function executeGetProducts(
	this: IExecuteFunctions,
	itemIndex: number,
	useFilter: boolean,
): Promise<INodeExecutionData[]> {
	const returnAll = this.getNodeParameter('returnAll', itemIndex, false) as boolean;
	const limit = returnAll ? 500 : (this.getNodeParameter('limit', itemIndex, 100) as number);
	let page = returnAll ? 1 : (this.getNodeParameter('page', itemIndex, 1) as number);
	const outputMode = this.getNodeParameter('outputMode', itemIndex, 'items') as string;
	const baseParams = buildProductParams(this, itemIndex);
	const allProducts: IDataObject[] = [];
	let lastData: IDataObject = {};

	do {
		let result: IDataObject;

		if (useFilter) {
			const updatedSince = this.getNodeParameter('updatedSince', itemIndex) as string;
			const filterOp = this.getNodeParameter('filterOperator', itemIndex, '>=') as string;
			const options = { ...baseParams };
			result = await skwirrelJsonRpcCall(this, 'getProductsByFilter', {
				filter: {
					updated_on: {
						datetime: updatedSince,
						operator: filterOp,
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

		lastData = result;
		const products = (result.products as IDataObject[]) || [];
		allProducts.push(...products);

		if (!returnAll || products.length < limit) break;

		const pageInfo = (result.page as IDataObject) || {};
		const totalPages = Number(pageInfo.number_of_pages || 1);
		if (page >= totalPages) break;

		page++;
	} while (returnAll);

	if (outputMode === 'raw') {
		return [{
			json: {
				...lastData,
				products: allProducts,
				_total_fetched: allProducts.length,
			} as IDataObject,
		}];
	}

	return allProducts.map((product) => ({ json: product }));
}

async function executeGetGroupedProducts(
	this: IExecuteFunctions,
	itemIndex: number,
): Promise<INodeExecutionData[]> {
	const returnAll = this.getNodeParameter('returnAll', itemIndex, false) as boolean;
	const limit = returnAll ? 500 : (this.getNodeParameter('limit', itemIndex, 100) as number);
	let page = returnAll ? 1 : (this.getNodeParameter('page', itemIndex, 1) as number);
	const outputMode = this.getNodeParameter('outputMode', itemIndex, 'items') as string;
	const inc = this.getNodeParameter('groupedIncludes', itemIndex, {}) as IDataObject;
	const allGroups: IDataObject[] = [];
	let lastData: IDataObject = {};

	do {
		const params: Record<string, unknown> = {
			page,
			limit,
			include_products: inc.includeProducts !== false,
			include_etim_features: inc.includeEtimFeatures !== false,
		};

		const collectionIds = this.getNodeParameter('collectionIds', itemIndex, '') as string;
		if (collectionIds) {
			params.collection_ids = parseNumericList(collectionIds);
		}

		const result = await skwirrelJsonRpcCall(this, 'getGroupedProducts', params);
		lastData = result;

		const groups =
			(result.grouped_products as IDataObject[]) ||
			(result.groups as IDataObject[]) ||
			(result.products as IDataObject[]) ||
			[];
		allGroups.push(...groups);

		if (!returnAll || groups.length < limit) break;

		const pageInfo = (result.page as IDataObject) || {};
		const totalPages = Number(pageInfo.number_of_pages || 1);
		if (page >= totalPages) break;

		page++;
	} while (returnAll);

	if (outputMode === 'raw') {
		return [{
			json: {
				...lastData,
				grouped_products: allGroups,
				_total_fetched: allGroups.length,
			} as IDataObject,
		}];
	}

	return allGroups.map((group) => ({ json: group }));
}
