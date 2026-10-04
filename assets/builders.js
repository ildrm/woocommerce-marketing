/* Pure builder transformations; shared by the WordPress app and node tests. */
(function (root, factory) {
    var exports = factory();
    if (typeof module === 'object' && module.exports) module.exports = exports;
    else root.wmosBuilders = exports;
})(typeof window === 'undefined' ? globalThis : window, function () {
    'use strict';
    function __(text) { return typeof globalThis.wp !== 'undefined' && globalThis.wp.i18n ? globalThis.wp.i18n.__(text, 'woocommerce-marketing-os') : text; }
    var fields = ['state', 'user_id', 'order_count', 'revenue_minor', 'currency', 'last_order_at', 'created_at', 'tags', 'attributes.locale', 'attributes.region', 'attributes.lifecycle', 'attributes.birthday_month', 'attributes.birthday_day', 'facts.product_ids', 'facts.category_ids', 'facts.coupon_codes', 'facts.cart_count', 'facts.cart_total_minor', 'facts.rfm', 'facts.segment_uuids', 'facts.open_count', 'facts.click_count', 'facts.referral_count', 'facts.loyalty_points', 'facts.consent'];
    var operators = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'contains', 'exists'];
    var nodeTypes = ['trigger', 'condition', 'filter', 'branch', 'delay', 'wait', 'action', 'goal', 'exit', 'split', 'join', 'experiment', 'webhook', 'subworkflow'];
    function rule() { return { field: 'order_count', operator: 'gte', value: 1 }; }
    function contentDefaults(channel) {
        if (channel === 'email') return { subject: '', text: '', html: '' };
        if (channel === 'whatsapp') return { template: '', language: 'en_US', components: [] };
        if (channel === 'push') return { title: '', text: '' };
        return { text: '' };
    }
    function defaults(kind) {
        switch (kind) {
        case 'campaign': return { audience: { all: true }, channel: 'email', purpose: 'marketing', content: { subject: '', text: '', html: '' } };
        case 'segment': return { rule: { all: [rule()] }, mode: 'materialized' };
        case 'automation': return { trigger: { event: 'commerce.order.paid' }, nodes: [{ id: 'start', type: 'trigger', config: {} }, { id: 'finish', type: 'exit', config: {} }], edges: [{ from: 'start', to: 'finish', outcome: 'success' }], reentry: 'once' };
        case 'experiment': return { unit: 'profile', metric: 'conversion', variants: [{ key: 'control', weight: 5000 }, { key: 'variant', weight: 5000 }] };
        case 'promotion': return { type: 'percent', amount: 10, individual_use: true, usage_limit: 1, description: '' };
        case 'program': return { type: 'loyalty', points_per_order: 10, currency: 'USD', exponent: 2, hold_days: 30, refund_policy: 'proportional', financial_retention_days: 0 };
        case 'offline': return { type: 'poster', location: '', notes: '', cost: 0, currency: 'USD', exponent: 2 };
        case 'event': return { type: 'event', location: '', timezone: 'UTC', capacity: 0, cost: 0, currency: 'USD', exponent: 2 };
        case 'content': case 'asset': return { type: 'email', subject: '', text: '', html: '', locale: 'en_US' };
        case 'personalization': return { surface: 'banner', title: '', html: '', rule: rule(), strategy: 'latest', config: {}, fallback: { title: '', html: '', strategy: 'latest', config: {} } };
        case 'recommendation': return { surface: 'recommendations', title: 'Recommended products', strategy: 'latest', config: { limit: 6 }, fallback: { title: 'Products', strategy: 'latest', config: { limit: 6 } } };
        case 'partner': case 'influencer': return { type: kind, handles: [], deliverables: [], cost: 0, currency: 'USD', exponent: 2, terms: '' };
        default: throw new Error(__('Unknown definition kind'));
        }
    }
    function nodeConfig(type) {
        if (['condition', 'filter', 'goal'].includes(type)) return { rule: rule() };
        if (type === 'delay') return { seconds: 3600 };
        if (type === 'wait') return { event: 'commerce.order.completed', timeout: 86400 };
        if (type === 'action') return { action: 'tag', tag: 'engaged' };
        if (type === 'branch') return { cases: [{ outcome: 'customer', rule: rule() }] };
        if (type === 'split') return {};
        if (type === 'join') return { split: '' };
        if (['experiment', 'subworkflow'].includes(type)) return { definition_uuid: '' };
        if (type === 'webhook') return { provider_uuid: '', data: {} };
        return {};
    }
    function changeNode(graph, index, changes) {
        var nodes = graph.nodes.map(function (node, i) { return i === index ? Object.assign({}, node, changes) : node; });
        var before = graph.nodes[index].id;
        var after = nodes[index].id;
        var edges = graph.edges.map(function (edge) { return Object.assign({}, edge, { from: edge.from === before ? after : edge.from, to: edge.to === before ? after : edge.to }); });
        return Object.assign({}, graph, { nodes: nodes, edges: edges });
    }
    function removeNode(graph, index) {
        var removed = graph.nodes[index].id;
        return Object.assign({}, graph, { nodes: graph.nodes.filter(function (_, i) { return i !== index; }), edges: graph.edges.filter(function (edge) { return edge.from !== removed && edge.to !== removed; }) });
    }
    function graphErrors(graph) {
        var errors = [];
        var ids = new Set();
        var triggers = 0;
        graph.nodes.forEach(function (node) {
            if (!/^[A-Za-z0-9_-]{1,64}$/.test(node.id)) errors.push('Node identifiers must use letters, numbers, underscore or hyphen.');
            if (ids.has(node.id)) errors.push('Node identifiers must be unique.');
            ids.add(node.id);
            if (node.type === 'trigger') triggers++;
        });
        if (triggers !== 1) errors.push('The workflow needs exactly one trigger node.');
        graph.edges.forEach(function (edge) { if (!ids.has(edge.from) || !ids.has(edge.to)) errors.push('Every connection must reference existing nodes.'); });
        return Array.from(new Set(errors));
    }
    function parseValue(value, operator) {
        if (operator === 'in') {
            var parsed = JSON.parse(value);
            if (!Array.isArray(parsed) || !parsed.length || parsed.length > 50) throw new Error(__('Enter an array of 1–50 values.'));
            return parsed;
        }
        if (/^-?\d+$/.test(value) && Number.isSafeInteger(Number(value))) return Number(value);
        if (value === 'true') return true;
        if (value === 'false') return false;
        return value;
    }
    function displayValue(key, row, locale, timeZone) {
        var value = row[key];
        if (value == null) return '—';
        var raw = String(value);
        try {
            if (key.endsWith('_at') && /^\d{4}-\d{2}-\d{2}[ T]/.test(raw)) {
                var instant = new Date(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw) ? raw.replace(' ', 'T') + 'Z' : raw);
                if (!Number.isNaN(instant.getTime())) return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short', timeZone: timeZone }).format(instant);
            }
            if ((key.endsWith('_minor') || key === 'minor') && /^[A-Z]{3}$/.test(row.currency || '') && row.exponent != null && Number.isInteger(Number(row.exponent)) && Number(row.exponent) >= 0 && Number(row.exponent) <= 4 && /^-?\d+$/.test(raw) && Number.isSafeInteger(Number(raw))) {
                var exponent = Number(row.exponent), negative = Number(raw) < 0;
                var digits = String(Math.abs(Number(raw))).padStart(exponent + 1, '0');
                var decimal = (negative ? '-' : '') + (exponent ? digits.slice(0, -exponent) + '.' + digits.slice(-exponent) : digits);
                return new Intl.NumberFormat(locale, { style: 'currency', currency: row.currency, minimumFractionDigits: exponent, maximumFractionDigits: exponent }).format(decimal);
            }
            if (['orders', 'order_count', 'points', 'attempts', 'conversions', 'exposures', 'clicks', 'opens'].includes(key) && /^-?\d+$/.test(raw) && Number.isSafeInteger(Number(raw))) return new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(Number(raw));
        } catch (error) { /* Preserve exact source values when the browser cannot format the configured locale. */ }
        return raw;
    }
    return { fields: fields, operators: operators, nodeTypes: nodeTypes, rule: rule, contentDefaults: contentDefaults, defaults: defaults, nodeConfig: nodeConfig, changeNode: changeNode, removeNode: removeNode, graphErrors: graphErrors, parseValue: parseValue, displayValue: displayValue };
});
