import { Skwirrel } from '../nodes/Skwirrel/Skwirrel.node';
import type { IExecuteFunctions, IDataObject } from 'n8n-workflow';

// Mock de transport module zodat we geen echte HTTP calls doen
jest.mock('../nodes/Skwirrel/transport', () => {
	const actual = jest.requireActual('../nodes/Skwirrel/transport');
	return {
		...actual,
		skwirrelJsonRpcCall: jest.fn(),
	};
});

import { skwirrelJsonRpcCall } from '../nodes/Skwirrel/transport';
const mockJsonRpcCall = skwirrelJsonRpcCall as jest.MockedFunction<typeof skwirrelJsonRpcCall>;

// ═══════════════════════════════════════════════════════
// Helper: mock IExecuteFunctions
// ═══════════════════════════════════════════════════════

function createNodeContext(params: Record<string, unknown>): IExecuteFunctions {
	return {
		getInputData: jest.fn().mockReturnValue([{ json: {} }]),
		getNodeParameter: jest.fn().mockImplementation(
			(name: string, _index: number, fallback?: unknown) =>
				params[name] !== undefined ? params[name] : fallback,
		),
		getNode: jest.fn().mockReturnValue({ name: 'Skwirrel', type: 'skwirrel' }),
		getCredentials: jest.fn().mockResolvedValue({
			endpoint: 'https://test.skwirrel.eu/jsonrpc',
			authType: 'bearer',
			apiToken: 'test-token',
			timeout: 30,
		}),
		continueOnFail: jest.fn().mockReturnValue(false),
		helpers: {
			request: jest.fn(),
		},
	} as unknown as IExecuteFunctions;
}

// ═══════════════════════════════════════════════════════
// Node description
// ═══════════════════════════════════════════════════════

describe('Skwirrel node description', () => {
	const node = new Skwirrel();

	test('has correct name and display name', () => {
		expect(node.description.name).toBe('skwirrel');
		expect(node.description.displayName).toBe('Skwirrel');
	});

	test('requires skwirrelApi credentials', () => {
		expect(node.description.credentials).toEqual([
			{ name: 'skwirrelApi', required: true },
		]);
	});

	test('has all four resources', () => {
		const resourceProp = node.description.properties.find(p => p.name === 'resource');
		const values = (resourceProp as any).options.map((o: any) => o.value);
		expect(values).toContain('product');
		expect(values).toContain('groupedProduct');
		expect(values).toContain('connection');
		expect(values).toContain('custom');
	});

	test('product resource has getAll and getByFilter operations', () => {
		const opProps = node.description.properties.filter(p => p.name === 'operation');
		const productOps = opProps.find(p =>
			(p.displayOptions?.show?.resource as string[])?.includes('product'),
		);
		const values = (productOps as any).options.map((o: any) => o.value);
		expect(values).toContain('getAll');
		expect(values).toContain('getByFilter');
	});

	test('has all product include options', () => {
		const includesProp = node.description.properties.find(p => p.name === 'includes');
		const names = (includesProp as any).options.map((o: any) => o.name);
		expect(names).toContain('includeProductStatus');
		expect(names).toContain('includeProductTranslations');
		expect(names).toContain('includeAttachments');
		expect(names).toContain('includeTradeItems');
		expect(names).toContain('includeTradeItemPrices');
		expect(names).toContain('includeCategories');
		expect(names).toContain('includeProductGroups');
		expect(names).toContain('includeGroupedProducts');
		expect(names).toContain('includeEtim');
		expect(names).toContain('includeEtimTranslations');
		expect(names).toContain('includeLanguages');
		expect(names).toContain('includeContexts');
	});

	test('has output mode option', () => {
		const outputProp = node.description.properties.find(p => p.name === 'outputMode');
		const values = (outputProp as any).options.map((o: any) => o.value);
		expect(values).toContain('items');
		expect(values).toContain('raw');
	});
});

// ═══════════════════════════════════════════════════════
// Product: getAll
// ═══════════════════════════════════════════════════════

describe('Product: getAll', () => {
	beforeEach(() => {
		mockJsonRpcCall.mockReset();
	});

	test('calls getProducts with correct params', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'product',
			operation: 'getAll',
			returnAll: false,
			page: 1,
			limit: 50,
			outputMode: 'items',
			collectionIds: '',
			includes: {
				includeProductStatus: true,
				includeProductTranslations: true,
				includeAttachments: false,
				includeTradeItems: true,
				includeTradeItemPrices: true,
				includeCategories: true,
				includeProductGroups: false,
				includeGroupedProducts: false,
				includeEtim: true,
				includeEtimTranslations: true,
				includeLanguages: 'nl-NL,nl',
				includeContexts: '1',
			},
		});

		mockJsonRpcCall.mockResolvedValueOnce({
			products: [{ product_id: 1 }, { product_id: 2 }],
			page: { current_page: 1, number_of_pages: 1 },
		} as IDataObject);

		const result = await node.execute.call(ctx);

		expect(mockJsonRpcCall).toHaveBeenCalledWith(
			ctx,
			'getProducts',
			expect.objectContaining({
				page: 1,
				limit: 50,
				include_product_status: true,
				include_attachments: false,
				include_categories: true,
				include_product_groups: false,
				include_languages: ['nl-NL', 'nl'],
				include_contexts: [1],
			}),
		);

		expect(result[0]).toHaveLength(2);
		expect(result[0][0].json).toEqual({ product_id: 1 });
		expect(result[0][1].json).toEqual({ product_id: 2 });
	});

	test('returns raw API response when outputMode is raw', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'product',
			operation: 'getAll',
			returnAll: false,
			page: 1,
			limit: 100,
			outputMode: 'raw',
			collectionIds: '',
			includes: {},
		});

		mockJsonRpcCall.mockResolvedValueOnce({
			products: [{ product_id: 1 }],
			page: { current_page: 1, number_of_pages: 1 },
		} as IDataObject);

		const result = await node.execute.call(ctx);

		expect(result[0]).toHaveLength(1);
		expect(result[0][0].json).toHaveProperty('products');
		expect(result[0][0].json).toHaveProperty('_total_fetched', 1);
	});

	test('paginates when returnAll is true', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'product',
			operation: 'getAll',
			returnAll: true,
			outputMode: 'items',
			collectionIds: '',
			includes: {},
		});

		// Pagina 1: 500 producten (vol)
		const page1Products = Array.from({ length: 500 }, (_, i) => ({ product_id: i + 1 }));
		mockJsonRpcCall.mockResolvedValueOnce({
			products: page1Products,
			page: { current_page: 1, number_of_pages: 3 },
		} as IDataObject);

		// Pagina 2: 500 producten (vol)
		const page2Products = Array.from({ length: 500 }, (_, i) => ({ product_id: 501 + i }));
		mockJsonRpcCall.mockResolvedValueOnce({
			products: page2Products,
			page: { current_page: 2, number_of_pages: 3 },
		} as IDataObject);

		// Pagina 3: 50 producten (laatste pagina)
		const page3Products = Array.from({ length: 50 }, (_, i) => ({ product_id: 1001 + i }));
		mockJsonRpcCall.mockResolvedValueOnce({
			products: page3Products,
			page: { current_page: 3, number_of_pages: 3 },
		} as IDataObject);

		const result = await node.execute.call(ctx);

		expect(mockJsonRpcCall).toHaveBeenCalledTimes(3);
		expect(result[0]).toHaveLength(1050);
	});

	test('stops pagination when page count reached', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'product',
			operation: 'getAll',
			returnAll: true,
			outputMode: 'items',
			collectionIds: '',
			includes: {},
		});

		mockJsonRpcCall.mockResolvedValueOnce({
			products: Array.from({ length: 500 }, (_, i) => ({ product_id: i })),
			page: { current_page: 1, number_of_pages: 1 },
		} as IDataObject);

		const result = await node.execute.call(ctx);

		// Slechts 1 call, want number_of_pages = 1
		expect(mockJsonRpcCall).toHaveBeenCalledTimes(1);
		expect(result[0]).toHaveLength(500);
	});

	test('passes collection_ids when set', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'product',
			operation: 'getAll',
			returnAll: false,
			page: 1,
			limit: 100,
			outputMode: 'items',
			collectionIds: '123, 456',
			includes: {},
		});

		mockJsonRpcCall.mockResolvedValueOnce({
			products: [],
			page: { current_page: 1, number_of_pages: 1 },
		} as IDataObject);

		await node.execute.call(ctx);

		expect(mockJsonRpcCall).toHaveBeenCalledWith(
			ctx,
			'getProducts',
			expect.objectContaining({
				collection_ids: [123, 456],
			}),
		);
	});

	test('returns empty array when no products', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'product',
			operation: 'getAll',
			returnAll: false,
			page: 1,
			limit: 100,
			outputMode: 'items',
			collectionIds: '',
			includes: {},
		});

		mockJsonRpcCall.mockResolvedValueOnce({
			products: [],
		} as IDataObject);

		const result = await node.execute.call(ctx);
		expect(result[0]).toHaveLength(0);
	});
});

// ═══════════════════════════════════════════════════════
// Product: getByFilter
// ═══════════════════════════════════════════════════════

describe('Product: getByFilter', () => {
	beforeEach(() => {
		mockJsonRpcCall.mockReset();
	});

	test('calls getProductsByFilter with filter params', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'product',
			operation: 'getByFilter',
			returnAll: false,
			page: 1,
			limit: 100,
			outputMode: 'items',
			collectionIds: '',
			updatedSince: '2025-01-15T10:00:00Z',
			filterOperator: '>=',
			includes: { includeLanguages: 'nl,en' },
		});

		mockJsonRpcCall.mockResolvedValueOnce({
			products: [{ product_id: 42 }],
		} as IDataObject);

		const result = await node.execute.call(ctx);

		expect(mockJsonRpcCall).toHaveBeenCalledWith(
			ctx,
			'getProductsByFilter',
			expect.objectContaining({
				filter: {
					updated_on: {
						datetime: '2025-01-15T10:00:00Z',
						operator: '>=',
					},
				},
				page: 1,
				limit: 100,
			}),
		);

		expect(result[0]).toHaveLength(1);
		expect(result[0][0].json).toEqual({ product_id: 42 });
	});

	test('uses custom filter operator', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'product',
			operation: 'getByFilter',
			returnAll: false,
			page: 1,
			limit: 100,
			outputMode: 'items',
			collectionIds: '',
			updatedSince: '2025-06-01T00:00:00Z',
			filterOperator: '>',
			includes: {},
		});

		mockJsonRpcCall.mockResolvedValueOnce({ products: [] } as IDataObject);

		await node.execute.call(ctx);

		expect(mockJsonRpcCall).toHaveBeenCalledWith(
			ctx,
			'getProductsByFilter',
			expect.objectContaining({
				filter: {
					updated_on: {
						datetime: '2025-06-01T00:00:00Z',
						operator: '>',
					},
				},
			}),
		);
	});
});

// ═══════════════════════════════════════════════════════
// Grouped Product
// ═══════════════════════════════════════════════════════

describe('Grouped Product: getAll', () => {
	beforeEach(() => {
		mockJsonRpcCall.mockReset();
	});

	test('calls getGroupedProducts with correct params', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'groupedProduct',
			operation: 'getAll',
			returnAll: false,
			page: 1,
			limit: 50,
			outputMode: 'items',
			collectionIds: '789',
			groupedIncludes: { includeProducts: true, includeEtimFeatures: false },
		});

		mockJsonRpcCall.mockResolvedValueOnce({
			grouped_products: [{ grouped_product_id: 10 }],
			page: { current_page: 1, number_of_pages: 1 },
		} as IDataObject);

		const result = await node.execute.call(ctx);

		expect(mockJsonRpcCall).toHaveBeenCalledWith(
			ctx,
			'getGroupedProducts',
			expect.objectContaining({
				page: 1,
				limit: 50,
				include_products: true,
				include_etim_features: false,
				collection_ids: [789],
			}),
		);

		expect(result[0]).toHaveLength(1);
		expect(result[0][0].json).toEqual({ grouped_product_id: 10 });
	});

	test('handles alternate response key "groups"', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'groupedProduct',
			operation: 'getAll',
			returnAll: false,
			page: 1,
			limit: 100,
			outputMode: 'items',
			collectionIds: '',
			groupedIncludes: {},
		});

		mockJsonRpcCall.mockResolvedValueOnce({
			groups: [{ id: 1 }, { id: 2 }],
		} as IDataObject);

		const result = await node.execute.call(ctx);
		expect(result[0]).toHaveLength(2);
	});

	test('handles alternate response key "products"', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'groupedProduct',
			operation: 'getAll',
			returnAll: false,
			page: 1,
			limit: 100,
			outputMode: 'items',
			collectionIds: '',
			groupedIncludes: {},
		});

		mockJsonRpcCall.mockResolvedValueOnce({
			products: [{ id: 5 }],
		} as IDataObject);

		const result = await node.execute.call(ctx);
		expect(result[0]).toHaveLength(1);
	});

	test('raw output includes _total_fetched', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'groupedProduct',
			operation: 'getAll',
			returnAll: false,
			page: 1,
			limit: 100,
			outputMode: 'raw',
			collectionIds: '',
			groupedIncludes: {},
		});

		mockJsonRpcCall.mockResolvedValueOnce({
			grouped_products: [{ id: 1 }, { id: 2 }, { id: 3 }],
			page: { current_page: 1, number_of_pages: 1 },
		} as IDataObject);

		const result = await node.execute.call(ctx);

		expect(result[0]).toHaveLength(1);
		expect(result[0][0].json._total_fetched).toBe(3);
	});
});

// ═══════════════════════════════════════════════════════
// Connection: test
// ═══════════════════════════════════════════════════════

describe('Connection: test', () => {
	beforeEach(() => {
		mockJsonRpcCall.mockReset();
	});

	test('calls getProducts with minimal params', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'connection',
			operation: 'test',
		});

		mockJsonRpcCall.mockResolvedValueOnce({
			products: [{ product_id: 1 }],
		} as IDataObject);

		const result = await node.execute.call(ctx);

		expect(mockJsonRpcCall).toHaveBeenCalledWith(
			ctx,
			'getProducts',
			expect.objectContaining({
				page: 1,
				limit: 1,
				include_product_status: false,
				include_product_translations: false,
				include_attachments: false,
				include_trade_items: false,
				include_categories: false,
			}),
		);

		expect(result[0]).toHaveLength(1);
		expect(result[0][0].json.success).toBe(true);
		expect(result[0][0].json.message).toContain('geslaagd');
	});
});

// ═══════════════════════════════════════════════════════
// Custom API Call
// ═══════════════════════════════════════════════════════

describe('Custom API Call', () => {
	beforeEach(() => {
		mockJsonRpcCall.mockReset();
	});

	test('calls arbitrary method with parsed JSON params', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'custom',
			operation: 'call',
			customMethod: 'getCollections',
			customParams: '{"page": 1, "limit": 5}',
		});

		mockJsonRpcCall.mockResolvedValueOnce({
			collections: [{ id: 1, name: 'Test' }],
		} as IDataObject);

		const result = await node.execute.call(ctx);

		expect(mockJsonRpcCall).toHaveBeenCalledWith(
			ctx,
			'getCollections',
			{ page: 1, limit: 5 },
		);

		expect(result[0]).toHaveLength(1);
		expect(result[0][0].json).toEqual({
			collections: [{ id: 1, name: 'Test' }],
		});
	});

	test('throws on invalid JSON params', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'custom',
			operation: 'call',
			customMethod: 'getProducts',
			customParams: 'not valid json {{{',
		});

		await expect(node.execute.call(ctx)).rejects.toThrow('Ongeldige JSON');
	});

	test('handles object params directly (pre-parsed by n8n)', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'custom',
			operation: 'call',
			customMethod: 'getProducts',
			customParams: { page: 2, limit: 10 },
		});

		mockJsonRpcCall.mockResolvedValueOnce({ products: [] } as IDataObject);

		const result = await node.execute.call(ctx);

		expect(mockJsonRpcCall).toHaveBeenCalledWith(
			ctx,
			'getProducts',
			{ page: 2, limit: 10 },
		);

		expect(result[0]).toHaveLength(1);
	});
});

// ═══════════════════════════════════════════════════════
// Error handling
// ═══════════════════════════════════════════════════════

describe('Error handling', () => {
	beforeEach(() => {
		mockJsonRpcCall.mockReset();
	});

	test('propagates errors when continueOnFail is false', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'product',
			operation: 'getAll',
			returnAll: false,
			page: 1,
			limit: 100,
			outputMode: 'items',
			collectionIds: '',
			includes: {},
		});

		mockJsonRpcCall.mockRejectedValueOnce(new Error('API down'));

		await expect(node.execute.call(ctx)).rejects.toThrow('API down');
	});

	test('catches errors when continueOnFail is true', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'product',
			operation: 'getAll',
			returnAll: false,
			page: 1,
			limit: 100,
			outputMode: 'items',
			collectionIds: '',
			includes: {},
		});
		(ctx.continueOnFail as jest.Mock).mockReturnValue(true);

		mockJsonRpcCall.mockRejectedValueOnce(new Error('API fout'));

		const result = await node.execute.call(ctx);

		expect(result[0]).toHaveLength(1);
		expect(result[0][0].json.error).toBe('API fout');
		expect(result[0][0].pairedItem).toEqual({ item: 0 });
	});
});

// ═══════════════════════════════════════════════════════
// Include defaults
// ═══════════════════════════════════════════════════════

describe('Product include defaults', () => {
	beforeEach(() => {
		mockJsonRpcCall.mockReset();
	});

	test('defaults: status, translations, attachments, trade items, prices, etim ON', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'product',
			operation: 'getAll',
			returnAll: false,
			page: 1,
			limit: 10,
			outputMode: 'items',
			collectionIds: '',
			includes: {}, // Lege includes = gebruik defaults
		});

		mockJsonRpcCall.mockResolvedValueOnce({ products: [] } as IDataObject);

		await node.execute.call(ctx);

		expect(mockJsonRpcCall).toHaveBeenCalledWith(
			ctx,
			'getProducts',
			expect.objectContaining({
				// !== false defaults → true
				include_product_status: true,
				include_product_translations: true,
				include_attachments: true,
				include_trade_items: true,
				include_trade_item_prices: true,
				include_etim: true,
				include_etim_translations: true,
				// === true defaults → false (opt-in)
				include_categories: false,
				include_product_groups: false,
				include_grouped_products: false,
				// Default language
				include_languages: ['nl-NL', 'nl'],
				include_contexts: [1],
			}),
		);
	});

	test('explicit false overrides defaults', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'product',
			operation: 'getAll',
			returnAll: false,
			page: 1,
			limit: 10,
			outputMode: 'items',
			collectionIds: '',
			includes: {
				includeProductStatus: false,
				includeAttachments: false,
				includeEtim: false,
			},
		});

		mockJsonRpcCall.mockResolvedValueOnce({ products: [] } as IDataObject);

		await node.execute.call(ctx);

		expect(mockJsonRpcCall).toHaveBeenCalledWith(
			ctx,
			'getProducts',
			expect.objectContaining({
				include_product_status: false,
				include_attachments: false,
				include_etim: false,
			}),
		);
	});

	test('custom languages are parsed correctly', async () => {
		const node = new Skwirrel();
		const ctx = createNodeContext({
			resource: 'product',
			operation: 'getAll',
			returnAll: false,
			page: 1,
			limit: 10,
			outputMode: 'items',
			collectionIds: '',
			includes: {
				includeLanguages: 'nl-NL, nl, en, de-DE',
				includeContexts: '1, 2, 3',
			},
		});

		mockJsonRpcCall.mockResolvedValueOnce({ products: [] } as IDataObject);

		await node.execute.call(ctx);

		expect(mockJsonRpcCall).toHaveBeenCalledWith(
			ctx,
			'getProducts',
			expect.objectContaining({
				include_languages: ['nl-NL', 'nl', 'en', 'de-DE'],
				include_contexts: [1, 2, 3],
			}),
		);
	});
});
