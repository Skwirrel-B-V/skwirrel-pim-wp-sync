<?php

declare(strict_types=1);

beforeEach(function () {
	$this->mapper = new Skwirrel_WC_Sync_Product_Mapper();
});

// ------------------------------------------------------------------
// get_attributes() — base attributes
// ------------------------------------------------------------------

test('get_attributes does not include brand (handled by Brand_Sync)', function () {
	$product = [
		'product_id' => 1,
		'brand_name' => 'Bosch',
	];

	$attrs = $this->mapper->get_attributes($product);

	expect($attrs)->not->toHaveKey('Brand');
});

test('get_attributes includes manufacturer', function () {
	$product = [
		'product_id' => 1,
		'manufacturer_name' => 'DeWalt',
	];

	$attrs = $this->mapper->get_attributes($product);

	expect($attrs)->toHaveKey('Manufacturer', 'DeWalt');
});

test('get_attributes includes GTIN', function () {
	$product = [
		'product_id' => 1,
		'product_gtin' => '8711893004885',
	];

	$attrs = $this->mapper->get_attributes($product);

	expect($attrs)->toHaveKey('GTIN', '8711893004885');
});

test('get_attributes returns empty array when no data', function () {
	$product = ['product_id' => 1];

	$attrs = $this->mapper->get_attributes($product);

	expect($attrs)->toBe([]);
});

test('get_attributes combines base and ETIM attributes', function () {
	$product = [
		'product_id' => 1,
		'brand_name' => 'Bosch',
		'_etim' => [
			[
				'_etim_features' => [
					[
						'etim_feature_code' => 'EF001234',
						'etim_feature_type' => 'L',
						'logical_value' => true,
						'not_applicable' => false,
						'_etim_feature_translations' => [
							['language' => 'nl', 'etim_feature_description' => 'Draadloos'],
						],
					],
				],
			],
		],
	];

	$attrs = $this->mapper->get_attributes($product);

	expect($attrs)->not->toHaveKey('Brand');
	expect($attrs)->toHaveKey('Draadloos', 'Ja');
});

// ------------------------------------------------------------------
// get_attributes() — ETIM features
// ------------------------------------------------------------------

test('get_attributes extracts logical ETIM feature (true = Ja)', function () {
	$product = [
		'product_id' => 1,
		'_etim' => [
			[
				'_etim_features' => [
					[
						'etim_feature_code' => 'EF001',
						'etim_feature_type' => 'L',
						'logical_value' => true,
						'not_applicable' => false,
						'_etim_feature_translations' => [
							['language' => 'nl', 'etim_feature_description' => 'Waterdicht'],
						],
					],
				],
			],
		],
	];

	$attrs = $this->mapper->get_attributes($product);

	expect($attrs)->toHaveKey('Waterdicht', 'Ja');
});

test('get_attributes extracts logical ETIM feature (false = Nee)', function () {
	$product = [
		'product_id' => 1,
		'_etim' => [
			[
				'_etim_features' => [
					[
						'etim_feature_code' => 'EF002',
						'etim_feature_type' => 'L',
						'logical_value' => false,
						'not_applicable' => false,
						'_etim_feature_translations' => [
							['language' => 'nl', 'etim_feature_description' => 'Dimbaar'],
						],
					],
				],
			],
		],
	];

	$attrs = $this->mapper->get_attributes($product);

	expect($attrs)->toHaveKey('Dimbaar', 'Nee');
});

test('get_attributes extracts numeric ETIM feature with unit', function () {
	$product = [
		'product_id' => 1,
		'_etim' => [
			[
				'_etim_features' => [
					[
						'etim_feature_code' => 'EF003',
						'etim_feature_type' => 'N',
						'numeric_value' => 230,
						'not_applicable' => false,
						'_etim_feature_translations' => [
							['language' => 'nl', 'etim_feature_description' => 'Spanning'],
						],
						'_etim_unit_translations' => [
							['language' => 'nl', 'etim_unit_abbreviation' => 'V'],
						],
					],
				],
			],
		],
	];

	$attrs = $this->mapper->get_attributes($product);

	expect($attrs)->toHaveKey('Spanning', '230 V');
});

test('get_attributes skips not_applicable ETIM features', function () {
	$product = [
		'product_id' => 1,
		'_etim' => [
			[
				'_etim_features' => [
					[
						'etim_feature_code' => 'EF004',
						'etim_feature_type' => 'L',
						'logical_value' => true,
						'not_applicable' => true,
						'_etim_feature_translations' => [
							['language' => 'nl', 'etim_feature_description' => 'Overgeslagen'],
						],
					],
				],
			],
		],
	];

	$attrs = $this->mapper->get_attributes($product);

	expect($attrs)->not->toHaveKey('Overgeslagen');
});

test('get_attributes extracts alphanumeric ETIM feature', function () {
	$product = [
		'product_id' => 1,
		'_etim' => [
			[
				'_etim_features' => [
					[
						'etim_feature_code' => 'EF005',
						'etim_feature_type' => 'A',
						'etim_value_code' => 'EV001',
						'not_applicable' => false,
						'_etim_feature_translations' => [
							['language' => 'nl', 'etim_feature_description' => 'Kleur'],
						],
						'_etim_value_translations' => [
							['language' => 'nl', 'etim_value_description' => 'Zwart'],
						],
					],
				],
			],
		],
	];

	$attrs = $this->mapper->get_attributes($product);

	expect($attrs)->toHaveKey('Kleur', 'Zwart');
});

test('get_attributes extracts ETIM from product_groups', function () {
	$product = [
		'product_id' => 1,
		'_product_groups' => [
			[
				'product_group_name' => 'Schroeven',
				'_etim' => [
					[
						'_etim_features' => [
							[
								'etim_feature_code' => 'EF010',
								'etim_feature_type' => 'N',
								'numeric_value' => 50,
								'not_applicable' => false,
								'_etim_feature_translations' => [
									['language' => 'nl', 'etim_feature_description' => 'Lengte'],
								],
								'_etim_unit_translations' => [
									['language' => 'nl', 'etim_unit_abbreviation' => 'mm'],
								],
							],
						],
					],
				],
			],
		],
	];

	$attrs = $this->mapper->get_attributes($product);

	expect($attrs)->toHaveKey('Lengte', '50 mm');
});

test('get_attributes uses feature code as label when no translation', function () {
	$product = [
		'product_id' => 1,
		'_etim' => [
			[
				'_etim_features' => [
					[
						'etim_feature_code' => 'EF999',
						'etim_feature_type' => 'L',
						'logical_value' => true,
						'not_applicable' => false,
					],
				],
			],
		],
	];

	$attrs = $this->mapper->get_attributes($product);

	expect($attrs)->toHaveKey('EF999', 'Ja');
});

test('get_attributes deduplicates by ETIM feature code', function () {
	$product = [
		'product_id' => 1,
		'_etim' => [
			[
				'_etim_features' => [
					[
						'etim_feature_code' => 'EF001',
						'etim_feature_type' => 'L',
						'logical_value' => true,
						'not_applicable' => false,
						'_etim_feature_translations' => [
							['language' => 'nl', 'etim_feature_description' => 'Feature A'],
						],
					],
					[
						'etim_feature_code' => 'EF001',
						'etim_feature_type' => 'L',
						'logical_value' => false,
						'not_applicable' => false,
						'_etim_feature_translations' => [
							['language' => 'nl', 'etim_feature_description' => 'Feature A'],
						],
					],
				],
			],
		],
	];

	$attrs = $this->mapper->get_attributes($product);

	// First occurrence wins
	expect($attrs)->toHaveKey('Feature A', 'Ja');
});
