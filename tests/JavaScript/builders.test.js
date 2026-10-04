'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const B = require('../../assets/builders.js');
test('renaming a graph node updates both incoming and outgoing connections without mutating the pinned input', () => {
    const graph = B.defaults('automation'); graph.edges.push({ from: 'finish', to: 'start', outcome: 'again' });
    const original = structuredClone(graph); const renamed = B.changeNode(graph, 0, { id: 'entrance' });
    assert.deepEqual(graph, original); assert.equal(renamed.edges[0].from, 'entrance'); assert.equal(renamed.edges[1].to, 'entrance');
});
test('removing a node removes incident connections while preserving unrelated edges', () => {
    const graph = B.defaults('automation'); graph.nodes.push({ id: 'other', type: 'exit', config: {} }); graph.edges.push({ from: 'finish', to: 'other' });
    const removed = B.removeNode(graph, 0); assert.equal(removed.edges.length, 1); assert.equal(removed.edges[0].to, 'other'); assert.equal(graph.nodes.length, 3);
});
test('graph errors reject duplicate identifiers, missing trigger and orphan edges', () => {
    assert.deepEqual(B.graphErrors(B.defaults('automation')), []);
    const graph = { nodes: [{ id: 'bad id', type: 'exit' }, { id: 'bad id', type: 'exit' }], edges: [{ from: 'absent', to: 'bad id' }] };
    assert.equal(B.graphErrors(graph).length, 4);
});
test('membership rules keep exact integers and reject incomplete or oversized JSON arrays', () => {
    assert.deepEqual(B.parseValue('[1,"a",false]', 'in'), [1, 'a', false]); assert.throws(() => B.parseValue('[', 'in')); assert.throws(() => B.parseValue('[]', 'in')); assert.throws(() => B.parseValue(JSON.stringify(Array(51).fill(1)), 'in'));
    assert.equal(B.parseValue('9007199254740993', 'eq'), '9007199254740993'); assert.equal(B.parseValue('1001', 'gte'), 1001); assert.equal(B.parseValue('true', 'eq'), true);
});
test('fresh workflow and experiment drafts do not share mutable arrays or configuration', () => {
    const a = B.defaults('automation'), b = B.defaults('automation'); a.nodes[0].id = 'changed'; assert.equal(b.nodes[0].id, 'start');
    assert.equal(B.defaults('experiment').variants.reduce((sum, v) => sum + v.weight, 0), 10000);
    assert.equal(B.defaults('program').financial_retention_days, 0);
});

test('channel-specific drafts remove stale email fields when switching to SMS or approved WhatsApp templates', () => {
    assert.deepEqual(B.contentDefaults('sms'), {text:''}); assert.deepEqual(Object.keys(B.contentDefaults('whatsapp')).sort(), ['components','language','template']); assert.deepEqual(B.contentDefaults('push'), {title:'',text:''});
});

test('display formatting uses merchant timezone and user locale while retaining exact unsupported amounts', () => {
    assert.equal(B.displayValue('orders', {orders:1234}, 'de-DE', 'UTC'), '1.234');
    assert.equal(B.displayValue('created_at', {created_at:'2026-01-01 23:00:00'}, 'en-GB', 'Asia/Tehran'), '2 Jan 2026, 02:30');
    assert.equal(B.displayValue('net_revenue_minor', {net_revenue_minor:1001, currency:'USD', exponent:2}, 'en-US', 'UTC'), '$10.01');
    assert.equal(B.displayValue('net_revenue_minor', {net_revenue_minor:'9007199254740991', currency:'USD', exponent:2}, 'en-US', 'UTC'), '$90,071,992,547,409.91');
    assert.equal(B.displayValue('net_revenue_minor', {net_revenue_minor:'9007199254740993', currency:'USD', exponent:2}, 'en-US', 'UTC'), '9007199254740993');
    assert.equal(B.displayValue('net_revenue_minor', {net_revenue_minor:1001, currency:'USD'}, 'en-US', 'UTC'), '1001');
    assert.equal(B.displayValue('net_revenue_minor', {net_revenue_minor:1001, currency:'USD', exponent:null}, 'en-US', 'UTC'), '1001');
    assert.equal(B.displayValue('created_at', {created_at:'2026-01-01 23:00:00'}, 'en-US', 'invalid-zone'), '2026-01-01 23:00:00');
});
