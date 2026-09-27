<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\TokenResolver;

beforeEach(function () {
    $this->resolver = new TokenResolver;
    $this->context = AutomationContext::make([
        'form' => ['email' => '  Foo@Example.COM ', 'name' => 'jane doe'],
        'lead' => ['tags' => ['a', 'b', 'c']],
        'when' => '2026-01-15 13:45:00',
        'empty' => '',
    ]);
});

it('still resolves plain tokens without filters', function () {
    expect($this->resolver->resolveString('Hi {{ form.name }}', $this->context))
        ->toBe('Hi jane doe');
});

it('preserves structured single tokens without filters', function () {
    expect($this->resolver->resolveString('{{ lead.tags }}', $this->context))
        ->toBe(['a', 'b', 'c']);
});

it('applies lower + trim filters', function () {
    expect($this->resolver->resolveString('{{ form.email | trim | lower }}', $this->context))
        ->toBe('foo@example.com');
});

it('applies the title filter', function () {
    expect($this->resolver->resolveString('{{ form.name | title }}', $this->context))
        ->toBe('Jane Doe');
});

it('applies the slug filter', function () {
    expect($this->resolver->resolveString('{{ form.name | slug }}', $this->context))
        ->toBe('jane-doe');
});

it('applies the date filter', function () {
    expect($this->resolver->resolveString('{{ when | date:Y-m-d }}', $this->context))
        ->toBe('2026-01-15');
});

it('applies the default filter for empty values', function () {
    expect($this->resolver->resolveString('{{ empty | default:N/A }}', $this->context))
        ->toBe('N/A');
});

it('applies the length filter to arrays', function () {
    // A single token preserves the filtered value's native type (int here).
    expect($this->resolver->resolveString('{{ lead.tags | length }}', $this->context))
        ->toBe(3);
    // Mid-string it is stringified.
    expect($this->resolver->resolveString('count: {{ lead.tags | length }}', $this->context))
        ->toBe('count: 3');
});

it('chains filters mid-string', function () {
    expect($this->resolver->resolveString('User: {{ form.email | trim | lower }}!', $this->context))
        ->toBe('User: foo@example.com!');
});

/*
 * 2.23.0: list and text filters, and a time zone on `date`. Built for a flow
 * that reads rows from Notion and writes one text per row; each filter is
 * checked on its own, and the old filters keep their behaviour.
 */
function listContext(): AutomationContext
{
    return AutomationContext::make([
        'tags' => ['Chor', 'Band', 'Technik'],
        'rows' => [
            ['name' => 'Get In', 'meta' => ['day' => 'Sa'], 'done' => true],
            ['name' => 'Soundcheck', 'meta' => ['day' => 'Sa'], 'done' => false],
            ['name' => 'Show', 'meta' => ['day' => 'So'], 'done' => true],
        ],
        'csv' => 'a, b ,c',
        'json' => '{"venue":"Kulturhaus","ids":[1,2]}',
        'broken_json' => '{nope',
        'utc' => '2026-10-03T13:00:00+00:00',
        'notion_date' => ['start' => '2026-10-03T15:00:00+02:00', 'end' => null, 'time_zone' => 'Europe/Berlin'],
        'empty_list' => [],
    ]);
}

it('joins a list with ", " by default', function () {
    expect((new TokenResolver)->resolveString('{{ tags | join }}', listContext()))->toBe('Chor, Band, Technik');
});

it('joins a list with a given separator, a quoted one and a line break', function () {
    $r = new TokenResolver;

    expect($r->resolveString('{{ tags | join:/ }}', listContext()))->toBe('Chor/Band/Technik')
        ->and($r->resolveString('{{ tags | join:" + " }}', listContext()))->toBe('Chor + Band + Technik')
        ->and($r->resolveString('{{ tags | join:\n }}', listContext()))->toBe("Chor\nBand\nTechnik");
});

it('leaves a scalar alone under join', function () {
    expect((new TokenResolver)->resolveString('{{ csv | join }}', listContext()))->toBe('a, b ,c');
});

it('plucks a key, also a nested one, from every item', function () {
    $r = new TokenResolver;

    expect($r->resolveString('{{ rows | pluck:name }}', listContext()))->toBe(['Get In', 'Soundcheck', 'Show'])
        ->and($r->resolveString('{{ rows | pluck:meta.day | join }}', listContext()))->toBe('Sa, Sa, So');
});

it('takes the first and the last item, and null from an empty list', function () {
    $r = new TokenResolver;

    expect($r->resolveString('{{ tags | first }}', listContext()))->toBe('Chor')
        ->and($r->resolveString('{{ tags | last }}', listContext()))->toBe('Technik')
        ->and($r->resolveString('{{ empty_list | first }}', listContext()))->toBeNull()
        ->and($r->resolveString('{{ rows | first }}', listContext()))->toBe(['name' => 'Get In', 'meta' => ['day' => 'Sa'], 'done' => true]);
});

it('splits text on a comma by default and trims the parts', function () {
    $r = new TokenResolver;

    expect($r->resolveString('{{ csv | split }}', listContext()))->toBe(['a', 'b', 'c'])
        ->and($r->resolveString('{{ csv | split:b }}', listContext()))->toBe(['a,', ',c']);
});

it('replaces text, also with a quoted space', function () {
    $r = new TokenResolver;

    expect($r->resolveString('{{ csv | replace:b,x }}', listContext()))->toBe('a, x ,c')
        ->and($r->resolveString('{{ csv | replace:" ",_ }}', listContext()))->toBe('a,_b_,c');
});

it('decodes JSON and leaves broken JSON as it was', function () {
    $r = new TokenResolver;

    expect($r->resolveString('{{ json | json_decode }}', listContext()))->toBe(['venue' => 'Kulturhaus', 'ids' => [1, 2]])
        ->and($r->resolveString('{{ broken_json | json_decode }}', listContext()))->toBe('{nope');
});

it('keeps the items whose key equals the value, booleans written as true and false', function () {
    $r = new TokenResolver;

    expect($r->resolveString('{{ rows | where:meta.day,Sa | pluck:name }}', listContext()))->toBe(['Get In', 'Soundcheck'])
        ->and($r->resolveString('{{ rows | where:done,true | pluck:name | join }}', listContext()))->toBe('Get In, Show')
        ->and($r->resolveString('{{ rows | where:name,Nope }}', listContext()))->toBe([]);
});

it('formats a date in a given time zone', function () {
    $r = new TokenResolver;

    expect($r->resolveString('{{ utc | date:H:i,Europe/Berlin }}', listContext()))->toBe('15:00')
        ->and($r->resolveString('{{ utc | date:H:i }}', listContext()))->toBe('13:00')
        ->and($r->resolveString('{{ utc | date:D, d.m. }}', listContext()))->toBe('Sat, 03.10.');
});

it('keeps a | and a , inside a quoted argument', function () {
    $r = new TokenResolver;

    expect($r->resolveString('{{ tags | join:" | " }}', listContext()))->toBe('Chor | Band | Technik')
        ->and($r->resolveString('{{ tags | join:" | " | upper }}', listContext()))->toBe('CHOR | BAND | TECHNIK')
        ->and($r->resolveString('Mitte {{ tags | join:\' | \' }} Ende', listContext()))->toBe('Mitte Chor | Band | Technik Ende')
        ->and($r->resolveString('{{ csv | replace:",",";" }}', listContext()))->toBe('a; b ;c')
        ->and($r->resolveString('{{ csv | replace:",", | trim }}', listContext()))->toBe('a b c')
        ->and($r->resolveString('{{ rows | where:name,"Get In" | pluck:name }}', listContext()))->toBe(['Get In']);
});

it('reads arguments written without quotes exactly as before', function () {
    $r = new TokenResolver;
    $context = AutomationContext::make(['empty' => '', 'name' => 'Ada', 'when' => '2026-01-15 13:45:00']);

    // An apostrophe inside a word is text, also twice across filters.
    expect($r->resolveString("{{ empty | default:it's }}", $context))->toBe("it's")
        ->and($r->resolveString("{{ empty | default:don't | replace:it's,x }}", $context))->toBe("don't")
        // Quotes around a default were never stripped, and still are not.
        ->and($r->resolveString('{{ empty | default:"N/A" }}', $context))->toBe('"N/A"')
        ->and($r->resolveString('{{ when | date:D, d.m. }}', $context))->toBe('Thu, 15.01.')
        ->and($r->resolveString('{{ name | lower | default:x }}!', $context))->toBe('ada!')
        ->and($r->resolveString('{{ name | replace:A,E }}', $context))->toBe('Eda');
});

it('formats the start of a Notion date value', function () {
    expect((new TokenResolver)->resolveString('{{ notion_date | date:d.m. H:i,Europe/Berlin }}', listContext()))
        ->toBe('03.10. 15:00');
});
