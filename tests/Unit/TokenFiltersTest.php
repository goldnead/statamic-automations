<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\TokenResolver;

beforeEach(function () {
    $this->resolver = new TokenResolver();
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
