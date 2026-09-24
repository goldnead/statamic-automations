/**
 * A handle from a name, the way core makes one: Statamic's own slug service
 * with `_` as the separator (what the blueprint editor does for field
 * handles), so "Nachricht senden" becomes `nachricht_senden` and umlauts are
 * transliterated per the site's language.
 *
 * The API wants `^[a-z][a-z0-9_]*$`; whatever the slug leaves outside that is
 * trimmed off here rather than refused on save. Outside the CP (tests), a
 * plain ASCII fallback stands in for the slug service.
 */
export function handleFrom(value) {
    const text = String(value ?? '');
    const slug = globalThis.Statamic?.$slug;

    let out;
    try {
        out = slug ? slug.separatedBy('_').create(text) : fallback(text);
    } catch {
        out = fallback(text);
    }

    return String(out)
        .toLowerCase()
        .replace(/[^a-z0-9_]+/g, '_')
        .replace(/^[^a-z]+/, '')
        .replace(/_+/g, '_')
        .replace(/_+$/, '');
}

function fallback(text) {
    return text
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_');
}
