import type {
	IExecuteFunctions,
	IDataObject,
	JsonObject,
} from 'n8n-workflow';
import { NodeApiError } from 'n8n-workflow';

let requestId = 0;

/**
 * Voer een Skwirrel JSON-RPC 2.0 call uit.
 *
 * Bouwt het JSON-RPC envelope, stuurt authenticatie headers mee,
 * en geeft het `result` veld terug (of gooit een NodeApiError).
 */
export async function skwirrelJsonRpcCall(
	ctx: IExecuteFunctions,
	method: string,
	params: Record<string, unknown>,
): Promise<IDataObject> {
	const credentials = await ctx.getCredentials('skwirrelApi');

	const endpoint = (credentials.endpoint as string).replace(/\/+$/, '');
	const authType = credentials.authType as string;
	const apiToken = credentials.apiToken as string;
	const timeout = ((credentials.timeout as number) || 30) * 1000;

	requestId++;

	const body = {
		jsonrpc: '2.0',
		method,
		params,
		id: requestId,
	};

	const headers: Record<string, string> = {
		'Content-Type': 'application/json',
		Accept: 'application/json',
		'X-Skwirrel-Api-Version': '2',
	};

	if (authType === 'bearer') {
		headers.Authorization = `Bearer ${apiToken}`;
	} else {
		headers['X-Skwirrel-Api-Token'] = apiToken;
	}

	let rawResponse: unknown;
	try {
		rawResponse = await ctx.helpers.request({
			method: 'POST',
			url: endpoint,
			headers,
			body,
			json: true,
			timeout,
		});
	} catch (error) {
		throw new NodeApiError(ctx.getNode(), error as JsonObject, {
			message: 'Kon geen verbinding maken met de Skwirrel API',
		});
	}

	// Parse als het een string is
	let response: IDataObject;
	if (typeof rawResponse === 'string') {
		try {
			response = JSON.parse(rawResponse) as IDataObject;
		} catch {
			throw new NodeApiError(ctx.getNode(), {} as JsonObject, {
				message: 'Ongeldige JSON response van de Skwirrel API',
			});
		}
	} else {
		response = rawResponse as IDataObject;
	}

	// JSON-RPC error
	if (response.error) {
		const err = response.error as JsonObject;
		throw new NodeApiError(ctx.getNode(), err, {
			message: (err['message'] as string) || 'Skwirrel API fout',
			description: err['data'] ? JSON.stringify(err['data']) : undefined,
		});
	}

	return (response.result ?? response) as IDataObject;
}

/**
 * Parse een comma-separated string naar een string array.
 */
export function parseCommaList(value: string): string[] {
	return value.split(',').map((s) => s.trim()).filter(Boolean);
}

/**
 * Parse een comma-separated string naar een nummer array.
 */
export function parseNumericList(value: string): number[] {
	return parseCommaList(value).map(Number).filter((n) => !isNaN(n));
}
