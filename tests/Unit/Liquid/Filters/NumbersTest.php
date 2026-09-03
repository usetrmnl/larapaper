<?php

use App\Liquid\Filters\Numbers;

test('number_with_delimiter formats numbers with commas by default', function (): void {
    $filter = new Numbers();

    expect($filter->number_with_delimiter(1234))->toBe('1,234')
        ->and($filter->number_with_delimiter(1000000))->toBe('1,000,000')
        ->and($filter->number_with_delimiter(0))->toBe('0');
});

test('number_with_delimiter handles custom delimiters', function (): void {
    $filter = new Numbers();

    expect($filter->number_with_delimiter(1234, '.'))->toBe('1.234')
        ->and($filter->number_with_delimiter(1000000, ' '))->toBe('1 000 000');
});

test('number_with_delimiter handles decimal values with custom separators', function (): void {
    $filter = new Numbers();

    expect($filter->number_with_delimiter(1234.57, ' ', ','))->toBe('1 234,57')
        ->and($filter->number_with_delimiter(1234.5, '.', ','))->toBe('1.234,50');
});

test('number_to_currency formats numbers with dollar sign by default', function (): void {
    $filter = new Numbers();

    expect($filter->number_to_currency(1234))->toBe('$1,234')
        ->and($filter->number_to_currency(1234.5))->toBe('$1,234.50')
        ->and($filter->number_to_currency(0))->toBe('$0');
});

test('number_to_currency handles custom currency symbols', function (): void {
    $filter = new Numbers();

    expect($filter->number_to_currency(1234, '£'))->toBe('£1,234')
        ->and($filter->number_to_currency(152350.69, '€'))->toBe('€152,350.69');
});

test('number_to_currency handles custom delimiters and separators', function (): void {
    $filter = new Numbers();

    $result1 = $filter->number_to_currency(1234.57, '£', '.', ',');
    $result2 = $filter->number_to_currency(1234.57, '€', ',', '.');

    expect($result1)->toContain('1.234,57')
        ->toContain('£')
        ->and($result2)->toContain('1,234.57')
        ->toContain('€');
});

test('number_with_delimiter handles string numbers', function (): void {
    $filter = new Numbers();

    expect($filter->number_with_delimiter('1234'))->toBe('1,234')
        ->and($filter->number_with_delimiter('1234.56'))->toBe('1,234.56');
});

test('number_with_delimiter handles negative numbers', function (): void {
    $filter = new Numbers();

    expect($filter->number_with_delimiter(-1234))->toBe('-1,234')
        ->and($filter->number_with_delimiter(-1234.56))->toBe('-1,234.56');
});

test('number_with_delimiter handles zero', function (): void {
    $filter = new Numbers();

    expect($filter->number_with_delimiter(0))->toBe('0')
        ->and($filter->number_with_delimiter(0.0))->toBe('0.00');
});

test('number_with_delimiter handles very small numbers', function (): void {
    $filter = new Numbers();

    expect($filter->number_with_delimiter(0.01))->toBe('0.01')
        ->and($filter->number_with_delimiter(0.001))->toBe('0.00');
});

test('number_to_currency handles string numbers', function (): void {
    $filter = new Numbers();

    expect($filter->number_to_currency('1234'))->toBe('$1,234')
        ->and($filter->number_to_currency('1234.56'))->toBe('$1,234.56');
});

test('number_to_currency handles negative numbers', function (): void {
    $filter = new Numbers();

    expect($filter->number_to_currency(-1234))->toBe('-$1,234')
        ->and($filter->number_to_currency(-1234.56))->toBe('-$1,234.56');
});

test('number_to_currency handles zero', function (): void {
    $filter = new Numbers();

    expect($filter->number_to_currency(0))->toBe('$0')
        ->and($filter->number_to_currency(0.0))->toBe('$0.00');
});

test('number_to_currency handles currency code conversion', function (): void {
    $filter = new Numbers();

    expect($filter->number_to_currency(1234, '$'))->toBe('$1,234')
        ->and($filter->number_to_currency(1234, '€'))->toBe('€1,234')
        ->and($filter->number_to_currency(1234, '£'))->toBe('£1,234');
});

test('number_to_currency handles German locale formatting', function (): void {
    $filter = new Numbers();

    // When delimiter is '.' and separator is ',', it should use German locale
    $result = $filter->number_to_currency(1234.56, 'EUR', '.', ',');
    expect($result)->toContain('1.234,56');
});

test('number_with_delimiter handles different decimal separators', function (): void {
    $filter = new Numbers();

    expect($filter->number_with_delimiter(1234.56, ',', ','))->toBe('1,234,56')
        ->and($filter->number_with_delimiter(1234.56, ' ', ','))->toBe('1 234,56');
});

test('number_to_currency handles very large numbers', function (): void {
    $filter = new Numbers();

    expect($filter->number_to_currency(1000000))->toBe('$1,000,000')
        ->and($filter->number_to_currency(1000000.50))->toBe('$1,000,000.50');
});

test('number_with_delimiter handles very large numbers', function (): void {
    $filter = new Numbers();

    expect($filter->number_with_delimiter(1000000))->toBe('1,000,000')
        ->and($filter->number_with_delimiter(1000000.50))->toBe('1,000,000.50');
});

test('random_number returns integer within default bounds (0-100)', function (): void {
    $filter = new Numbers();

    $result = $filter->random_number('');
    expect($result)->toBeInt()
        ->toBeGreaterThanOrEqual(0)
        ->toBeLessThanOrEqual(100);
});

test('random_number with two args returns integer within range', function (): void {
    $filter = new Numbers();

    foreach (range(1, 20) as $_) {
        $result = $filter->random_number('', 1, 6);
        expect($result)->toBeGreaterThanOrEqual(1)
            ->toBeLessThanOrEqual(6);
    }
});

test('random_number with single arg treats it as upper bound', function (): void {
    $filter = new Numbers();

    foreach (range(1, 20) as $_) {
        $result = $filter->random_number('', 6);
        expect($result)->toBeGreaterThanOrEqual(0)
            ->toBeLessThanOrEqual(6);
    }
});

test('random_number normalizes reversed bounds', function (): void {
    $filter = new Numbers();

    foreach (range(1, 20) as $_) {
        $result = $filter->random_number('', 6, 1);
        expect($result)->toBeGreaterThanOrEqual(1)
            ->toBeLessThanOrEqual(6);
    }
});

test('random_number returns exact value when bounds are equal', function (): void {
    $filter = new Numbers();

    expect($filter->random_number('', 3, 3))->toBe(3);
});

test('random_number casts string bounds', function (): void {
    $filter = new Numbers();

    foreach (range(1, 20) as $_) {
        $result = $filter->random_number('', '1', '6');
        expect($result)->toBeGreaterThanOrEqual(1)
            ->toBeLessThanOrEqual(6);
    }
});

test('random_number ignores piped value', function (): void {
    $filter = new Numbers();

    foreach (range(1, 20) as $_) {
        $result = $filter->random_number(99, 1, 6);
        expect($result)->toBeGreaterThanOrEqual(1)
            ->toBeLessThanOrEqual(6);
    }
});

test('random_number works in liquid template with arithmetic', function (): void {
    $environment = new Keepsuit\Liquid\Environment(
        extensions: [new Keepsuit\Liquid\Extensions\StandardExtension()]
    );
    $environment->filterRegistry->register(Numbers::class);

    $template = $environment->parseString('{% assign number = nil | random_number: 5, 5 %}{{ number | plus: 1 }}');
    $context = $environment->newRenderContext();
    $result = $template->render($context);

    expect(mb_trim($result))->toBe('6');
});
