import { parseCommaList, parseNumericList, skwirrelJsonRpcCall } from '../nodes/Skwirrel/transport';
import type { IExecuteFunctions, IDataObject } from 'n8n-workflow';

// ═══════════════════════════════════════════════════════
// parseCommaList
// ═══════════════════════════════════════════════════════

describe('parseCommaList', () => {
	test('splits comma-separated values', () => {
		expect(parseCommaList('nl-NL,nl,en')).toEqual(['nl-NL', 'nl', 'en']);
	});

	test('trims whitespace around values', () => {
		expect(parseCommaList('nl-NL , nl , en')).toEqual(['nl-NL', 'nl', 'en']);
	});

	test('filters out empty strings', () => {
		expect(parseCommaList('nl,,en,')).toEqual(['nl', 'en']);
	});

	test('returns empty array for empty input', () => {
		expect(parseCommaList('')).toEqual([]);
	});

	test('handles single value', () => {
		expect(parseCommaList('nl')).toEqual(['nl']);
	});

	test('handles whitespace-only input', () => {
		expect(parseCommaList(' , , ')).toEqual([]);
	});
});

// ═══════════════════════════════════════════════════════
// parseNumericList
// ═══════════════════════════════════════════════════════

describe('parseNumericList', () => {
	test('parses comma-separated numbers', () => {
		expect(parseNumericList('123, 456, 789')).toEqual([123, 456, 789]);
	});

	test('filters non-numeric values', () => {
		expect(parseNumericList('123, abc, 456')).toEqual([123, 456]);
	});

	test('returns empty array for empty string', () => {
		expect(parseNumericList('')).toEqual([]);
	});

	test('handles single number', () => {
		expect(parseNumericList('42')).toEqual([42]);
	});

	test('handles decimals', () => {
		expect(parseNumericList('1.5, 2.7')).toEqual([1.5, 2.7]);
	});

	test('filters whitespace-only entries', () => {
		expect(parseNumericList(' , , ')).toEqual([]);
	});
});

// ═══════════════════════════════════════════════════════
// skwirrelJsonRpcCall
// ═══════════════════════════════════════════════════════

function createMockContext(overrides: {
	credentials?: Partial<IDataObject>;
	requestResponse?: unknown;
	requestError?: Error;
} = {}): IExecuteFunctions {
	const credentials = {
		endpoint: 'https://test.skwirrel.eu/jsonrpc',
		authType: 'bearer',
		apiToken: 'test-token-123',
		timeout: 30,
		...overrides.credentials,
	};

	const mockRequest = overrides.requestError
		? jest.fn().mockRejectedValue(overrides.requestError)
		: jest.fn().mockResolvedValue(overrides.requestResponse);

	return {
		getCredentials: jest.fn().mockResolvedValue(credentials),
		getNode: jest.fn().mockReturnValue({ name: 'Skwirrel', type: 'skwirrel' }),
		helpers: {
			request: mockRequest,
		},
	} as unknown as IExecuteFunctions;
}

describe('skwirrelJsonRpcCall', () => {
	test('sends correct JSON-RPC envelope', async () => {
		const ctx = createMockContext({
			requestResponse: { jsonrpc: '2.0', result: { products: [] }, id: 1 },
		});

		await skwirrelJsonRpcCall(ctx, 'getProducts', { page: 1, limit: 10 });

		const requestCall = (ctx.helpers.request as jest.Mock).mock.calls[0][0];
		expect(requestCall.method).toBe('POST');
		expect(requestCall.url).toBe('https://test.skwirrel.eu/jsonrpc');
		expect(requestCall.body.jsonrpc).toBe('2.0');
		expect(requestCall.body.method).toBe('getProducts');
		expect(requestCall.body.params).toEqual({ page: 1, limit: 10 });
		expect(requestCall.body.id).toBeGreaterThan(0);
	});

	test('sets Bearer auth header', async () => {
		const ctx = createMockContext({
			credentials: { authType: 'bearer', apiToken: 'my-bearer-token' },
			requestResponse: { result: {} },
		});

		await skwirrelJsonRpcCall(ctx, 'getProducts', {});

		const requestCall = (ctx.helpers.request as jest.Mock).mock.calls[0][0];
		expect(requestCall.headers.Authorization).toBe('Bearer my-bearer-token');
		expect(requestCall.headers['X-Skwirrel-Api-Token']).toBeUndefined();
	});

	test('sets static token header', async () => {
		const ctx = createMockContext({
			credentials: { authType: 'token', apiToken: 'my-static-token' },
			requestResponse: { result: {} },
		});

		await skwirrelJsonRpcCall(ctx, 'getProducts', {});

		const requestCall = (ctx.helpers.request as jest.Mock).mock.calls[0][0];
		expect(requestCall.headers['X-Skwirrel-Api-Token']).toBe('my-static-token');
		expect(requestCall.headers.Authorization).toBeUndefined();
	});

	test('always sends X-Skwirrel-Api-Version: 2 header', async () => {
		const ctx = createMockContext({
			requestResponse: { result: {} },
		});

		await skwirrelJsonRpcCall(ctx, 'getProducts', {});

		const requestCall = (ctx.helpers.request as jest.Mock).mock.calls[0][0];
		expect(requestCall.headers['X-Skwirrel-Api-Version']).toBe('2');
	});

	test('strips trailing slashes from endpoint', async () => {
		const ctx = createMockContext({
			credentials: { endpoint: 'https://test.skwirrel.eu/jsonrpc///' },
			requestResponse: { result: {} },
		});

		await skwirrelJsonRpcCall(ctx, 'getProducts', {});

		const requestCall = (ctx.helpers.request as jest.Mock).mock.calls[0][0];
		expect(requestCall.url).toBe('https://test.skwirrel.eu/jsonrpc');
	});

	test('converts timeout from seconds to milliseconds', async () => {
		const ctx = createMockContext({
			credentials: { timeout: 45 },
			requestResponse: { result: {} },
		});

		await skwirrelJsonRpcCall(ctx, 'getProducts', {});

		const requestCall = (ctx.helpers.request as jest.Mock).mock.calls[0][0];
		expect(requestCall.timeout).toBe(45000);
	});

	test('defaults timeout to 30 seconds when not set', async () => {
		const ctx = createMockContext({
			credentials: { timeout: undefined },
			requestResponse: { result: {} },
		});

		await skwirrelJsonRpcCall(ctx, 'getProducts', {});

		const requestCall = (ctx.helpers.request as jest.Mock).mock.calls[0][0];
		expect(requestCall.timeout).toBe(30000);
	});

	test('returns result from successful JSON-RPC response', async () => {
		const ctx = createMockContext({
			requestResponse: {
				result: { products: [{ product_id: 1 }, { product_id: 2 }] },
			},
		});

		const result = await skwirrelJsonRpcCall(ctx, 'getProducts', {});

		expect(result).toEqual({ products: [{ product_id: 1 }, { product_id: 2 }] });
	});

	test('parses string response as JSON', async () => {
		const ctx = createMockContext({
			requestResponse: JSON.stringify({
				result: { products: [{ product_id: 99 }] },
			}),
		});

		const result = await skwirrelJsonRpcCall(ctx, 'getProducts', {});

		expect(result).toEqual({ products: [{ product_id: 99 }] });
	});

	test('throws on invalid JSON string response', async () => {
		const ctx = createMockContext({
			requestResponse: 'not valid json {{{',
		});

		await expect(skwirrelJsonRpcCall(ctx, 'getProducts', {}))
			.rejects.toThrow();
	});

	test('throws on JSON-RPC error in response', async () => {
		const ctx = createMockContext({
			requestResponse: {
				error: { code: -32601, message: 'Method not found' },
			},
		});

		await expect(skwirrelJsonRpcCall(ctx, 'nonExistent', {}))
			.rejects.toThrow();
	});

	test('throws on HTTP/network error', async () => {
		const ctx = createMockContext({
			requestError: new Error('ECONNREFUSED'),
		});

		await expect(skwirrelJsonRpcCall(ctx, 'getProducts', {}))
			.rejects.toThrow();
	});

	test('returns full response when no result key', async () => {
		const ctx = createMockContext({
			requestResponse: { some_data: 'value' },
		});

		const result = await skwirrelJsonRpcCall(ctx, 'customMethod', {});

		expect(result).toEqual({ some_data: 'value' });
	});

	test('sends json: true for automatic parsing', async () => {
		const ctx = createMockContext({
			requestResponse: { result: {} },
		});

		await skwirrelJsonRpcCall(ctx, 'getProducts', {});

		const requestCall = (ctx.helpers.request as jest.Mock).mock.calls[0][0];
		expect(requestCall.json).toBe(true);
	});
});
