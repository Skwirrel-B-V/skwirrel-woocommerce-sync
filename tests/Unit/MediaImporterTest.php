<?php

declare(strict_types=1);

beforeEach(function () {
	$this->importer = new Skwirrel_WC_Sync_Media_Importer();
});

// ------------------------------------------------------------------
// is_image_attachment_type()
// ------------------------------------------------------------------

test('is_image_attachment_type recognizes all image types', function (string $code) {
	expect($this->importer->is_image_attachment_type($code))->toBeTrue();
})->with(['IMG', 'PPI', 'PHI', 'LOG', 'SCH', 'PRT', 'OTV']);

test('is_image_attachment_type is case insensitive', function () {
	expect($this->importer->is_image_attachment_type('img'))->toBeTrue();
	expect($this->importer->is_image_attachment_type('Ppi'))->toBeTrue();
	expect($this->importer->is_image_attachment_type('log'))->toBeTrue();
});

test('is_image_attachment_type rejects non-image types', function (string $code) {
	expect($this->importer->is_image_attachment_type($code))->toBeFalse();
})->with(['MAN', 'DAT', 'CER', 'WAR', 'PDF', '', 'UNKNOWN']);
