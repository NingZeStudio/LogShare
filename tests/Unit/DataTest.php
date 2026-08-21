<?php

use App\Data\MetadataEntry;
use App\Data\Token;

test('Token generates a strong random value', function () {
    $a = new Token();
    $b = new Token();

    expect($a->get())->toBeString();
    expect(strlen($a->get()))->toBe(64);
    expect($a->get())->not->toBe($b->get());
});

test('Token matches with constant-time comparison', function () {
    $token = new Token();
    expect($token->matches($token->get()))->toBeTrue();
    expect($token->matches('wrong'))->toBeFalse();
});

test('Token json serializes to its value', function () {
    $token = new Token();
    expect(json_encode($token))->toBe(json_encode($token->get()));
});

test('Token accepts a fixed value', function () {
    $token = new Token('abc123');
    expect($token->get())->toBe('abc123');
    expect($token->matches('abc123'))->toBeTrue();
});

test('MetadataEntry parses from array', function () {
    $entry = MetadataEntry::fromArray([
        'key' => 'version',
        'value' => '1.20.1',
        'label' => 'Version',
        'visible' => false,
    ]);

    expect($entry)->toBeInstanceOf(MetadataEntry::class);
    expect($entry->getKey())->toBe('version');
    expect($entry->getValue())->toBe('1.20.1');
    expect($entry->getDisplayLabel())->toBe('Version');
    expect($entry->getLabel())->toBe('Version');
    expect($entry->isVisible())->toBeFalse();
    expect($entry->getDisplayValue())->toBe('1.20.1');
});

test('MetadataEntry fromArray returns null when invalid', function () {
    expect(MetadataEntry::fromArray(['key' => 'x']))->toBeNull();
    expect(MetadataEntry::fromArray(['value' => 'x']))->toBeNull();
    expect(MetadataEntry::fromArray([]))->toBeNull();
});

test('MetadataEntry parses from object', function () {
    $obj = (object) ['key' => 'k', 'value' => 'v', 'label' => 'L', 'visible' => true];
    $entry = MetadataEntry::fromObject($obj);

    expect($entry)->toBeInstanceOf(MetadataEntry::class);
    expect($entry->getKey())->toBe('k');
    expect($entry->getValue())->toBe('v');
});

test('MetadataEntry allFromArray caps at max entries', function () {
    $data = array_fill(0, 200, ['key' => 'k', 'value' => 'v']);
    $entries = MetadataEntry::allFromArray($data);

    expect(count($entries))->toBe(MetadataEntry::MAX_ENTRIES);
});

test('MetadataEntry allFromArray skips invalid entries', function () {
    $entries = MetadataEntry::allFromArray([
        ['key' => 'a', 'value' => '1'],
        ['not-an-array-value' => true],
        ['key' => 'b', 'value' => '2'],
    ]);

    expect($entries)->toHaveCount(2);
});

test('MetadataEntry truncates oversized values', function () {
    $entry = MetadataEntry::fromArray([
        'key' => 'long',
        'value' => str_repeat('x', 2000),
    ]);

    expect(strlen((string) $entry->getValue()))->toBeLessThanOrEqual(1024);
});

test('MetadataEntry jsonSerialize matches expected shape', function () {
    $entry = MetadataEntry::fromArray(['key' => 'k', 'value' => 'v', 'label' => 'L', 'visible' => true]);
    expect($entry->jsonSerialize())->toMatchArray([
        'key' => 'k',
        'value' => 'v',
        'label' => 'L',
        'visible' => true,
    ]);
});

test('MetadataEntry getDisplayLabel falls back to key', function () {
    $entry = MetadataEntry::fromArray(['key' => 'fallback', 'value' => 'v']);
    expect($entry->getDisplayLabel())->toBe('fallback');
});