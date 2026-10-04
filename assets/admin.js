(function (wp, boot, B) {
    'use strict';
    if (!wp || !boot || !B) return;
    var h = wp.element.createElement, useState = wp.element.useState, useEffect = wp.element.useEffect;
    var C = wp.components, __ = function (text) { return wp.i18n.__(text, 'woocommerce-marketing-os'); };
    var Button = C.Button, Text = C.TextControl, Select = C.SelectControl, Area = C.TextareaControl, Check = C.CheckboxControl;
    var resources = {
        campaigns: ['Campaigns', 'campaign', 'manage_campaigns'], automations: ['Automations', 'automation', 'manage_automations'], segments: ['Segments', 'segment', 'manage_campaigns'],
        promotions: ['Promotions', 'promotion', 'manage_promotions'], programs: ['Loyalty and partner programs', 'program', 'manage_partners'], experiments: ['Experiments', 'experiment', 'manage_campaigns'],
        assets: ['Assets', 'asset', 'manage_campaigns'], offline: ['Offline placements', 'offline', 'manage_campaigns'], 'marketing-events': ['Event marketing', 'event', 'manage_campaigns'],
        influencers: ['Influencers', 'influencer', 'manage_partners'], content: ['Content and SEO briefs', 'content', 'manage_campaigns'], partners: ['Partners', 'partner', 'manage_partners'], personalization: ['Personalization', 'personalization', 'manage_campaigns'], recommendations: ['Recommendations', 'recommendation', 'manage_campaigns']
    };
    function key() { return typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : 'request-' + Date.now() + '-' + Math.random().toString(36).slice(2); }
    var pendingRequests = new Map();
    function request(path, method, data, revision, signal) {
        var headers = { 'X-WP-Nonce': boot.nonce };
        var mutation = method && method !== 'GET', fingerprint = mutation ? method + '|' + path + '|' + JSON.stringify(data) + '|' + revision : null;
        var intent = mutation ? pendingRequests.get(fingerprint) || { key: key(), promise: null } : null;
        if (intent && intent.promise) return intent.promise;
        if (mutation) headers['Idempotency-Key'] = intent.key;
        if (revision) headers['If-Match'] = '"' + revision + '"';
        if (method && method !== 'GET' && document.querySelector('[data-wmos-invalid="true"]')) return Promise.reject(new Error(__('Complete or correct the invalid editor field before submitting.')));
        var response = wp.apiFetch({ url: boot.restURL + path, method: method || 'GET', data: data, headers: headers, signal: signal });
        if (!mutation) return response;
        intent.promise = response.then(function (result) { pendingRequests.delete(fingerprint); return result; }, function (error) { intent.promise = null; if (error.code && error.code !== 'fetch_error') pendingRequests.delete(fingerprint); throw error; });
        pendingRequests.set(fingerprint, intent); return intent.promise;
    }
    function json(value) { return JSON.stringify(value, null, 2); }
    function button(label, onClick, extra) { return h(Button, Object.assign({ onClick: onClick, variant: 'secondary' }, extra || {}), __(label)); }
    function field(label, value, onChange, extra) { return h(Text, Object.assign({ label: __(label), value: value == null ? '' : String(value), onChange: onChange }, extra || {})); }
    function select(label, value, options, onChange) { return h(Select, { label: __(label), value: value, options: options.map(function (x) { return typeof x === 'string' ? { value: x, label: __(x) } : x; }), onChange: onChange }); }
    function Empty(props) { return h('div', { className: 'wmos-empty' }, h('h3', null, __(props.title || 'No records yet')), h('p', null, __(props.text || 'Create a definition to start. Published versions preserve their execution history.'))); }
    function JsonField(props) {
        var pair = useState(json(props.value)), text = pair[0], setText = pair[1];
        var errorState = useState(''), error = errorState[0], setError = errorState[1];
        useEffect(function () { setText(json(props.value)); }, [JSON.stringify(props.value)]);
        function change(value) {
            setText(value);
            try { var parsed = JSON.parse(value); if (parsed === null || typeof parsed !== 'object') throw new Error(__('Enter a JSON object or array.')); props.onChange(parsed); setError(''); }
            catch (e) { setError(e instanceof SyntaxError ? __('Enter valid JSON.') : e.message); }
        }
        return h('div', { 'data-wmos-invalid': error ? 'true' : 'false' }, h(Area, { label: __(props.label || 'Advanced configuration (JSON)'), className: 'wmos-json', value: text, rows: props.rows || 8, onChange: change, help: __(props.help || 'JSON is validated on save and again before publication.') }), error ? h('p', { role: 'alert' }, error) : null);
    }
    function RuleValue(props) {
        var pair = useState(typeof props.value === 'object' ? json(props.value) : String(props.value == null ? '' : props.value)), value = pair[0], setValue = pair[1];
        var errors = useState(''), error = errors[0], setError = errors[1];
        useEffect(function () { setValue(typeof props.value === 'object' ? json(props.value) : String(props.value == null ? '' : props.value)); setError(''); }, [JSON.stringify(props.value), props.operator]);
        return h('div', { 'data-wmos-invalid': error ? 'true' : 'false' }, field('Value', value, function (next) {
            setValue(next); try { props.onChange(B.parseValue(next, props.operator)); setError(''); } catch (e) { setError(e instanceof SyntaxError ? __('Enter valid JSON.') : e.message); }
        }, { help: props.operator === 'in' ? __('Enter a JSON array, for example [1, 2].') : __('Numbers use exact integer values; revenue is in minor currency units.') }), error ? h('p', { role: 'alert' }, error) : null);
    }
    function RuleEditor(props) {
        var rule = props.value, group = rule.all ? 'all' : rule.any ? 'any' : rule.not ? 'not' : null;
        function convert(value) { props.onChange(value === 'leaf' ? B.rule() : value === 'not' ? { not: B.rule() } : Object.fromEntries([[value, [B.rule()]]])); }
        if (group) {
            var children = group === 'not' ? [rule.not] : rule[group];
            return h('div', { className: 'wmos-rule' }, select('Condition group', group, [{ value: 'all', label: __('All conditions (AND)') }, { value: 'any', label: __('Any condition (OR)') }, { value: 'not', label: __('Not') }, { value: 'leaf', label: __('Single condition') }], convert), children.map(function (child, i) {
                return h('div', { key: i }, h(RuleEditor, { value: child, onChange: function (next) { var updated = children.map(function (x, j) { return i === j ? next : x; }); props.onChange(group === 'not' ? { not: next } : Object.fromEntries([[group, updated]])); } }), children.length > 1 ? button('Remove condition', function () { props.onChange(Object.fromEntries([[group, children.filter(function (_, j) { return j !== i; })]])); }) : null);
            }), group !== 'not' ? h('div', { className: 'wmos-actions' }, button('Add condition', function () { props.onChange(Object.fromEntries([[group, children.concat([B.rule()])]])); }), button('Add nested group', function () { props.onChange(Object.fromEntries([[group, children.concat([{ any: [B.rule()] }])]])); })) : null);
        }
        return h('div', { className: 'wmos-rule' }, h('div', { className: 'wmos-rule-row' }, select('Field', rule.field, B.fields, function (value) { props.onChange(Object.assign({}, rule, { field: value })); }), select('Operator', rule.operator, B.operators, function (value) { props.onChange(Object.assign({}, rule, { operator: value })); }), rule.operator !== 'exists' ? h(RuleValue, { value: rule.value, operator: rule.operator, onChange: function (value) { props.onChange(Object.assign({}, rule, { value: value })); } }) : h('p', null, __('Tests whether the field is known.')), button('Make group', function () { props.onChange({ all: [rule] }); })));
    }
    function Workflow(props) {
        var graph = props.value;
        function node(index, changes) { props.onChange(B.changeNode(graph, index, changes)); }
        var errors = B.graphErrors(graph);
        return h('div', null,
            field('Trigger event', (graph.trigger || {}).event, function (value) { props.onChange(Object.assign({}, graph, { trigger: { event: value } })); }),
            h('p', { className: 'wmos-muted' }, __('The outline and node forms provide the same editing functions without dragging. Server validation rejects unreachable nodes, invalid branches and loops.')),
            h(WorkflowCanvas, { graph: graph }),
            h('nav', { className: 'wmos-outline', 'aria-label': __('Workflow canvas outline') }, graph.nodes.map(function (n, i) { return h('a', { key: n.id + i, href: '#wmos-node-' + i }, n.id + ' · ' + __(n.type)); })),
            errors.length ? h('ul', { role: 'alert' }, errors.map(function (error) { return h('li', { key: error }, __(error)); })) : null,
            graph.nodes.map(function (n, i) {
                return h('fieldset', { className: 'wmos-node', id: 'wmos-node-' + i, key: i }, h('legend', null, __('Node') + ' ' + (i + 1)), h('div', { className: 'wmos-split' }, field('Node identifier', n.id, function (value) { node(i, { id: value }); }), select('Node type', n.type, B.nodeTypes, function (type) { node(i, { type: type, config: B.nodeConfig(type) }); })),
                    ['condition', 'filter', 'goal'].includes(n.type) ? h(RuleEditor, { value: n.config.rule || B.rule(), onChange: function (value) { node(i, { config: Object.assign({}, n.config, { rule: value }) }); } }) : null,
                    n.type === 'delay' ? field('Delay (seconds)', n.config.seconds, function (value) { node(i, { config: { seconds: Number(value) } }); }, { type: 'number', min: 0 }) : null,
                    n.type === 'wait' ? h('div', null, field('Wait for event', n.config.event, function (value) { node(i, { config: Object.assign({}, n.config, { event: value }) }); }), field('Timeout (seconds)', n.config.timeout, function (value) { node(i, { config: Object.assign({}, n.config, { timeout: Number(value) }) }); }, { type: 'number' })) : null,
                    n.type === 'action' ? h('div', null, select('Action', n.config.action || 'tag', ['tag', 'message', 'coupon', 'points', 'review'], function (value) { node(i, { config: { action: value } }); }), h(JsonField, { label: 'Action configuration', value: n.config, onChange: function (value) { node(i, { config: value }); }, help: 'Message uses provider_uuid, channel, purpose and content; every send checks current consent.' })) : null,
                    !['trigger', 'exit', 'action', 'delay', 'wait', 'condition', 'filter', 'goal'].includes(n.type) ? h(JsonField, { label: 'Node configuration', value: n.config, onChange: function (value) { node(i, { config: value }); } }) : null,
                    h('div', { className: 'wmos-actions' }, i > 0 ? button('Move up', function () { var nodes = graph.nodes.slice(); nodes.splice(i - 1, 0, nodes.splice(i, 1)[0]); props.onChange(Object.assign({}, graph, { nodes: nodes })); }) : null,
                        i < graph.nodes.length - 1 ? button('Move down', function () { var nodes = graph.nodes.slice(); nodes.splice(i + 1, 0, nodes.splice(i, 1)[0]); props.onChange(Object.assign({}, graph, { nodes: nodes })); }) : null,
                        button('Remove node', function () { props.onChange(B.removeNode(graph, i)); }, { isDestructive: true })));
            }), button('Add node', function () { var id = 'node_' + (graph.nodes.length + 1); while (graph.nodes.some(function (n) { return n.id === id; })) id += '_new'; props.onChange(Object.assign({}, graph, { nodes: graph.nodes.concat([{ id: id, type: 'action', config: B.nodeConfig('action') }]) })); }),
            h('h3', null, __('Connections')), graph.edges.map(function (edge, i) { return h('div', { className: 'wmos-rule-row', key: i }, select('From node', edge.from, graph.nodes.map(function (n) { return n.id; }), function (value) { props.onChange(Object.assign({}, graph, { edges: graph.edges.map(function (x, j) { return j === i ? Object.assign({}, x, { from: value }) : x; }) })); }), select('To node', edge.to, graph.nodes.map(function (n) { return n.id; }), function (value) { props.onChange(Object.assign({}, graph, { edges: graph.edges.map(function (x, j) { return j === i ? Object.assign({}, x, { to: value }) : x; }) })); }), field('Outcome', edge.outcome || 'success', function (value) { props.onChange(Object.assign({}, graph, { edges: graph.edges.map(function (x, j) { return j === i ? Object.assign({}, x, { outcome: value }) : x; }) })); }), button('Remove connection', function () { props.onChange(Object.assign({}, graph, { edges: graph.edges.filter(function (_, j) { return j !== i; }) })); })); }),
            button('Add connection', function () { props.onChange(Object.assign({}, graph, { edges: graph.edges.concat([{ from: graph.nodes[0].id, to: graph.nodes[graph.nodes.length - 1].id, outcome: 'success' }]) })); }, { disabled: !graph.nodes.length }));
    }
    function WorkflowCanvas(props) {
        var graph = props.graph, positions = {};
        graph.nodes.forEach(function (node, index) { positions[node.id] = { x: 20 + (index % 2) * 320, y: 20 + Math.floor(index / 2) * 100 }; });
        return h('div', { className: 'wmos-canvas' }, h('svg', { viewBox: '0 0 640 ' + Math.max(140, Math.ceil(graph.nodes.length / 2) * 100 + 40), role: 'group', 'aria-label': __('Workflow connections; use the outline below to edit each node') }, h('title', null, __('Workflow canvas')),
            h('defs', null, h('marker', { id: 'wmos-arrow', viewBox: '0 0 10 10', refX: 10, refY: 5, markerWidth: 6, markerHeight: 6, orient: 'auto-start-reverse' }, h('path', { d: 'M 0 0 L 10 5 L 0 10 z', fill: '#555' }))),
            graph.edges.map(function (edge, index) { var a = positions[edge.from], b = positions[edge.to]; if (!a || !b) return null; var x1 = a.x + 120, y1 = a.y + 48, x2 = b.x + 120, y2 = b.y;
                return h('g', { key: 'edge-' + index }, h('path', { d: 'M ' + x1 + ' ' + y1 + ' C ' + x1 + ' ' + (y1 + 35) + ', ' + x2 + ' ' + (y2 - 35) + ', ' + x2 + ' ' + y2, fill: 'none', stroke: '#555', strokeWidth: 1.5, markerEnd: 'url(#wmos-arrow)' }), h('text', { x: (x1 + x2) / 2 + 4, y: (y1 + y2) / 2, fill: '#333', fontSize: 11 }, edge.outcome || 'success')); }),
            graph.nodes.map(function (node, index) { var p = positions[node.id]; return h('a', { key: 'canvas-node-' + index, href: '#wmos-node-' + index, 'aria-label': __('Edit node') + ' ' + node.id }, h('rect', { x: p.x, y: p.y, width: 240, height: 48, rx: 6, fill: '#fff', stroke: '#674399', strokeWidth: 2 }), h('text', { x: p.x + 12, y: p.y + 20, fill: '#222', fontSize: 13 }, node.id), h('text', { x: p.x + 12, y: p.y + 37, fill: '#555', fontSize: 11 }, __(node.type))); })));
    }
    function BodyEditor(props) {
        var body = props.value, kind = props.kind;
        function set(name, value) { var next = Object.assign({}, body); if (value === '') delete next[name]; else next[name] = value; props.onChange(next); }
        function numeric(name) { return function (value) { set(name, Number(value)); }; }
        if (kind === 'automation') return h(Workflow, { value: body, onChange: props.onChange });
        if (kind === 'segment') return h('div', null, h(RuleEditor, { value: body.rule || { all: [B.rule()] }, onChange: function (value) { set('rule', value); } }), select('Evaluation mode', body.mode || 'materialized', ['materialized', 'dynamic'], function (value) { set('mode', value); }));
        if (['personalization', 'recommendation'].includes(kind)) return h('div', null, select('Surface', body.surface, ['recommendations', 'banner', 'landing', 'email'], function (value) { set('surface', value); }), field('Title', body.title, function (value) { set('title', value); }), h(Area, { label: __('Safe HTML content'), value: body.html || '', onChange: function (value) { set('html', value); } }), select('Recommendation strategy', body.strategy || 'latest', ['latest', 'category', 'bestsellers', 'trending', 'pinned', 'recent_views', 'purchase_history'], function (value) { set('strategy', value); }), h(JsonField, { label: 'Recommendation configuration', value: body.config || {}, onChange: function (value) { set('config', value); }, help: 'Set limit (1–20), product_ids, category_ids, exclude_ids or days (1–90). Private strategies require current personalization consent.' }), body.rule ? h(RuleEditor, { value: body.rule, onChange: function (value) { set('rule', value); } }) : button('Add audience rule', function () { set('rule', B.rule()); }), h(JsonField, { label: 'Public fallback', value: body.fallback || {}, onChange: function (value) { set('fallback', value); }, help: 'Use public content and a public recommendation strategy for shared cached HTML.' }));
        var inputs = [];
        if (kind === 'campaign') {
            inputs.push(select('Channel', body.channel || '', ['email', 'sms', 'push', 'whatsapp', 'telegram', 'social', 'webhook'], function (value) { props.onChange(Object.assign({}, body, { channel: value, content: B.contentDefaults(value) })); }));
            inputs.push(select('Provider connection', body.provider_uuid || '', [{ value: '', label: __('Choose a configured connection') }].concat((props.providers || []).map(function (p) { return { value: p.uuid, label: p.name + ' · ' + p.state }; })), function (value) { set('provider_uuid', value); }));
            inputs.push(field('Purpose', body.purpose || 'marketing', function (value) { set('purpose', value); }));
            inputs.push(select('Audience', body.audience && body.audience.segment_uuid ? 'segment' : 'all', ['all', 'segment'], function (value) { set('audience', value === 'all' ? { all: true } : { segment_uuid: '' }); }));
            if (body.audience && Object.prototype.hasOwnProperty.call(body.audience, 'segment_uuid')) inputs.push(field('Segment UUID', body.audience.segment_uuid, function (value) { set('audience', { segment_uuid: value }); }));
            if (body.channel === 'email') inputs.push(field('Subject', (body.content || {}).subject || '', function (value) { set('content', Object.assign({}, body.content, { subject: value })); }));
            if (body.channel === 'push') inputs.push(field('Push title', (body.content || {}).title || '', function (value) { set('content', Object.assign({}, body.content, { title: value })); }));
            if (body.channel === 'whatsapp') {
                inputs.push(field('Approved template name', (body.content || {}).template || '', function (value) { set('content', Object.assign({}, body.content, { template: value })); }));
                inputs.push(field('Template language', (body.content || {}).language || '', function (value) { set('content', Object.assign({}, body.content, { language: value })); }));
                inputs.push(h(JsonField, { label: 'Approved template components', value: (body.content || {}).components || [], onChange: function (value) { set('content', Object.assign({}, body.content, { components: value })); } }));
            } else inputs.push(h(Area, { label: __('Message text'), rows: 6, value: (body.content || {}).text || '', onChange: function (value) { set('content', Object.assign({}, body.content, { text: value })); } }));
            inputs.push(field('Scheduled UTC instant (optional)', body.scheduled_at || '', function (value) { set('scheduled_at', value); }, { help: __('Use an ISO date with timezone, for example 2026-11-01T09:00:00Z. Publish before scheduling.') }));
        } else if (kind === 'experiment') {
            inputs.push(select('Assignment unit', body.unit || 'profile', ['profile', 'session'], function (value) { set('unit', value); }));
            inputs.push(h(JsonField, { label: 'Variants', value: body.variants, onChange: function (value) { set('variants', value); }, help: 'Use unique keys and positive integer weights totaling 10000. Results are exploratory unless an approved protocol is configured.' }));
        } else {
            inputs.push(field('Type', body.type || '', function (value) { set('type', value); }));
            if (['promotion', 'program'].includes(kind)) {
                inputs.push(field(kind === 'promotion' ? 'Discount amount' : 'Points per order', kind === 'promotion' ? body.amount : body.points_per_order, numeric(kind === 'promotion' ? 'amount' : 'points_per_order'), { type: 'number', min: 0 }));
                if (kind === 'program') { inputs.push(field('Refund hold (days)', body.hold_days, numeric('hold_days'), { type: 'number', min: 0 })); inputs.push(field('Financial retention (days)', body.financial_retention_days, numeric('financial_retention_days'), { type: 'number', min: 1, help: __('Set the approved retention period before publishing this program.') })); }
            }
            if (['offline', 'event'].includes(kind)) inputs.push(field('Location descriptor', body.location, function (value) { set('location', value); }));
            if (['offline', 'event', 'influencer', 'partner'].includes(kind)) {
                inputs.push(field('Cost in minor units', body.cost, numeric('cost'), { type: 'number', min: 0 }));
                inputs.push(field('Currency', body.currency || 'USD', function (value) { set('currency', value.toUpperCase()); }));
                inputs.push(field('Campaign UUID (optional)', body.campaign_uuid || '', function (value) { set('campaign_uuid', value); }));
            }
            if (['asset', 'content'].includes(kind)) {
                inputs.push(field('WordPress attachment ID (optional)', body.attachment_id || '', numeric('attachment_id'), { type: 'number', min: 0, help: __('Use an attachment from the WordPress Media Library; the plugin does not import arbitrary remote files.') }));
                inputs.push(button('Choose from Media Library', function () { var frame = wp.media({ title: __('Choose an existing marketing asset'), button: { text: __('Use asset') }, multiple: false }); frame.on('select', function () { var attachment = frame.state().get('selection').first().toJSON(); set('attachment_id', attachment.id); }); frame.open(); }));
                inputs.push(field('Subject', body.subject, function (value) { set('subject', value); }));
                inputs.push(h(Area, { label: __('Content or brief'), value: body.text || '', rows: 6, onChange: function (value) { set('text', value); } }));
                if (kind === 'content') {
                    inputs.push(field('SEO title', body.seo_title || '', function (value) { set('seo_title', value); }));
                    inputs.push(h(Area, { label: __('SEO description'), value: body.seo_description || '', onChange: function (value) { set('seo_description', value); } }));
                }
            }
        }
        return h('div', null, inputs.map(function (input, i) { return h('div', { key: i }, input); }), h('details', null, h('summary', null, __('All definition fields')), h(JsonField, { value: body, onChange: props.onChange, rows: 14 })));
    }
    function List(props) {
        return h('div', { className: 'wmos-table-wrap' }, h('table', { className: 'wmos-table' }, h('caption', null, __(props.caption)), h('thead', null, h('tr', null, props.columns.map(function (c) { return h('th', { scope: 'col', key: c.key }, __(c.label)); }))), h('tbody', null, props.items.map(function (row) { return h('tr', { key: row.uuid || row.key || json(row) }, props.columns.map(function (c) { return h('td', { key: c.key }, c.render ? c.render(row) : B.displayValue(c.key, row, boot.locale, boot.timeZone)); })); }))));
    }
    function useCollection(path) {
        var state = useState({ items: [], next_cursor: null }), data = state[0], setData = state[1];
        var pending = useState(false), loading = pending[0], setLoading = pending[1];
        var errors = useState(''), error = errors[0], setError = errors[1];
        var cursors = useState([]), history = cursors[0], setHistory = cursors[1];
        var refreshes = useState(0), refresh = refreshes[0], setRefresh = refreshes[1];
        useEffect(function () { setHistory([]); }, [path]);
        useEffect(function () {
            var live = true, controller = new AbortController(); setLoading(true); setError('');
            var cursor = history.length ? history[history.length - 1] : '';
            request(path + (path.includes('?') ? '&' : '?') + 'per_page=25' + (cursor ? '&cursor=' + encodeURIComponent(cursor) : ''), 'GET', undefined, undefined, controller.signal).then(function (value) { if (live) setData(value); }).catch(function (e) { if (live) setError(e.message); }).finally(function () { if (live) setLoading(false); });
            return function () { live = false; controller.abort(); };
        }, [path, history.join('|'), refresh]);
        return { data: data, loading: loading, error: error, refresh: function () { setRefresh(function (x) { return x + 1; }); }, next: function () { setHistory(history.concat([data.next_cursor])); }, back: function () { setHistory(history.slice(0, -1)); }, hasBack: history.length > 0 };
    }
    function Pagination(props) { return h('div', { className: 'wmos-actions' }, props.list.hasBack ? button('Previous page', props.list.back) : null, props.list.data.next_cursor ? button('Next page', props.list.next) : null); }
    function Definitions(props) {
        var resource = props.resource, meta = resources[resource], list = useCollection(resource);
        var editing = useState(null), item = editing[0], setItem = editing[1];
        var namePair = useState(''), name = namePair[0], setName = namePair[1];
        var bodyPair = useState(B.defaults(meta[1])), body = bodyPair[0], setBody = bodyPair[1];
        var busyPair = useState(false), busy = busyPair[0], setBusy = busyPair[1];
        var providers = useCollection('channel-providers');
        var profilePair = useState(''), profile = profilePair[0], setProfile = profilePair[1];
        function edit(row) { setItem(row); setName(row.name); setBody(row.body); }
        function create() { setItem({ uuid: null }); setName(''); setBody(B.defaults(meta[1])); }
        async function act(path, data, revision) {
            setBusy(true);
            try { var result = await request(path, 'POST', data || {}, revision); list.refresh(); if (item && item.uuid) edit(await request(resource + '/' + item.uuid)); props.notify(__('Operation completed.'), 'success'); return result; }
            catch (e) { props.notify(e.message, 'error'); }
            finally { setBusy(false); }
        }
        async function save(e) {
            e.preventDefault(); setBusy(true);
            try { var result = await request(item.uuid ? resource + '/' + item.uuid : resource, item.uuid ? 'PUT' : 'POST', item.uuid ? { body: body } : { name: name, body: body }, item.row_version); edit(await request(resource + '/' + result.uuid)); list.refresh(); props.notify(__('Draft saved. Publish a version before execution.'), 'success'); }
            catch (error) { props.notify(error instanceof SyntaxError ? __('Enter valid JSON.') : error.message, 'error'); }
            finally { setBusy(false); }
        }
        var publishCapability = resource === 'automations' ? 'publish_automations' : ['campaigns', 'experiments', 'assets', 'offline', 'marketing-events', 'content', 'personalization', 'recommendations'].includes(resource) ? 'publish_campaigns' : meta[2];
        return h('section', null, h('div', { className: 'wmos-toolbar' }, h('h2', null, __(meta[0])), button('Create new', create, { variant: 'primary' })),
            list.error ? h(C.Notice, { status: 'error', isDismissible: false }, list.error) : null,
            list.loading ? h(C.Spinner) : list.data.items.length ? h(List, { caption: meta[0], items: list.data.items, columns: [{ key: 'name', label: 'Name', render: function (row) { return h(Button, { variant: 'link', onClick: function () { edit(row); } }, row.name); } }, { key: 'state', label: 'State', render: function (row) { return h('span', { className: 'wmos-badge' }, row.state); } }, { key: 'published', label: 'Published', render: function (row) { return row.published ? __('Yes') : __('No'); } }, { key: 'updated_at', label: 'Updated' }] }) : h(Empty, { title: 'Create your first definition' }),
            h(Pagination, { list: list }), item ? h('form', { className: 'wmos-card', onSubmit: save, 'aria-label': __('Definition editor') }, h('div', { className: 'wmos-toolbar' }, h('h3', null, item.uuid ? __('Edit draft') : __('New definition')), button('Close editor', function () { setItem(null); })),
                field('Name', name, setName, { required: true, disabled: !!item.uuid }), item.uuid ? h('p', { className: 'wmos-uuid' }, item.uuid + ' · ' + __('Revision') + ' ' + item.row_version) : null,
                h(BodyEditor, { kind: meta[1], value: body, onChange: setBody, providers: providers.data.items }), h('div', { className: 'wmos-actions' }, h(Button, { type: 'submit', variant: 'primary', isBusy: busy, disabled: busy }, __('Save draft')),
                    item.uuid && boot.capabilities[publishCapability] ? button('Publish version', function () { act(resource + '/' + item.uuid + '/publish', {}, item.row_version); }, { disabled: busy || JSON.stringify(body) !== JSON.stringify(item.body), title: __('Save draft changes before publishing.') }) : null,
                    item.uuid && item.published && boot.capabilities[publishCapability] ? button(resource === 'automations' ? 'Enable' : 'Start', function () { act(resource + '/' + item.uuid + '/transition', { state: resource === 'automations' ? 'enabled' : ['campaigns', 'experiments'].includes(resource) ? 'running' : 'active' }, item.row_version); }, { disabled: busy || ['enabled', 'active', 'running'].includes(item.state) }) : null,
                    item.uuid && ['running', 'enabled', 'active'].includes(item.state) && boot.capabilities[publishCapability] ? button('Pause', function () { act(resource + '/' + item.uuid + '/transition', { state: 'paused' }, item.row_version); }, { disabled: busy }) : null,
                    item.uuid && resource === 'campaigns' && item.published && body.scheduled_at ? button('Schedule', function () { act(resource + '/' + item.uuid + '/transition', { state: 'scheduled' }, item.row_version); }) : null,
                    item.uuid && resource === 'segments' ? button('Rebuild memberships', function () { act(resource + '/' + item.uuid + '/rebuild'); }) : null,
                    resource === 'segments' ? button('Preview matching contacts', async function () { try { props.result(await request('segments/preview', 'POST', { rule: body.rule, per_page: 25 })); } catch (e) { props.notify(e.message, 'error'); } }) : null,
                    item.uuid && resource === 'experiments' ? button('View results', async function () { try { props.result(await request(resource + '/' + item.uuid + '/results')); } catch (e) { props.notify(e.message, 'error'); } }) : null,
                    item.uuid && ['personalization', 'recommendations'].includes(resource) ? button('Preview public fallback', async function () { try { props.result(await request('personalization/' + item.uuid + '/preview', 'POST', { profile_uuid: null })); } catch (e) { props.notify(e.message, 'error'); } }) : null,
                    resource === 'recommendations' ? button('Refresh product catalog', async function () { try { props.result(await request('recommendations/refresh', 'POST', {})); } catch (e) { props.notify(e.message, 'error'); } }) : null,
                    item.uuid && boot.capabilities.manage_settings ? button('Export definition', async function () { try { var exported = await request('definitions/export', 'POST', { uuid: item.uuid }); var blob = new Blob([json(exported)], { type: 'application/json' }); var url = URL.createObjectURL(blob), a = document.createElement('a'); a.href = url; a.download = meta[1] + '-' + item.uuid + '.json'; a.click(); setTimeout(function () { URL.revokeObjectURL(url); }, 1000); } catch (e) { props.notify(e.message, 'error'); } }) : null),
                item.uuid && resource === 'automations' && boot.capabilities.run_automations ? h('div', null, h('h3', null, __('Manual enrollment')), field('Contact UUID', profile, setProfile), button('Enroll contact', function () { act(resource + '/' + item.uuid + '/enter', { profile_uuid: profile, event_uuid: null }); }, { disabled: !profile || busy })) : null,
                item.uuid && resource === 'promotions' ? h('div', null, field('Contact UUID for coupon', profile, setProfile), button('Issue native coupon', async function () { var result = await act(resource + '/' + item.uuid + '/issue', { profile_uuid: profile }); if (result) props.result(result); }, { disabled: !profile || busy })) : null) : null);
    }
    function Contacts(props) {
        var list = useCollection('contacts'), pair = useState(null), selected = pair[0], setSelected = pair[1];
        var emailPair = useState(''), email = emailPair[0], setEmail = emailPair[1];
        var tagsPair = useState(''), tags = tagsPair[0], setTags = tagsPair[1];
        var attrsPair = useState({}), attrs = attrsPair[0], setAttrs = attrsPair[1];
        var purposePair = useState('marketing'), purpose = purposePair[0], setPurpose = purposePair[1];
        var channelPair = useState('email'), channel = channelPair[0], setChannel = channelPair[1];
        var policyPair = useState(''), policy = policyPair[0], setPolicy = policyPair[1];
        var evidencePair = useState(''), evidence = evidencePair[0], setEvidence = evidencePair[1];
        var identityPair = useState('email'), identityKind = identityPair[0], setIdentityKind = identityPair[1];
        var valuePair = useState(''), identityValue = valuePair[0], setIdentityValue = valuePair[1];
        var verifyPair = useState(false), verified = verifyPair[0], setVerified = verifyPair[1];
        var targetPair = useState(''), target = targetPair[0], setTarget = targetPair[1];
        var mergePair = useState(''), mergeId = mergePair[0], setMergeId = mergePair[1];
        var reasonPair = useState(''), reason = reasonPair[0], setReason = reasonPair[1];
        async function action(path, data, revision) { try { var result = await request(path, 'POST', data || {}, revision); list.refresh(); props.result(result); props.notify(__('Operation completed.'), 'success'); return result; } catch (e) { props.notify(e.message, 'error'); } }
        function selectContact(row) { setSelected(row); setTags(row.tags.join(', ')); setAttrs(row.attributes); }
        return h('section', null, h('h2', null, __('Customers and consent')), h('p', { className: 'wmos-muted' }, __('Marketing profiles supplement WooCommerce customers. A purchase or entered email never grants marketing permission.')),
            boot.capabilities.manage_contacts ? h('form', { className: 'wmos-card', onSubmit: async function (e) { e.preventDefault(); var created = await action('contacts', { email: email }); if (created) { selectContact(await request('contacts/' + created.uuid)); setEmail(''); } } }, field('Email for new profile', email, setEmail, { type: 'email', required: true }), h(Button, { type: 'submit', variant: 'primary' }, __('Create contact'))) : null,
            list.error ? h(C.Notice, { status: 'error', isDismissible: false }, list.error) : null,
            h(List, { caption: 'Marketing profiles', items: list.data.items, columns: [{ key: 'email', label: 'Email', render: function (row) { return h(Button, { variant: 'link', onClick: function () { selectContact(row); } }, row.email || row.uuid); } }, { key: 'uuid', label: 'Public identifier' }, { key: 'state', label: 'State' }] }), h(Pagination, { list: list }),
            selected ? h('div', { className: 'wmos-card' }, h('h3', null, selected.email), h('p', { className: 'wmos-uuid' }, selected.uuid), field('Tags (comma separated)', tags, setTags), h(JsonField, { label: 'Profile attributes', value: attrs, onChange: setAttrs }),
                boot.capabilities.manage_contacts ? button('Save profile', async function () { try { await request('contacts/' + selected.uuid, 'PUT', { tags: tags.split(',').map(function (x) { return x.trim(); }).filter(Boolean), attributes: attrs }, selected.row_version); selectContact(await request('contacts/' + selected.uuid)); list.refresh(); props.notify(__('Profile saved.'), 'success'); } catch (e) { props.notify(e.message, 'error'); } }) : null,
                button('View consent history', async function () { try { props.result(await request('contacts/' + selected.uuid + '/consents')); } catch (e) { props.notify(e.message, 'error'); } }),
                boot.capabilities.manage_consent ? h('fieldset', { className: 'wmos-node' }, h('legend', null, __('Record an evidenced choice')), field('Purpose', purpose, setPurpose), select('Channel', channel, ['email', 'sms', 'whatsapp', 'telegram', 'push', 'analytics', 'ads', 'social', 'webhook', 'personalization', 'profiling'], setChannel), field('Policy version', policy, setPolicy), h(Area, { label: __('Evidence or request reference'), value: evidence, onChange: setEvidence }), h('div', { className: 'wmos-actions' }, button('Record grant', function () { action('contacts/' + selected.uuid + '/consents/grant', { purpose: purpose, channel: channel, policy: policy, evidence: { reference: evidence } }); }, { disabled: !policy || !evidence }), button('Withdraw', function () { action('contacts/' + selected.uuid + '/consents/withdraw', { purpose: purpose, channel: channel }); }, { isDestructive: true }))) : null,
                boot.capabilities.manage_contacts ? h('fieldset', { className: 'wmos-node' }, h('legend', null, __('Verified delivery identity')), select('Identity type', identityKind, ['email', 'phone', 'telegram', 'push'], setIdentityKind), field('Identity value', identityValue, setIdentityValue), field('Verification evidence reference', evidence, setEvidence), h(Check, { label: __('I verified this destination using the referenced evidence'), checked: verified, onChange: setVerified }), button('Record identity', function () { action('contacts/' + selected.uuid + '/identities', { kind: identityKind, value: identityValue, verified: verified, evidence_reference: evidence }); }, { disabled: !identityValue || !evidence })) : null,
                boot.capabilities.manage_consent ? button('Send requested email confirmation', function () { action('contacts/' + selected.uuid + '/consents/request-confirmation', { purpose: purpose, channel: 'email', policy: policy, evidence: { reference: evidence } }); }, { disabled: !policy || !evidence }) : null,
                boot.capabilities.manage_contacts ? h('fieldset', { className: 'wmos-node' }, h('legend', null, __('Resolve duplicate identities')), h('p', null, __('A merge requires verified ownership evidence, withdraws existing choices and preserves financial history on its original contact. Review both subjects first.')), field('Target contact UUID', target, setTarget), field('Merge or reversal reason', reason, setReason), field('Ownership verification reference', evidence, setEvidence), button('Merge into reviewed contact', async function () { if (!window.confirm(__('Merge these verified subjects and withdraw existing permission?'))) return; try { var targetContact = await request('contacts/' + target); var merged = await action('contacts/' + selected.uuid + '/merge', { target_uuid: target, target_revision: Number(targetContact.row_version), reason: reason, proof: { verification_reference: evidence } }, selected.row_version); if (merged) { setMergeId(merged.uuid); selectContact(await request('contacts/' + target)); } } catch (e) { props.notify(e.message, 'error'); } }, { disabled: !target || !reason || !evidence, isDestructive: true }), field('Merge operation UUID for reversal', mergeId, setMergeId), button('Reverse reviewed merge', function () { if (window.confirm(__('Reverse the merge while keeping consent withdrawn?'))) action('identity-merges/' + mergeId + '/undo', { reason: reason }); }, { disabled: !mergeId || !reason })) : null,
                boot.capabilities.manage_privacy ? h('div', { className: 'wmos-actions' }, button('Export personal data', function () { action('contacts/' + selected.uuid + '/privacy/export'); }), button('Erase personal data', function () { if (window.confirm(__('Erase optional marketing data for this verified subject request? Commerce records remain under WooCommerce.'))) action('contacts/' + selected.uuid + '/privacy/erase'); }, { isDestructive: true })) : null) : null);
    }
    function Providers(props) {
        var list = useCollection('providers'), selectedPair = useState(null), selected = selectedPair[0], setSelected = selectedPair[1];
        var namePair = useState(''), name = namePair[0], setName = namePair[1];
        var typePair = useState('resend'), type = typePair[0], setType = typePair[1];
        var configPair = useState({ enabled: false, policy_acknowledged: false }), config = configPair[0], setConfig = configPair[1];
        var secretPair = useState(''), secret = secretPair[0], setSecret = secretPair[1];
        function edit(row) { setSelected(row); setName(row ? row.name : ''); setType(row ? row.type : 'resend'); setConfig(row ? row.configuration : { enabled: false, policy_acknowledged: false }); setSecret(''); }
        return h('section', null, h('div', { className: 'wmos-toolbar' }, h('h2', null, __('Channels and integrations')), button('New connection', function () { edit(null); })), h('p', { className: 'wmos-muted' }, __('Configure a server encryption key before entering credentials. Secrets are write-only. Provider account policy and template restrictions still apply.')),
            list.error ? h(C.Notice, { status: 'error', isDismissible: false }, list.error) : null,
            h(List, { caption: 'Provider connections', items: list.data.items, columns: [{ key: 'name', label: 'Connection', render: function (row) { return h(Button, { variant: 'link', onClick: function () { edit(row); } }, row.name); } }, { key: 'type', label: 'Adapter' }, { key: 'state', label: 'State' }, { key: 'configured', label: 'Credential', render: function (row) { return row.configured ? __('Configured') : __('Missing'); } }] }),
            h('form', { className: 'wmos-card', onSubmit: async function (e) { e.preventDefault(); try { await request(selected ? 'providers/' + selected.uuid : 'providers', selected ? 'PUT' : 'POST', { name: name, type: type, configuration: config, secret: secret || null }, selected && selected.row_version); setSecret(''); list.refresh(); props.notify(__('Connection saved; credential remains server-side.'), 'success'); } catch (e) { setSecret(''); props.notify(e.message, 'error'); } } },
                h('h3', null, selected ? __('Edit connection') : __('New connection')), field('Connection name', name, setName, { required: true }), select('Adapter', type, ['resend', 'twilio', 'meta', 'telegram', 'onesignal', 'relay'], setType), field(selected ? 'Replace credential (leave blank to retain)' : 'Credential', secret, setSecret, { type: 'password', autoComplete: 'new-password' }),
                h(Check, { label: __('Enable this connection'), checked: !!config.enabled, onChange: function (value) { setConfig(Object.assign({}, config, { enabled: value })); } }), h(Check, { label: __('I reviewed the provider policy and permitted data transfer'), checked: !!config.policy_acknowledged, onChange: function (value) { setConfig(Object.assign({}, config, { policy_acknowledged: value })); } }),
                h(JsonField, { label: 'Safe provider configuration', value: config, onChange: setConfig, help: 'Resend: from. Twilio: account_sid and from. Meta: phone_number_id. OneSignal: app_id. Relay: approved endpoint and channel. Put token/webhook_secret only in credential.' }), h(Button, { type: 'submit', variant: 'primary' }, __('Save connection'))));
    }
    function Messages(props) {
        var list = useCollection('messages'), providers = useCollection('channel-providers');
        var profilePair = useState(''), profile = profilePair[0], setProfile = profilePair[1];
        var providerPair = useState(''), provider = providerPair[0], setProvider = providerPair[1];
        var channelPair = useState('email'), channel = channelPair[0], setChannel = channelPair[1];
        var contentPair = useState({ subject: '', text: '', html: '' }), content = contentPair[0], setContent = contentPair[1];
        return h('section', null, h('h2', null, __('Message delivery')), h('p', { className: 'wmos-muted' }, __('A manual message uses the same verified destination, consent, suppression and retry policy as campaign delivery.')),
            h('form', { className: 'wmos-card', onSubmit: async function (e) { e.preventDefault(); try { props.result(await request('messages', 'POST', { profile_uuid: profile, provider_uuid: provider, channel: channel, purpose: 'marketing', content: content })); list.refresh(); props.notify(__('Message intent queued.'), 'success'); } catch (error) { props.notify(error instanceof SyntaxError ? __('Enter valid JSON.') : error.message, 'error'); } } }, field('Contact UUID', profile, setProfile, { required: true }), select('Channel', channel, ['email', 'sms', 'whatsapp', 'telegram', 'push', 'social', 'webhook'], function (value) { setChannel(value); setContent(B.contentDefaults(value)); }), select('Provider connection', provider, [{ value: '', label: __('Choose a connection') }].concat(providers.data.items.map(function (p) { return { value: p.uuid, label: p.name }; })), setProvider), h(JsonField, { label: 'Message content', value: content, onChange: setContent }), h(Button, { type: 'submit', variant: 'primary', disabled: !provider || !profile }, __('Queue marketing message'))),
            list.error ? h(C.Notice, { status: 'error', isDismissible: false }, list.error) : null,
            h(List, { caption: 'Delivery intents', items: list.data.items, columns: [{ key: 'uuid', label: 'Message' }, { key: 'channel', label: 'Channel' }, { key: 'state', label: 'State' }, { key: 'scheduled_at', label: 'Scheduled' }, { key: 'last_error', label: 'Outcome' }] }), h(Pagination, { list: list }));
    }
    function Programs(props) {
        var programPair = useState(''), program = programPair[0], setProgram = programPair[1];
        var profilePair = useState(''), profile = profilePair[0], setProfile = profilePair[1];
        var pointsPair = useState(10), points = pointsPair[0], setPoints = pointsPair[1];
        var holdPair = useState(''), hold = holdPair[0], setHold = holdPair[1];
        var codePair = useState(''), code = codePair[0], setCode = codePair[1];
        var orderPair = useState(''), order = orderPair[0], setOrder = orderPair[1];
        var basePair = useState(0), base = basePair[0], setBase = basePair[1];
        var currencyPair = useState('USD'), currency = currencyPair[0], setCurrency = currencyPair[1];
        var payoutPair = useState(''), payout = payoutPair[0], setPayout = payoutPair[1];
        var referencePair = useState(''), reference = referencePair[0], setReference = referencePair[1];
        var reasonPair = useState(''), reason = reasonPair[0], setReason = reasonPair[1];
        var ledger = useCollection('ledger'), referrals = useCollection('referrals'), commissions = useCollection('commissions');
        async function act(path, data) { try { var result = await request(path, 'POST', data || {}); props.result(result); ledger.refresh(); referrals.refresh(); commissions.refresh(); } catch (e) { props.notify(e.message, 'error'); } }
        return h('section', null, h(Definitions, Object.assign({}, props, { resource: 'programs' })), h('div', { className: 'wmos-card' }, h('h3', null, __('Program operations')), field('Published program UUID', program, setProgram), field('Contact UUID', profile, setProfile), field('Points', points, function (value) { setPoints(Number(value)); }, { type: 'number', min: 1 }),
            h('div', { className: 'wmos-actions' }, button('Inspect balance', async function () { try { props.result(await request('programs/' + program + '/balance?profile_uuid=' + encodeURIComponent(profile))); } catch (e) { props.notify(e.message, 'error'); } }, { disabled: !profile || !program }), boot.capabilities.adjust_rewards ? button('Award points', function () { act('programs/' + program + '/earn', { profile_uuid: profile, points: points }); }, { disabled: !profile || !program }) : null, button('Reserve points', async function () { try { var result = await request('programs/' + program + '/reserve', 'POST', { profile_uuid: profile, points: points }); setHold(result.uuid || ''); props.result(result); ledger.refresh(); } catch (e) { props.notify(e.message, 'error'); } }, { disabled: !profile || !program }), button('Create referral', function () { act('programs/' + program + '/referrals', { profile_uuid: profile }); }, { disabled: !profile || !program })), field('Reservation UUID', hold, setHold), h('div', { className: 'wmos-actions' }, button('Redeem reservation', function () { act('rewards/' + hold + '/redeem'); }, { disabled: !hold }), button('Release reservation', function () { act('rewards/' + hold + '/release'); }, { disabled: !hold }))),
            h('div', { className: 'wmos-card' }, h('h3', null, __('Referral and financial review')), field('Referral code to claim', code, setCode), button('Claim referral for contact', function () { act('referrals/claim', { code: code, profile_uuid: profile }); }, { disabled: !code || !profile }), field('Canonical paid order ID', order, setOrder, { type: 'number', min: 1 }), field('Commission basis in minor units', base, function (value) { setBase(Number(value)); }, { type: 'number', min: 1 }), field('Order currency', currency, setCurrency), button('Record reviewed affiliate commission', function () { act('programs/' + program + '/commissions', { affiliate_uuid: profile, order_id: Number(order), base_minor: base, currency: currency }); }, { disabled: !program || !profile || !order || !base }), boot.capabilities.adjust_rewards ? h('div', null, field('Adjustment reason', reason, setReason), button('Record signed points adjustment', function () { act('programs/' + program + '/adjust', { profile_uuid: profile, points: points, reason: reason }); }, { disabled: !program || !profile || !reason })) : null, boot.capabilities.manage_payouts ? h('div', null, h('p', null, __('Confirm only a payment already settled externally. Recording this reference does not transfer money.')), field('Approved commission UUIDs (comma separated)', payout, setPayout), field('Confirmed external payment reference', reference, setReference), button('Record confirmed payout', function () { if (window.confirm(__('Record these commissions as paid using this settled external payment reference?'))) act('payouts/confirm', { commission_uuids: payout.split(',').map(function (value) { return value.trim(); }).filter(Boolean), external_reference: reference }); }, { disabled: !payout || !reference })) : null),
            h(List, { caption: 'Immutable points ledger', items: ledger.data.items, columns: [{ key: 'uuid', label: 'Entry' }, { key: 'kind', label: 'Kind' }, { key: 'points', label: 'Points' }, { key: 'reason', label: 'Reason' }] }), h(Pagination, { list: ledger }),
            h(List, { caption: 'Referrals', items: referrals.data.items, columns: [{ key: 'code', label: 'Referral code' }, { key: 'state', label: 'State' }, { key: 'order_id', label: 'Order' }] }), h(Pagination, { list: referrals }),
            h(List, { caption: 'Affiliate commissions', items: commissions.data.items, columns: [{ key: 'uuid', label: 'Commission' }, { key: 'state', label: 'State' }, { key: 'amount_minor', label: 'Minor currency units' }, { key: 'currency', label: 'Currency' }, { key: 'action', label: 'Review', render: function (row) { return boot.capabilities.manage_payouts && row.state === 'held' ? button('Approve', function () { act('commissions/' + row.uuid + '/approve'); }) : '—'; } }] }), h(Pagination, { list: commissions }));
    }
    function Links(props) {
        var list = useCollection('links'), destinationPair = useState(''), destination = destinationPair[0], setDestination = destinationPair[1];
        var campaignPair = useState(''), campaign = campaignPair[0], setCampaign = campaignPair[1];
        var dimsPair = useState({}), dimensions = dimsPair[0], setDimensions = dimsPair[1];
        var createdPair = useState(null), created = createdPair[0], setCreated = createdPair[1];
        return h('section', null, h('h2', null, __('Tracking links and QR placements')), h('p', { className: 'wmos-muted' }, __('Redirects work without optional shopper tracking. Destinations are restricted by server policy. Link each physical placement to its own identifier.')),
            h('form', { className: 'wmos-card', onSubmit: async function (e) { e.preventDefault(); try { var result = await request('links', 'POST', { destination: destination, definition_uuid: campaign || null, placement_uuid: null, dimensions: dimensions }); setCreated(result); list.refresh(); props.result(result); } catch (error) { props.notify(error instanceof SyntaxError ? __('Enter valid JSON.') : error.message, 'error'); } } }, field('Store destination URL', destination, setDestination, { type: 'url', required: true }), field('Campaign UUID (optional)', campaign, setCampaign), h(JsonField, { label: 'Campaign dimensions', value: dimensions, onChange: setDimensions, help: 'Use approved UTM/campaign/placement dimensions. Never put email or other personal data in a link.' }), h(Button, { type: 'submit', variant: 'primary' }, __('Create tracking link'))),
            created ? h('div', { className: 'wmos-card wmos-created-link' }, h('h3', null, __('Created tracking placement')), h('a', { href: created.url }, created.url), h(QR, { url: created.url, uuid: created.uuid })) : null,
            list.error ? h(C.Notice, { status: 'error', isDismissible: false }, list.error) : null,
            h(List, { caption: 'Tracked destinations', items: list.data.items, columns: [{ key: 'slug', label: 'Link', render: function (row) { return h('a', { href: row.url, target: '_blank', rel: 'noopener noreferrer' }, row.url || row.slug); } }, { key: 'destination', label: 'Destination' }, { key: 'state', label: 'State' }, { key: 'qr', label: 'QR placement', render: function (row) { return h(QR, { url: row.url, uuid: row.uuid }); } }] }), h(Pagination, { list: list }));
    }
    function QR(props) {
        var pair = useState(false), opened = pair[0], setOpened = pair[1];
        if (!props.url || !window.wmosQRCode) return null;
        var qr; try { window.wmosQRCode.stringToBytes = window.wmosQRCode.stringToBytesFuncs['UTF-8']; qr = window.wmosQRCode(0, 'M'); qr.addData(props.url); qr.make(); } catch (_) { return h('p', null, __('This link is too long for a QR code.')); }
        function download() { var blob = new Blob([qr.createSvgTag({ cellSize: 6, margin: 24, scalable: true })], { type: 'image/svg+xml' }); var url = URL.createObjectURL(blob), a = document.createElement('a'); a.href = url; a.download = 'placement-' + props.uuid + '.svg'; a.click(); setTimeout(function () { URL.revokeObjectURL(url); }, 1000); }
        return h('div', null, button(opened ? 'Hide QR' : 'Show QR', function () { setOpened(!opened); }, { 'aria-expanded': opened }), opened ? h('div', { className: 'wmos-qr' }, h('img', { src: qr.createDataURL(4, 16), width: (qr.getModuleCount() + 8) * 4, height: (qr.getModuleCount() + 8) * 4, alt: __('QR code for tracking link') + ': ' + props.url }), button('Download QR SVG', download), h('p', null, __('Test the printed code at the intended size before placement.'))) : null);
    }
    function Report(props) {
        var reportPair = useState(null), report = reportPair[0], setReport = reportPair[1];
        var datePair = useState(new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10)), from = datePair[0], setFrom = datePair[1];
        var endPair = useState(new Date().toISOString().slice(0, 10)), to = endPair[0], setTo = endPair[1];
        var modelPair = useState('last_touch'), model = modelPair[0], setModel = modelPair[1];
        async function load() { try { setReport(await request('reports?from=' + from + '&to=' + to + '&model=' + model)); } catch (e) { props.notify(e.message, 'error'); } }
        useEffect(function () { load(); }, []);
        return h('section', null, h('h2', null, __('Analytics and attribution')), h('form', { className: 'wmos-card', onSubmit: function (e) { e.preventDefault(); load(); } }, h('div', { className: 'wmos-split' }, field('From', from, setFrom, { type: 'date' }), field('To', to, setTo, { type: 'date' })), select('Attribution model', model, ['first_touch', 'last_touch', 'last_non_direct', 'linear', 'time_decay', 'position_based', 'custom'], setModel), h(Button, { type: 'submit', variant: 'primary' }, __('Refresh report'))),
            h('p', { className: 'wmos-muted' }, __('Revenue excludes tax and shipping under the default merchandise basis. Currencies remain separate. Attribution is an allocation, not proof of causal lift. Custom-model changes apply to subsequent reconciliation; historical allocations remain pinned until explicitly rebuilt.')),
            report ? h('div', { className: 'wmos-card' }, h('h3', null, __('Measured results and basis')), h(List, { caption: 'Revenue and cost by currency', items: (report.currencies || []).map(function (row) { return Object.assign({ uuid: row.currency + '-' + row.exponent }, row); }), columns: [{ key: 'currency', label: 'Currency' }, { key: 'exponent', label: 'Minor unit exponent' }, { key: 'orders', label: 'Paid orders' }, { key: 'net_revenue_minor', label: 'Net revenue in minor units' }, { key: 'aov_minor', label: 'Average order in minor units' }, { key: 'cost_minor', label: 'Recorded cost in minor units' }, { key: 'roas', label: 'Revenue / cost', render: function (row) { return row.roas == null ? __('No cost basis') : Number(row.roas).toFixed(2); } }] }), h('details', null, h('summary', null, __('Full accessible report and definition')), h('pre', { tabIndex: 0, 'aria-label': __('Accessible report data') }, json(report)))) : h(C.Spinner), boot.capabilities.manage_campaigns ? h(CostEntry, props) : null);
    }
    function CostEntry(props) {
        var definitionPair = useState(''), definition = definitionPair[0], setDefinition = definitionPair[1];
        var minorPair = useState(0), minor = minorPair[0], setMinor = minorPair[1];
        var currencyPair = useState('USD'), currency = currencyPair[0], setCurrency = currencyPair[1];
        var exponentPair = useState(2), exponent = exponentPair[0], setExponent = exponentPair[1];
        return h('form', { className: 'wmos-card', onSubmit: async function (e) { e.preventDefault(); try { props.result(await request('costs', 'POST', { definition_uuid: definition, minor: minor, currency: currency, exponent: exponent, effective_at: null })); props.notify(__('Recorded actual cost. Refresh the report to include this entry.'), 'success'); } catch (error) { props.notify(error instanceof SyntaxError ? __('Enter valid JSON.') : error.message, 'error'); } } }, h('h3', null, __('Record an actual campaign or placement cost')), field('Definition UUID', definition, setDefinition, { required: true }), field('Actual cost in minor units', minor, function (value) { setMinor(Number(value)); }, { type: 'number', min: 0, max: 1000000000000, required: true }), field('Cost currency', currency, function (value) { setCurrency(value.toUpperCase()); }, { required: true }), field('Minor unit exponent', exponent, function (value) { setExponent(Number(value)); }, { type: 'number', min: 0, max: 4, required: true }), h(Button, { type: 'submit', variant: 'secondary' }, __('Record cost')));
    }
    function Health(props) {
        var pair = useState(null), health = pair[0], setHealth = pair[1], jobs = useCollection('jobs'), audit = useCollection('audit');
        useEffect(function () { request('health').then(setHealth).catch(function (e) { props.notify(e.message, 'error'); }); }, []);
        return h('section', null, h('h2', null, __('System health and execution')), health ? h('div', { className: 'wmos-card' }, h('pre', { tabIndex: 0 }, json(health))) : h(C.Spinner),
            h(List, { caption: 'Durable jobs', items: jobs.data.items, columns: [{ key: 'uuid', label: 'Job' }, { key: 'kind', label: 'Kind' }, { key: 'state', label: 'State' }, { key: 'attempts', label: 'Attempts' }, { key: 'last_error', label: 'Safe error' }, { key: 'action', label: 'Action', render: function (row) { return row.state === 'failed' && boot.capabilities.operate_queue ? button('Retry', async function () { try { await request('jobs/' + row.uuid + '/retry', 'POST', {}); jobs.refresh(); } catch (e) { props.notify(e.message, 'error'); } }) : '—'; } }] }), h(Pagination, { list: jobs }),
            h(List, { caption: 'Privileged action audit', items: audit.data.items, columns: [{ key: 'action', label: 'Action' }, { key: 'object_uuid', label: 'Object' }, { key: 'created_at', label: 'Time' }] }), h(Pagination, { list: audit }));
    }
    function Settings(props) {
        var pair = useState(null), settings = pair[0], setSettings = pair[1];
        var importPair = useState('{}'), imported = importPair[0], setImported = importPair[1];
        var modelPair = useState(null), customModel = modelPair[0], setCustomModel = modelPair[1];
        useEffect(function () { request('settings').then(setSettings).catch(function (e) { props.notify(e.message, 'error'); }); }, []);
        useEffect(function () { request('attribution/configuration').then(setCustomModel).catch(function (e) { props.notify(e.message, 'error'); }); }, []);
        function set(field, value) { setSettings(Object.assign({}, settings, { [field]: value })); }
        return h('section', null, h('h2', null, __('Settings and definition import')), settings ? h('form', { className: 'wmos-card', onSubmit: async function (e) { e.preventDefault(); try { var data = Object.assign({}, settings); delete data.row_version; await request('settings', 'PUT', data, settings.row_version); setSettings(await request('settings')); props.notify(__('Settings saved.'), 'success'); } catch (error) { props.notify(error instanceof SyntaxError ? __('Enter valid JSON.') : error.message, 'error'); } } },
            h(Check, { label: __('Enable purpose-gated behavioral tracking'), checked: settings.tracking_enabled, onChange: function (v) { set('tracking_enabled', v); } }), h(Check, { label: __('Enable canonical commerce capture under configured policy'), checked: settings.commerce_enabled, onChange: function (v) { set('commerce_enabled', v); } }), field('Consent policy version', settings.consent_policy_version, function (v) { set('consent_policy_version', v); }), field('Raw event retention (days)', settings.retention_days, function (v) { set('retention_days', Number(v)); }, { type: 'number', min: 1, max: 3650 }), field('Financial retention (days; 0 means unconfigured)', settings.financial_retention_days, function (v) { set('financial_retention_days', Number(v)); }, { type: 'number', min: 0 }), field('Schedule timezone (IANA)', settings.time_zone, function (v) { set('time_zone', v); }), field('Sender email', settings.sender_email, function (v) { set('sender_email', v); }, { type: 'email' }), field('Sender name', settings.sender_name, function (v) { set('sender_name', v); }), h('fieldset', { className: 'wmos-node' }, h('legend', null, __('Enabled execution modules')), ['email', 'sms', 'push', 'whatsapp', 'telegram', 'ads', 'social', 'webhook', 'programs', 'promotions', 'campaigns', 'automations', 'segments', 'measurement', 'recommendations', 'personalization'].map(function (module) { return h(Check, { key: module, label: __(module), checked: settings.enabled_modules.includes(module), onChange: function (enabled) { set('enabled_modules', enabled ? settings.enabled_modules.concat([module]) : settings.enabled_modules.filter(function (x) { return x !== module; })); } }); })), h(Button, { type: 'submit', variant: 'primary' }, __('Save settings'))) : h(C.Spinner),
            customModel ? h('form', { className: 'wmos-card', onSubmit: async function (e) { e.preventDefault(); try { await request('attribution/configuration', 'PUT', customModel); props.notify(__('Custom weights saved for subsequent reconciliation. Existing allocations remain pinned.'), 'success'); } catch (error) { props.notify(error instanceof SyntaxError ? __('Enter valid JSON.') : error.message, 'error'); } } }, h('h3', null, __('Custom attribution weights')), field('Default attribution weight', customModel.default_weight, function (value) { setCustomModel(Object.assign({}, customModel, { default_weight: Number(value) })); }, { type: 'number', min: 0, max: 1000000 }), h(JsonField, { label: 'Channel attribution weights', value: customModel.channel_weights, onChange: function (value) { setCustomModel(Object.assign({}, customModel, { channel_weights: value })); }, help: 'Enter an object of channel names and nonnegative integer weights, for example {"email": 3, "direct": 1}. Attribution remains correlation.' }), h(Button, { type: 'submit', variant: 'secondary' }, __('Save attribution weights'))) : null,
            h('form', { className: 'wmos-card', onSubmit: async function (e) { e.preventDefault(); try { props.result(await request('definitions/import', 'POST', JSON.parse(imported))); props.notify(__('Imported as a draft. Review before publishing.'), 'success'); } catch (error) { props.notify(error instanceof SyntaxError ? __('Enter valid JSON.') : error.message, 'error'); } } }, h('h3', null, __('Import a versioned definition')), h(Area, { label: __('Definition JSON'), value: imported, onChange: setImported, rows: 12, className: 'wmos-json' }), h(Button, { type: 'submit', variant: 'secondary' }, __('Validate and import draft'))));
    }
    function Overview(props) {
        var pair = useState(null), data = pair[0], setData = pair[1];
        useEffect(function () { request('reports').then(setData).catch(function (e) { props.notify(e.message, 'error'); }); }, []);
        return h('section', null, h('h2', null, __('Marketing overview')), h('div', { className: 'wmos-card' }, h('h3', null, __('Build a consent-aware lifecycle')), h('p', null, __('Configure a protected provider connection, record verified customer permission, publish a segment and automation, then measure canonical WooCommerce conversions.')),
            h('div', { className: 'wmos-actions' }, boot.capabilities.manage_integrations ? button('Configure a channel', function () { props.navigate('providers'); }) : null, boot.capabilities.manage_campaigns ? button('Create a campaign', function () { props.navigate('campaigns'); }) : null, boot.capabilities.view_health ? button('Review system health', function () { props.navigate('health'); }) : null)),
            data ? h('div', { className: 'wmos-card' }, h('h3', null, __('Recent measurement')), h('pre', { tabIndex: 0 }, json(data))) : h(C.Spinner));
    }
    function App() {
        var activePair = useState('overview'), active = activePair[0], setActive = activePair[1];
        var noticePair = useState(null), notice = noticePair[0], setNotice = noticePair[1];
        var resultPair = useState(null), result = resultPair[0], setResult = resultPair[1];
        function notify(text, status) { setNotice({ text: text, status: status || 'info' }); }
        function navigate(view) { setActive(view); setResult(null); setNotice(null); }
        var menus = [['overview', 'Overview', 'view_analytics']].concat(Object.keys(resources).map(function (id) { return [id, resources[id][0], resources[id][2]]; })).concat([['contacts', 'Customers and consent', 'view_contacts'], ['providers', 'Channels and integrations', 'manage_integrations'], ['messages', 'Messages', 'manage_campaigns'], ['links', 'Links and QR', 'manage_campaigns'], ['reports', 'Analytics and attribution', 'view_analytics'], ['health', 'System health', 'view_health'], ['settings', 'Settings and import', 'manage_settings']]);
        var props = { notify: notify, result: setResult, navigate: navigate };
        var content = resources[active] ? active === 'programs' ? h(Programs, props) : h(Definitions, Object.assign({}, props, { resource: active, key: active })) : ({ overview: h(Overview, props), contacts: h(Contacts, props), providers: h(Providers, props), messages: h(Messages, props), links: h(Links, props), reports: h(Report, props), health: h(Health, props), settings: h(Settings, props) })[active];
        return h('div', { className: 'wmos-app' }, h('header', { className: 'wmos-header' }, h('div', null, h('h1', null, __('Marketing operating system')), h('p', null, __('Campaigns, customer permission and measurable lifecycle execution in WooCommerce.'))), h('span', { className: 'wmos-badge' }, '1.0.0-rc.1')), h('div', { className: 'wmos-layout' },
            h('nav', { className: 'wmos-nav', 'aria-label': __('Marketing navigation') }, menus.filter(function (menu) { return boot.capabilities[menu[2]]; }).map(function (menu) { return h(Button, { key: menu[0], variant: 'tertiary', 'aria-current': active === menu[0] ? 'page' : undefined, onClick: function () { navigate(menu[0]); } }, __(menu[1])); })),
            h('main', { className: 'wmos-main', id: 'wmos-main', 'aria-label': __('Marketing workspace') }, notice ? h(C.Notice, { status: notice.status, onRemove: function () { setNotice(null); } }, notice.text) : null, content, result ? h('section', { className: 'wmos-card', 'aria-live': 'polite' }, h('div', { className: 'wmos-toolbar' }, h('h3', null, __('Operation result')), button('Dismiss result', function () { setResult(null); })), h('pre', { tabIndex: 0 }, json(result))) : null)));
    }
    var root = document.getElementById('wmos-admin-root');
    if (root) { if (wp.element.createRoot) wp.element.createRoot(root).render(h(App)); else wp.element.render(h(App), root); }
})(window.wp, window.wmosAdmin, window.wmosBuilders);
