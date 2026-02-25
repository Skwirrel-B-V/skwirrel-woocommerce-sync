<?php

declare(strict_types=1);

// ------------------------------------------------------------------
// get_custom_class_attributes()
// ------------------------------------------------------------------

beforeEach(function () {
	$this->mapper = new Skwirrel_WC_Sync_Product_Mapper();
});

test('get_custom_class_attributes extracts alphanumeric feature', function () {
	$product = [
		'product_id' => 1,
		'_custom_classes' => [
			[
				'custom_class_id' => 5,
				'custom_class_code' => 'BUIS',
				'custom_class_name' => 'Buisafmetingen',
				'_custom_features' => [
					[
						'custom_feature_id' => 10,
						'custom_feature_code' => 'MAT',
						'custom_feature_type' => 'A',
						'custom_value_code' => 'KOPER',
						'not_applicable' => false,
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Materiaal'],
						],
						'_custom_values' => [
							[
								'custom_value_code' => 'KOPER',
								'_custom_value_translations' => [
									['language' => 'nl', 'custom_value_description' => 'Koper'],
								],
							],
						],
					],
				],
			],
		],
	];

	$result = $this->mapper->get_custom_class_attributes($product);

	expect($result)->toHaveCount(1);
	expect($result['Materiaal'])->toBe('Koper');
});

test('get_custom_class_attributes extracts numeric feature with unit', function () {
	$product = [
		'product_id' => 2,
		'_custom_classes' => [
			[
				'custom_class_id' => 5,
				'_custom_features' => [
					[
						'custom_feature_code' => 'BD',
						'custom_feature_type' => 'N',
						'numeric_value' => 15.0,
						'not_applicable' => false,
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Buitendiameter'],
						],
						'_custom_unit_translations' => [
							['language' => 'nl', 'custom_unit_abbreviation' => 'mm', 'custom_unit_description' => 'millimeter'],
						],
					],
				],
			],
		],
	];

	$result = $this->mapper->get_custom_class_attributes($product);

	expect($result)->toHaveCount(1);
	expect($result['Buitendiameter'])->toBe('15 mm');
});

test('get_custom_class_attributes extracts logical feature', function () {
	$product = [
		'product_id' => 3,
		'_custom_classes' => [
			[
				'custom_class_id' => 5,
				'_custom_features' => [
					[
						'custom_feature_code' => 'CERT',
						'custom_feature_type' => 'L',
						'logical_value' => true,
						'not_applicable' => false,
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Gecertificeerd'],
						],
					],
				],
			],
		],
	];

	$result = $this->mapper->get_custom_class_attributes($product);

	expect($result['Gecertificeerd'])->toBe('Ja');
});

test('get_custom_class_attributes extracts range feature', function () {
	$product = [
		'product_id' => 4,
		'_custom_classes' => [
			[
				'custom_class_id' => 5,
				'_custom_features' => [
					[
						'custom_feature_code' => 'TEMP',
						'custom_feature_type' => 'R',
						'range_min' => -20,
						'range_max' => 80,
						'not_applicable' => false,
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Temperatuurbereik'],
						],
						'_custom_unit_translations' => [
							['language' => 'nl', 'custom_unit_abbreviation' => '°C'],
						],
					],
				],
			],
		],
	];

	$result = $this->mapper->get_custom_class_attributes($product);

	expect($result['Temperatuurbereik'])->toBe('-20 – 80 °C');
});

test('get_custom_class_attributes extracts multi-alphanumeric as comma-separated', function () {
	$product = [
		'product_id' => 5,
		'_custom_classes' => [
			[
				'custom_class_id' => 5,
				'_custom_features' => [
					[
						'custom_feature_code' => 'KLEUR',
						'custom_feature_type' => 'M',
						'not_applicable' => false,
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Kleur'],
						],
						'_custom_values' => [
							[
								'custom_value_code' => 'ROOD',
								'_custom_value_translations' => [
									['language' => 'nl', 'custom_value_description' => 'Rood'],
								],
							],
							[
								'custom_value_code' => 'BLAUW',
								'_custom_value_translations' => [
									['language' => 'nl', 'custom_value_description' => 'Blauw'],
								],
							],
						],
					],
				],
			],
		],
	];

	$result = $this->mapper->get_custom_class_attributes($product);

	expect($result['Kleur'])->toBe('Rood, Blauw');
});

test('get_custom_class_attributes extracts date feature', function () {
	$product = [
		'product_id' => 6,
		'_custom_classes' => [
			[
				'custom_class_id' => 5,
				'_custom_features' => [
					[
						'custom_feature_code' => 'PROD_DATE',
						'custom_feature_type' => 'D',
						'date_value' => '2025-06-15',
						'not_applicable' => false,
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Productiedatum'],
						],
					],
				],
			],
		],
	];

	$result = $this->mapper->get_custom_class_attributes($product);

	expect($result['Productiedatum'])->toBe('2025-06-15');
});

test('get_custom_class_attributes extracts internationalized text', function () {
	$product = [
		'product_id' => 7,
		'_custom_classes' => [
			[
				'custom_class_id' => 5,
				'_custom_features' => [
					[
						'custom_feature_code' => 'GEBRUIK',
						'custom_feature_type' => 'I',
						'not_applicable' => false,
						'translated_texts' => [
							['language' => 'en', 'text' => 'Indoor use only'],
							['language' => 'nl', 'text' => 'Alleen voor binnengebruik'],
						],
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Gebruik'],
						],
					],
				],
			],
		],
	];

	$result = $this->mapper->get_custom_class_attributes($product);

	expect($result['Gebruik'])->toBe('Alleen voor binnengebruik');
});

test('get_custom_class_attributes skips not_applicable features', function () {
	$product = [
		'product_id' => 8,
		'_custom_classes' => [
			[
				'custom_class_id' => 5,
				'_custom_features' => [
					[
						'custom_feature_code' => 'MAT',
						'custom_feature_type' => 'A',
						'not_applicable' => true,
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Materiaal'],
						],
					],
				],
			],
		],
	];

	$result = $this->mapper->get_custom_class_attributes($product);

	expect($result)->toBe([]);
});

test('get_custom_class_attributes skips T and B types', function () {
	$product = [
		'product_id' => 9,
		'_custom_classes' => [
			[
				'custom_class_id' => 5,
				'_custom_features' => [
					[
						'custom_feature_code' => 'DESC',
						'custom_feature_type' => 'T',
						'text_value' => 'Some short text',
						'not_applicable' => false,
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Omschrijving'],
						],
					],
					[
						'custom_feature_code' => 'LONG',
						'custom_feature_type' => 'B',
						'big_text_value' => 'Very long text here...',
						'not_applicable' => false,
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Lange tekst'],
						],
					],
				],
			],
		],
	];

	$result = $this->mapper->get_custom_class_attributes($product);

	expect($result)->toBe([]);
});

// ------------------------------------------------------------------
// get_custom_class_text_meta()
// ------------------------------------------------------------------

test('get_custom_class_text_meta extracts T and B types as meta', function () {
	$product = [
		'product_id' => 10,
		'_custom_classes' => [
			[
				'custom_class_id' => 5,
				'_custom_features' => [
					[
						'custom_feature_code' => 'DESC',
						'custom_feature_type' => 'T',
						'text_value' => 'Korte productomschrijving',
						'not_applicable' => false,
					],
					[
						'custom_feature_code' => 'LONG_DESC',
						'custom_feature_type' => 'B',
						'big_text_value' => 'Heel uitgebreide tekst over het product...',
						'not_applicable' => false,
					],
				],
			],
		],
	];

	$result = $this->mapper->get_custom_class_text_meta($product);

	expect($result)->toHaveCount(2);
	expect($result['_skwirrel_cc_desc'])->toBe('Korte productomschrijving');
	expect($result['_skwirrel_cc_long_desc'])->toBe('Heel uitgebreide tekst over het product...');
});

test('get_custom_class_text_meta ignores attribute types', function () {
	$product = [
		'product_id' => 11,
		'_custom_classes' => [
			[
				'custom_class_id' => 5,
				'_custom_features' => [
					[
						'custom_feature_code' => 'MAT',
						'custom_feature_type' => 'A',
						'custom_value_code' => 'KOPER',
						'not_applicable' => false,
					],
					[
						'custom_feature_code' => 'NUM',
						'custom_feature_type' => 'N',
						'numeric_value' => 42,
						'not_applicable' => false,
					],
				],
			],
		],
	];

	$result = $this->mapper->get_custom_class_text_meta($product);

	expect($result)->toBe([]);
});

// ------------------------------------------------------------------
// Whitelist / Blacklist filtering
// ------------------------------------------------------------------

test('get_custom_class_attributes filters by whitelist with class ID', function () {
	$product = [
		'product_id' => 12,
		'_custom_classes' => [
			[
				'custom_class_id' => 5,
				'custom_class_code' => 'BUIS',
				'_custom_features' => [
					[
						'custom_feature_code' => 'MAT',
						'custom_feature_type' => 'N',
						'numeric_value' => 15,
						'not_applicable' => false,
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Buitendiameter'],
						],
					],
				],
			],
			[
				'custom_class_id' => 10,
				'custom_class_code' => 'POMP',
				'_custom_features' => [
					[
						'custom_feature_code' => 'POWER',
						'custom_feature_type' => 'N',
						'numeric_value' => 500,
						'not_applicable' => false,
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Vermogen'],
						],
					],
				],
			],
		],
	];

	// Whitelist: only class ID 5
	$result = $this->mapper->get_custom_class_attributes($product, false, 'whitelist', [5], []);

	expect($result)->toHaveCount(1);
	expect(array_key_exists('Buitendiameter', $result))->toBeTrue();
	expect(array_key_exists('Vermogen', $result))->toBeFalse();
});

test('get_custom_class_attributes filters by blacklist with class code', function () {
	$product = [
		'product_id' => 13,
		'_custom_classes' => [
			[
				'custom_class_id' => 5,
				'custom_class_code' => 'BUIS',
				'_custom_features' => [
					[
						'custom_feature_code' => 'MAT',
						'custom_feature_type' => 'N',
						'numeric_value' => 15,
						'not_applicable' => false,
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Buitendiameter'],
						],
					],
				],
			],
			[
				'custom_class_id' => 10,
				'custom_class_code' => 'POMP',
				'_custom_features' => [
					[
						'custom_feature_code' => 'POWER',
						'custom_feature_type' => 'N',
						'numeric_value' => 500,
						'not_applicable' => false,
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Vermogen'],
						],
					],
				],
			],
		],
	];

	// Blacklist: exclude class code BUIS
	$result = $this->mapper->get_custom_class_attributes($product, false, 'blacklist', [], ['buis']);

	expect($result)->toHaveCount(1);
	expect(array_key_exists('Vermogen', $result))->toBeTrue();
	expect(array_key_exists('Buitendiameter', $result))->toBeFalse();
});

test('get_custom_class_attributes includes trade item classes when enabled', function () {
	$product = [
		'product_id' => 14,
		'_custom_classes' => [
			[
				'custom_class_id' => 5,
				'_custom_features' => [
					[
						'custom_feature_code' => 'MAT',
						'custom_feature_type' => 'L',
						'logical_value' => true,
						'not_applicable' => false,
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Gecertificeerd'],
						],
					],
				],
			],
		],
		'_trade_items' => [
			[
				'_trade_item_custom_classes' => [
					[
						'custom_class_id' => 20,
						'_custom_features' => [
							[
								'custom_feature_code' => 'GEWICHT',
								'custom_feature_type' => 'N',
								'numeric_value' => 2.5,
								'not_applicable' => false,
								'_custom_feature_translations' => [
									['language' => 'nl', 'custom_feature_description' => 'Gewicht'],
								],
								'_custom_unit_translations' => [
									['language' => 'nl', 'custom_unit_abbreviation' => 'kg'],
								],
							],
						],
					],
				],
			],
		],
	];

	// Without trade items
	$without = $this->mapper->get_custom_class_attributes($product, false);
	expect($without)->toHaveCount(1);
	expect(array_key_exists('Gecertificeerd', $without))->toBeTrue();

	// With trade items
	$with = $this->mapper->get_custom_class_attributes($product, true);
	expect($with)->toHaveCount(2);
	expect($with['Gewicht'])->toBe('2.5 kg');
});

test('get_custom_class_attributes returns empty for product without custom classes', function () {
	$product = ['product_id' => 15];

	$result = $this->mapper->get_custom_class_attributes($product);

	expect($result)->toBe([]);
});

// ------------------------------------------------------------------
// parse_custom_class_filter()
// ------------------------------------------------------------------

test('parse_custom_class_filter separates numeric IDs from string codes', function () {
	$result = Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter('12, 45, BUIS, pomp, 99');

	expect($result['ids'])->toBe([12, 45, 99]);
	expect($result['codes'])->toBe(['buis', 'pomp']);
});

test('parse_custom_class_filter handles empty string', function () {
	$result = Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter('');

	expect($result['ids'])->toBe([]);
	expect($result['codes'])->toBe([]);
});

// ------------------------------------------------------------------
// Multiple classes on one product
// ------------------------------------------------------------------

test('get_custom_class_attributes merges features from multiple classes', function () {
	$product = [
		'product_id' => 16,
		'_custom_classes' => [
			[
				'custom_class_id' => 5,
				'_custom_features' => [
					[
						'custom_feature_code' => 'MAT',
						'custom_feature_type' => 'A',
						'not_applicable' => false,
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Materiaal'],
						],
						'_custom_values' => [
							[
								'custom_value_code' => 'KOPER',
								'_custom_value_translations' => [
									['language' => 'nl', 'custom_value_description' => 'Koper'],
								],
							],
						],
					],
				],
			],
			[
				'custom_class_id' => 10,
				'_custom_features' => [
					[
						'custom_feature_code' => 'POWER',
						'custom_feature_type' => 'N',
						'numeric_value' => 500,
						'not_applicable' => false,
						'_custom_feature_translations' => [
							['language' => 'nl', 'custom_feature_description' => 'Vermogen'],
						],
						'_custom_unit_translations' => [
							['language' => 'nl', 'custom_unit_abbreviation' => 'W'],
						],
					],
				],
			],
		],
	];

	$result = $this->mapper->get_custom_class_attributes($product);

	expect($result)->toHaveCount(2);
	expect($result['Materiaal'])->toBe('Koper');
	expect($result['Vermogen'])->toBe('500 W');
});
