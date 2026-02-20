import {
	IExecuteFunctions,
	IRequestOptions,
	JsonObject,
	NodeApiError,
} from 'n8n-workflow';

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
): Promise<unknown> {
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
		headers['Authorization'] = `Bearer ${apiToken}`;
	} else {
		headers['X-Skwirrel-Api-Token'] = apiToken;
	}

	const options: IRequestOptions = {
		method: 'POST',
		url: endpoint,
		headers,
		body,
		json: true,
		timeout,
	};

	let response: JsonObject;
	try {
		response = (await ctx.helpers.request(options)) as JsonObject;
	} catch (error) {
		throw new NodeApiError(ctx.getNode(), error as JsonObject, {
			message: 'Kon geen verbinding maken met de Skwirrel API',
		});
	}

	// Als de response een string is (niet-geparsed JSON), parse het
	if (typeof response === 'string') {
		try {
			response = JSON.parse(response) as JsonObject;
		} catch {
			throw new NodeApiError(ctx.getNode(), {} as JsonObject, {
				message: 'Ongeldige JSON response van de Skwirrel API',
			});
		}
	}

	// JSON-RPC error afhandelen
	if (response.error) {
		const err = response.error as JsonObject;
		throw new NodeApiError(ctx.getNode(), err, {
			message: (err.message as string) || 'Skwirrel API fout',
			description: err.data ? JSON.stringify(err.data) : undefined,
		});
	}

	return response.result;
}
