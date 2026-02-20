import { SkwirrelApi } from '../credentials/SkwirrelApi.credentials';

describe('SkwirrelApi credentials', () => {
	const creds = new SkwirrelApi();

	test('has correct name', () => {
		expect(creds.name).toBe('skwirrelApi');
		expect(creds.displayName).toBe('Skwirrel API');
	});

	test('has endpoint property', () => {
		const prop = creds.properties.find(p => p.name === 'endpoint');
		expect(prop).toBeDefined();
		expect(prop!.type).toBe('string');
		expect(prop!.required).toBe(true);
	});

	test('has authType with bearer and token options', () => {
		const prop = creds.properties.find(p => p.name === 'authType');
		expect(prop).toBeDefined();
		expect(prop!.type).toBe('options');
		const values = (prop as any).options.map((o: any) => o.value);
		expect(values).toContain('bearer');
		expect(values).toContain('token');
	});

	test('has apiToken as password field', () => {
		const prop = creds.properties.find(p => p.name === 'apiToken');
		expect(prop).toBeDefined();
		expect(prop!.type).toBe('string');
		expect((prop!.typeOptions as any)?.password).toBe(true);
		expect(prop!.required).toBe(true);
	});

	test('has timeout with min/max', () => {
		const prop = creds.properties.find(p => p.name === 'timeout');
		expect(prop).toBeDefined();
		expect(prop!.type).toBe('number');
		expect(prop!.default).toBe(30);
		expect((prop!.typeOptions as any)?.minValue).toBe(5);
		expect((prop!.typeOptions as any)?.maxValue).toBe(120);
	});

	test('default authType is bearer', () => {
		const prop = creds.properties.find(p => p.name === 'authType');
		expect(prop!.default).toBe('bearer');
	});
});
