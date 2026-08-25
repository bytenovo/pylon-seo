(function (wp) {
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var __ = wp.i18n.__;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var useSelect = wp.data.useSelect;
    var useDispatch = wp.data.useDispatch;
    var PluginSidebar = wp.editor.PluginSidebar || (wp.editPost && wp.editPost.PluginSidebar);
    var PanelBody = wp.components.PanelBody;
    var TextControl = wp.components.TextControl;
    var TextareaControl = wp.components.TextareaControl;
    var ToggleControl = wp.components.ToggleControl;
    var SelectControl = wp.components.SelectControl;
    var Button = wp.components.Button;
    var Spinner = wp.components.Spinner;
    var Icon = wp.components.Icon;
    var noticeActions = wp.data.dispatch('core/notices');

    function pylonEstimatePixels(text, fontSize) {
        fontSize = fontSize || 13;
        // Approximate per-character pixel widths at 13px system font, used
        // to estimate whether a title will be truncated in search results.
        var widths = {
            a: 5.5, b: 5.8, c: 5.2, d: 5.8, e: 5.4, f: 3.5, g: 5.5,
            h: 5.7, i: 2.5, j: 2.5, k: 5.2, l: 2.5, m: 8.5, n: 5.7,
            o: 5.6, p: 5.7, q: 5.7, r: 3.6, s: 5,   t: 3.5, u: 5.7,
            v: 5.2, w: 7.5, x: 5,   y: 5.2, z: 5,
            A: 6.8, B: 6.3, C: 6.8, D: 7,   E: 5.8, F: 5.5, G: 7.2,
            H: 7,   I: 3,   J: 4.5, K: 6,   L: 5.5, M: 8.5, N: 7,
            O: 7.5, P: 6,   Q: 7.5, R: 6.3, S: 6,   T: 6.5, U: 7,
            V: 6.5, W: 9.5, X: 6.5, Y: 6.5, Z: 6,
            '0': 5.5, '1': 3.5, '2': 5.5, '3': 5.5, '4': 5.8,
            '5': 5.5, '6': 5.5, '7': 5.5, '8': 5.5, '9': 5.5
        };
        var total = 0;
        for (var i = 0; i < text.length; i++) { total += widths[text[i]] || 4; }
        return total * (fontSize / 13);
    }

    var _gutenScoreTimer = null;
    window.pylonGutenServerScore = (window.pylonGutenbergData && parseInt(window.pylonGutenbergData.engine_overall)) || 0;

    function pylonRecalcGutenScore(postId) {
        clearTimeout(_gutenScoreTimer);
        _gutenScoreTimer = setTimeout(function () {
            var fd = new FormData();
            fd.append('action', 'pylon_recalculate_engine_score');
            fd.append('post_id', postId);
            fd.append('_ajax_nonce', window.pylonGutenbergData.nonce);
            var ed = wp.data.select('core/editor');
            fd.append('pylon_title', ed.getEditedPostAttribute('meta').pylon_title || '');
            fd.append('pylon_description', ed.getEditedPostAttribute('meta').pylon_description || '');
            fd.append('pylon_focus_keyword', ed.getEditedPostAttribute('meta').pylon_focus_keyword || '');
            fd.append('pylon_canonical', ed.getEditedPostAttribute('meta').pylon_canonical || '');
            fd.append('pylon_og_image', ed.getEditedPostAttribute('meta').pylon_og_image || '');
            fd.append('pylon_schema_type', ed.getEditedPostAttribute('meta').pylon_schema_type || '');
            fd.append('pylon_noindex', ed.getEditedPostAttribute('meta').pylon_noindex || '');
            fd.append('content', ed.getEditedPostAttribute('content') || '');
            fetch(window.pylonGutenbergData.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success && res.data && typeof res.data.overall !== 'undefined') {
                        window.pylonGutenServerScore = parseInt(res.data.overall) || 0;
                        if (window.pylonGutenbergData) window.pylonGutenbergData.engine_overall = window.pylonGutenServerScore;
                        window.dispatchEvent(new CustomEvent('pylon-score-updated', { detail: { overall: window.pylonGutenServerScore } }));
                    }
                }).catch(function () {});
        }, 800);
    }

    function pylonCalcScore(title, desc, keyword, content, headings, images, canonical, noindex, schemaType, ogImage) {
        title = title || '';
        desc = desc || '';
        keyword = keyword || '';
        content = content || '';
        canonical = canonical || '';
        noindex = noindex || false;
        schemaType = schemaType || '';
        ogImage = ogImage || '';

        var pb = window.pylonGutenbergData || {};
        var slug = pb.slug || '';
        var contentForChecks = pb.content_text || content;
        var contentLower = contentForChecks.toLowerCase().replace(/<[^>]*>/g, ' ');
        var hasList = (typeof pb.has_list !== 'undefined') ? pb.has_list : (/<[uo]l/i.test(content));
        var hasTable = (typeof pb.has_table !== 'undefined') ? pb.has_table : (/<table/i.test(content));
        var firstSentence = contentForChecks.replace(/<[^>]*>/g, ' ').replace(/&[^;]+;/g, ' ').trim().substring(0, 200);
        var hasQA = (contentForChecks.indexOf('?') !== -1) && headings > 0;
        var wordCount = pb.words > 0 ? pb.words : (contentLower ? contentLower.split(/\s+/).filter(Boolean).length : 0);

        var keywords = keyword ? keyword.split(',').map(function(s){ return s.trim().toLowerCase(); }).filter(function(s){ return s; }) : [];
        var kwSet = keywords.length > 0;
        var kwInTitle = false, kwInDesc = false, kwInContent = false, kwInSlug = false;
        for (var ki = 0; ki < keywords.length; ki++) {
            var k = keywords[ki];
            if (!kwInTitle && title.toLowerCase().indexOf(k) !== -1) kwInTitle = true;
            if (!kwInDesc && desc.toLowerCase().indexOf(k) !== -1) kwInDesc = true;
            if (!kwInContent && contentLower.indexOf(k) !== -1) kwInContent = true;
            if (!kwInSlug && slug.toLowerCase().indexOf(k) !== -1) kwInSlug = true;
        }
        var kwPts = 0;
        if (kwSet) {
            kwPts += 5;
            if (kwInTitle) kwPts += 5;
            if (kwInDesc) kwPts += 4;
            if (kwInContent) kwPts += 4;
            if (kwInSlug) kwPts += 2;
        }

        var google = 0;
        if (title.length >= 30 && title.length <= 60) google += 15;
        else if (title) google += 8;
        if (desc.length >= 120 && desc.length <= 160) google += 15;
        else if (desc) google += 7;
        if (wordCount >= 300) google += 10;
        if (wordCount >= 1000) google += 5;
        if (headings >= 3) google += 10;
        if (images > 0) google += 10;
        else google += 5;
        if (schemaType) google += 10;
        if (canonical) google += 5;
        if (ogImage) google += 5;
        if (hasList || hasTable) google += 5;
        google += kwPts;

        var bing = Math.min(100, google + (schemaType ? 5 : -5) + (images >= 2 ? 5 : 0));

        var chatgpt = 0;
        if (wordCount >= 500) chatgpt += 15;
        if (wordCount >= 1500) chatgpt += 10;
        if (/^[A-Z].+\./.test(firstSentence)) chatgpt += 15;
        if (hasQA) chatgpt += 10;
        if (hasList) chatgpt += 10;
        if (headings >= 5) chatgpt += 10;
        if (schemaType && ['Article', 'FAQPage', 'HowTo'].indexOf(schemaType) !== -1) chatgpt += 15;
        if (/\d{4}/.test(contentLower)) chatgpt += 5;
        if (hasTable) chatgpt += 10;
        chatgpt += kwPts;

        var perplexity = 0;
        if (wordCount >= 800) perplexity += 20;
        if (/\[\d+\]|<sup>|(University|Research|Study|According)/i.test(contentForChecks)) perplexity += 15;
        if (hasList) perplexity += 10;
        if (hasQA) perplexity += 10;
        if (headings >= 4) perplexity += 10;
        if (schemaType) perplexity += 15;
        if (images >= 1) perplexity += 5;
        if (/\d{4}/.test(contentLower)) perplexity += 5;
        if (/definition|meaning|what is|how to/i.test(title)) perplexity += 10;
        perplexity += kwPts;

        var gemini = Math.min(100, Math.floor((google + bing) / 2) + (images >= 2 ? 10 : 0) + (ogImage ? 5 : 0));

        var claude = 0;
        if (wordCount >= 1000) claude += 20;
        if (headings >= 6) claude += 15;
        if (hasList && hasTable) claude += 10;
        if (/nuance|trade-off|however|contrast|comparison|pros and cons/i.test(contentForChecks)) claude += 15;
        if (schemaType) claude += 10;
        if (/expert|professional|years of experience/i.test(contentForChecks)) claude += 10;
        if (hasQA) claude += 10;
        claude += kwPts;

        var scores = [
            Math.min(100, google), Math.min(100, bing), Math.min(100, chatgpt),
            Math.min(100, perplexity), Math.min(100, gemini), Math.min(100, claude)
        ];
        var overall = Math.floor(scores.reduce(function(a, b) { return a + b; }, 0) / scores.length);
        return Math.min(100, Math.max(0, overall));
    }

    function pylonScoreLabel(score) {
        if (score >= 80) return { label: __('Good', 'pylon-seo'), color: '#22c55e' };
        if (score >= 50) return { label: __('Ok', 'pylon-seo'), color: '#f59e0b' };
        if (score >= 1) return { label: __('Poor', 'pylon-seo'), color: '#ef4444' };
        return { label: __('N/A', 'pylon-seo'), color: '#9ca3af' };
    }

    function PylonScoreCircle(props) {
        var score = props.score;
        var info = pylonScoreLabel(score);
        var dashArray = (score / 100 * 100) + ', 100';
        return el('div', {
            style: { textAlign: 'center', padding: '12px 0', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '12px' }
        },
            el('svg', { viewBox: '0 0 36 36', width: '48', height: '48' },
                el('path', { className: 'pylon-score-bg', d: 'M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831', fill: 'none', stroke: '#e5e7eb', strokeWidth: '3' }),
                el('path', {
                    className: 'pylon-score-fill',
                    d: 'M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831',
                    fill: 'none', stroke: info.color, strokeWidth: '3',
                    strokeDasharray: dashArray, strokeLinecap: 'round'
                })
            ),
            el('div', { style: { textAlign: 'left' } },
                el('div', { style: { fontSize: '20px', fontWeight: '700', color: info.color } }, Math.round(score)),
                el('div', { style: { fontSize: '11px', color: '#6b7280' } }, info.label)
            )
        );
    }

    function GooglePreview(props) {
        var title = props.title || props.fallbackTitle || '';
        var desc = props.description || props.fallbackDescription || '';
        var link = (window.pylonGutenbergData && window.pylonGutenbergData.permalink) || '';
        var hasPixelData = typeof pylonEstimatePixels !== 'undefined';
        var titleWidth = hasPixelData ? pylonEstimatePixels(title, 20) : 0;
        var descWidth = hasPixelData ? pylonEstimatePixels(desc, 13) : 0;
        var titleTrunc = titleWidth > 600 || title.length > 60 ? title.substring(0, 55) + '...' : title;
        var descTrunc = descWidth > 920 || desc.length > 160 ? desc.substring(0, 150) + '...' : desc;
        return el('div', { style: { background: '#fff', border: '1px solid #e5e7eb', borderRadius: '8px', padding: '12px', marginBottom: '12px' } },
            el('div', { style: { fontSize: '11px', color: '#202124', lineHeight: '1.3' } },
                el('span', { style: { color: '#202124' } }, link.replace(/^https?:\/\//, '')),
                el('span', { style: { color: '#5f6368' } }, ' › ' + (window.pylonGutenbergData ? window.pylonGutenbergData.slug || '' : ''))
            ),
            el('div', { style: { color: '#1a0dab', fontSize: '20px', lineHeight: '1.3', paddingTop: '4px', cursor: 'pointer', wordBreak: 'break-word' } },
                el('span', {}, titleTrunc || el('span', { style: { color: '#9ca3af' } }, __('No title', 'pylon-seo')))
            ),
            el('div', { style: { color: '#545454', fontSize: '13px', lineHeight: '1.58', wordBreak: 'break-word' } },
                descTrunc || el('span', { style: { color: '#9ca3af' } }, __('No description', 'pylon-seo'))
            ),
            titleWidth > 600 ? el('div', { style: { fontSize: '11px', color: '#d93025', marginTop: '4px' } }, __('Title may be truncated in search results', 'pylon-seo')) : null,
            descWidth > 920 ? el('div', { style: { fontSize: '11px', color: '#d93025' } }, __('Description may be truncated in search results', 'pylon-seo')) : null
        );
    }

    function PylonMetaFields() {
        var postType = useSelect(function (select) { return select('core/editor').getCurrentPostType(); }, []);
        var meta = useSelect(function (select) {
            return select('core/editor').getEditedPostAttribute('meta') || {};
        }, []);
        var editPost = useDispatch('core/editor').editPost;
        var content = useSelect(function (select) {
            return select('core/editor').getEditedPostContent();
        }, []);

        var title = meta.pylon_title || '';
        var desc = meta.pylon_description || '';
        var keyword = meta.pylon_focus_keyword || '';
        var canonical = meta.pylon_canonical || '';
        var noindex = meta.pylon_noindex === '1';
        var nofollow = meta.pylon_nofollow === '1';
        var ogTitle = meta.pylon_og_title || '';
        var ogDesc = meta.pylon_og_description || '';
        var ogImage = meta.pylon_og_image || '';
        var twTitle = meta.pylon_twitter_title || '';
        var twDesc = meta.pylon_twitter_description || '';
        var twImage = meta.pylon_twitter_image || '';
        var schemaType = meta.pylon_schema_type || '';

        var ogTitleEffective = ogTitle || title;
        var ogDescEffective = ogDesc || desc;
        var twTitleEffective = twTitle || ogTitleEffective;
        var twDescEffective = twDesc || ogDescEffective;

        var postTitle = useSelect(function (select) {
            return select('core/editor').getEditedPostAttribute('title');
        }, []);

        var excerpt = useSelect(function (select) {
            return select('core/editor').getEditedPostAttribute('excerpt');
        }, []);

        var contentText = content ? content.replace(/<[^>]*>/g, '') : '';
        var wordCount = contentText ? contentText.split(/\s+/).filter(Boolean).length : 0;
        var headingMatches = content ? content.match(/<h[1-6][^>]*>/gi) : null;
        var headingCount = headingMatches ? headingMatches.length : 0;
        var imgMatches = content ? content.match(/<img[^>]*>/gi) : null;
        var imgCount = imgMatches ? imgMatches.length : 0;

        var jsScore = pylonCalcScore(title || postTitle, desc || excerpt, keyword, content, headingCount, imgCount, canonical, noindex, schemaType, ogImage);

        var _ss = useState(window.pylonGutenServerScore || 0);
        var serverScoreState = _ss[0];
        var setServerScoreState = _ss[1];

        useEffect(function () {
            function onScoreUpdated(e) { setServerScoreState(e.detail.overall || 0); }
            window.addEventListener('pylon-score-updated', onScoreUpdated);
            return function () { window.removeEventListener('pylon-score-updated', onScoreUpdated); };
        }, []);

        var score = jsScore;

        var fieldsDef = [
            { key: 'pylon_title', label: __('SEO Title', 'pylon-seo'), type: 'text' },
            { key: 'pylon_description', label: __('Meta Description', 'pylon-seo'), type: 'textarea' },
            { key: 'pylon_focus_keyword', label: __('Focus Keyword', 'pylon-seo'), type: 'text' },
            { key: 'pylon_canonical', label: __('Canonical URL', 'pylon-seo'), type: 'text' },
        ];

        var ogFields = [
            { key: 'pylon_og_title', label: __('OG Title', 'pylon-seo'), type: 'text' },
            { key: 'pylon_og_description', label: __('OG Description', 'pylon-seo'), type: 'textarea' },
            { key: 'pylon_og_image', label: __('OG Image URL', 'pylon-seo'), type: 'text' },
            { key: 'pylon_twitter_title', label: __('Twitter Title', 'pylon-seo'), type: 'text' },
            { key: 'pylon_twitter_description', label: __('Twitter Description', 'pylon-seo'), type: 'textarea' },
            { key: 'pylon_twitter_image', label: __('Twitter Image URL', 'pylon-seo'), type: 'text' },
        ];

        var advancedFields = [
            { key: 'pylon_noindex', label: __('Noindex', 'pylon-seo'), type: 'toggle' },
            { key: 'pylon_nofollow', label: __('Nofollow', 'pylon-seo'), type: 'toggle' },
        ];

        var schemaTypes = (window.pylonGutenbergData && window.pylonGutenbergData.schema_types) || { '': __('None', 'pylon-seo') };

        var tabStyle = { padding: '10px 16px', cursor: 'pointer', fontSize: '12px', fontWeight: '600', borderBottom: '2px solid transparent', color: '#6b7280' };
        var tabActiveStyle = { padding: '10px 16px', cursor: 'pointer', fontSize: '12px', fontWeight: '600', borderBottom: '2px solid #1a73e8', color: '#1a73e8' };
        var tabs = ['general', 'social', 'advanced'];
        var [activeTab, setActiveTab] = useState('general');

        function updateMeta(key, value) {
            editPost({ meta: Object.assign({}, meta, { [key]: value }) });
            var postId = wp.data.select('core/editor').getCurrentPostId();
            if (postId) pylonRecalcGutenScore(postId);
        }

        var kwLimit = (window.pylonGutenbergData && window.pylonGutenbergData.kw_limit) || 5;

        function renderTextField(field) {
            var val = meta[field.key] || '';
            if (field.type === 'textarea') {
                return el(TextareaControl, {
                    label: field.label,
                    value: val,
                    onChange: function (v) { updateMeta(field.key, v); }
                });
            }
            var onChange = function (v) { updateMeta(field.key, v); };
            var help = null;
            if (field.key === 'pylon_focus_keyword') {
                onChange = function (v) {
                    var parts = v.split(',').map(function(s){ return s.trim(); }).filter(function(s){ return s; });
                    if (parts.length > kwLimit) {
                        parts = parts.slice(0, kwLimit);
                        v = parts.join(', ');
                    }
                    updateMeta(field.key, v);
                };
                var count = val.split(',').map(function(s){ return s.trim(); }).filter(function(s){ return s; }).length;
                help = count + ' / ' + kwLimit + ' keywords';
            }
            return el(TextControl, {
                label: field.label,
                value: val,
                help: help,
                onChange: onChange
            });
        }

        function renderToggle(field) {
            var val = meta[field.key] === '1';
            return el(ToggleControl, {
                label: field.label,
                checked: val,
                onChange: function (v) { updateMeta(field.key, v ? '1' : ''); }
            });
        }

        var checks = [];
        var kw = keyword;
        var t = title || postTitle || '';
        var d = desc || excerpt || '';
        checks.push({ id: 'keyword', pass: !!kw, label: __('Keyword set', 'pylon-seo') });
        var kwLower = kw.toLowerCase();
        var kwInTitle = t.toLowerCase().indexOf(kwLower) !== -1;
        var kwInDesc = d.toLowerCase().indexOf(kwLower) !== -1;
        var kwInContent = contentText.toLowerCase().indexOf(kwLower) !== -1;
        checks.push({ id: 'kw_used', pass: kw && (kwInTitle || kwInDesc || kwInContent), label: __('Keyword in title/desc/content', 'pylon-seo') });
        checks.push({ id: 'title_len', pass: t.length >= 10 && t.length <= 70, label: __('Title length 10–70 chars', 'pylon-seo'), note: t.length + ' chars' });
        checks.push({ id: 'desc_len', pass: d.length >= 50 && d.length <= 160, label: __('Description length 50–160 chars', 'pylon-seo'), note: d.length + ' chars' });
        checks.push({ id: 'content_words', pass: wordCount >= 300, label: __('Content ≥300 words', 'pylon-seo'), note: wordCount + ' words' });
        checks.push({ id: 'headings', pass: headingCount > 0, label: __('Has headings', 'pylon-seo'), note: headingCount + ' headings' });
        checks.push({ id: 'images', pass: imgCount > 0, label: __('Has images', 'pylon-seo'), note: imgCount + ' images' });

        return el(Fragment, {},
            el('div', { style: { borderBottom: '1px solid #e5e7eb', backgroundColor: '#f9fafb' } },
                el('div', { style: { display: 'flex' } },
                    el('div', { style: activeTab === 'general' ? tabActiveStyle : tabStyle, onClick: function () { setActiveTab('general'); } }, __('General', 'pylon-seo')),
                    el('div', { style: activeTab === 'social' ? tabActiveStyle : tabStyle, onClick: function () { setActiveTab('social'); } }, __('Social', 'pylon-seo')),
                    el('div', { style: activeTab === 'advanced' ? tabActiveStyle : tabStyle, onClick: function () { setActiveTab('advanced'); } }, __('Advanced', 'pylon-seo'))
                )
            ),
            el(PylonScoreCircle, { score: score }),

            activeTab === 'general' ? el(Fragment, {},
                el(GooglePreview, { title: title, description: desc, fallbackTitle: postTitle, fallbackDescription: excerpt }),
                fieldsDef.map(function (f) { return renderTextField(f); }),
                el(SelectControl, {
                    label: __('Schema Type', 'pylon-seo'),
                    value: schemaType,
                    options: Object.keys(schemaTypes).map(function (k) { return { label: schemaTypes[k], value: k }; }),
                    onChange: function (v) { updateMeta('pylon_schema_type', v); }
                }),
                el('div', { className: 'pylon-adash' },
                    el('div', { className: 'pylon-adash-g-grid' },
                        checks.map(function (c) {
                            return el('div', {
                                key: c.id,
                                className: 'pylon-adash-i' + (c.pass ? ' ok' : ' no'),
                                style: { display: 'flex', alignItems: 'center', padding: '4px 0', fontSize: '12px', gap: '6px' }
                            },
                                el('span', { className: 'pylon-adash-i-dot', style: {
                                    width: '8px', height: '8px', borderRadius: '50%', display: 'inline-block',
                                    backgroundColor: c.pass ? '#22c55e' : '#ef4444', flexShrink: 0
                                } }),
                                el('span', { className: 'pylon-adash-i-lbl', style: { color: c.pass ? '#374151' : '#9ca3af' } }, c.label),
                                c.note ? el('span', { className: 'pylon-adash-i-note', style: { fontSize: '10px', color: '#9ca3af', marginLeft: 'auto' } }, c.note) : null
                            );
                        })
                    )
                )
            ) : null,

            activeTab === 'social' ? el(Fragment, {},
                el('div', { style: { padding: '8px 0' } },
                    el('strong', { style: { fontSize: '11px', color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.5px' } }, __('Open Graph / Facebook', 'pylon-seo'))
                ),
                ogFields.slice(0, 3).map(function (f) { return renderTextField(f); }),
                el(GooglePreview, { title: ogTitleEffective, description: ogDescEffective, fallbackTitle: postTitle, fallbackDescription: excerpt }),
                el('div', { style: { padding: '8px 0', marginTop: '16px' } },
                    el('strong', { style: { fontSize: '11px', color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.5px' } }, __('Twitter / X', 'pylon-seo'))
                ),
                ogFields.slice(3, 6).map(function (f) { return renderTextField(f); }),
                el(GooglePreview, { title: twTitleEffective, description: twDescEffective, fallbackTitle: postTitle, fallbackDescription: excerpt })
            ) : null,

            activeTab === 'advanced' ? el(Fragment, {},
                advancedFields.map(function (f) { return renderToggle(f); }),
                el(SelectControl, {
                    label: __('Schema Type', 'pylon-seo'),
                    value: schemaType,
                    options: Object.keys(schemaTypes).map(function (k) { return { label: schemaTypes[k], value: k }; }),
                    onChange: function (v) { updateMeta('pylon_schema_type', v); }
                }),
                el(TextControl, {
                    label: __('Canonical URL', 'pylon-seo'),
                    value: canonical,
                    onChange: function (v) { updateMeta('pylon_canonical', v); }
                }),
                el('div', { style: { marginTop: '12px', fontSize: '11px', color: '#9ca3af' } },
                    el('a', { href: window.pylonGutenbergData ? window.pylonGutenbergData.full_edit_url : '', target: '_blank' }, __('Open full Pylon meta box →', 'pylon-seo'))
                )
            ) : null
        );
    }

    function AeoPanel() {
        var postType = useSelect(function (s) { return s('core/editor').getCurrentPostType(); }, []);
        var postId = useSelect(function (s) { return s('core/editor').getCurrentPostId(); }, []);
        var post = useSelect(function (s) {
            if (!postType || !postId) return null;
            return s('core').getEntityRecord('postType', postType, postId);
        }, [postType, postId]);
        var aeo = post && post.pylon_aeo_analysis;
        var aeoEnabled = window.pylonGutenbergData && window.pylonGutenbergData.aeo_enabled === '1';

        if (!aeoEnabled) {
            return el('div', { style: { padding: '12px', textAlign: 'center', fontSize: '12px', color: '#9ca3af' } },
                __('AEO analysis is disabled in settings.', 'pylon-seo')
            );
        }

        if (!aeo) {
            return el('div', { style: { padding: '16px', textAlign: 'center' } }, el(Spinner, {}));
        }

        var gradeColors = { high: '#22c55e', medium: '#f59e0b', low: '#ef4444' };
        var gradeLabels = { high: __('High', 'pylon-seo'), medium: __('Medium', 'pylon-seo'), low: __('Low', 'pylon-seo') };
        var gc = gradeColors[aeo.grade] || '#9ca3af';
        var gl = gradeLabels[aeo.grade] || __('N/A', 'pylon-seo');

        return el('div', {},
            el('div', { style: { textAlign: 'center', padding: '12px 0', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '12px' } },
                el('svg', { viewBox: '0 0 36 36', width: '48', height: '48' },
                    el('path', { d: 'M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831', fill: 'none', stroke: '#e5e7eb', strokeWidth: '3' }),
                    el('path', { d: 'M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831', fill: 'none', stroke: gc, strokeWidth: '3', strokeDasharray: (aeo.score / 100 * 100) + ', 100', strokeLinecap: 'round' })
                ),
                el('div', { style: { textAlign: 'left' } },
                    el('div', { style: { fontSize: '20px', fontWeight: '700', color: gc } }, Math.round(aeo.score)),
                    el('div', { style: { fontSize: '11px', color: gc } }, gl),
                    el('div', { style: { fontSize: '10px', color: '#9ca3af' } },
                        aeo.passed + '/' + aeo.total + ' ' + __('checks passed', 'pylon-seo')
                    )
                )
            ),
            aeo.checks ? el('div', { className: 'pylon-adash' },
                el('div', { className: 'pylon-adash-g-grid' },
                    Object.keys(aeo.checks).map(function (key) {
                        var c = aeo.checks[key];
                        return el('div', {
                            key: key,
                            className: 'pylon-adash-i' + (c.pass ? ' ok' : ' no'),
                            style: { display: 'flex', alignItems: 'center', padding: '4px 0', fontSize: '12px', gap: '6px' }
                        },
                            el('span', { className: 'pylon-adash-i-dot', style: {
                                width: '8px', height: '8px', borderRadius: '50%', display: 'inline-block',
                                backgroundColor: c.pass ? '#22c55e' : '#ef4444', flexShrink: 0
                            } }),
                            el('span', { className: 'pylon-adash-i-lbl', style: { color: c.pass ? '#374151' : '#9ca3af', fontSize: '11px' } }, c.label),
                            c.note ? el('span', { className: 'pylon-adash-i-note', style: { fontSize: '10px', color: '#9ca3af', marginLeft: 'auto', textAlign: 'right', maxWidth: '50%' } }, c.note) : null
                        );
                    })
                )
            ) : null
        );
    }

    if (PluginSidebar) {
        wp.plugins.registerPlugin('pylon-seo-sidebar', {
            icon: el('svg', { width: '20', height: '20', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: '2' },
                el('path', { d: 'M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5' })
            ),
            render: function () {
                return el(PluginSidebar, {
                    name: 'pylon-seo-sidebar',
                    title: __('Pylon SEO', 'pylon-seo')
                },
                    el(PanelBody, { title: __('SEO Settings', 'pylon-seo'), initialOpen: true },
                        el(PylonMetaFields, {})
                    ),
                    el(PanelBody, { title: __('SEO Analysis', 'pylon-seo'), initialOpen: false },
                        el(SeoAnalysisPanel, {})
                    ),
                    el(PanelBody, { title: __('Advanced Analysis', 'pylon-seo'), initialOpen: false },
                        el(AdvancedAnalysisPanel, {})
                    ),
                    el(PanelBody, { title: __('AEO Analysis', 'pylon-seo'), initialOpen: false },
                        el(AeoPanel, {})
                    )
                );
            }
        });
    }

    function SeoAnalysisPanel() {
        var data = window.pylonGutenbergData && window.pylonGutenbergData.seo_checks;
        var highlights = window.pylonGutenbergData && window.pylonGutenbergData.highlight_issues;
        if (!data || !data.checks || data.checks.length === 0) {
            return el('div', { style: { padding: '16px', textAlign: 'center', fontSize: '12px', color: '#9ca3af' } },
                __('Save the post to see SEO analysis.', 'pylon-seo')
            );
        }

        var _checkTabState = useState('seo');
        var activeCheckTab = _checkTabState[0];
        var setActiveCheckTab = _checkTabState[1];
        var tabs = [
            { key: 'seo', label: __('SEO', 'pylon-seo'), score: data.scores.seo || 0 },
            { key: 'readability', label: __('Readability', 'pylon-seo'), score: data.scores.readability || 0 },
            { key: 'technical', label: __('Technical', 'pylon-seo'), score: data.scores.technical || 0 },
            { key: 'media', label: __('Media', 'pylon-seo'), score: data.scores.media || 0 },
            { key: 'issues', label: __('Issues', 'pylon-seo'), count: (highlights || []).length },
        ];

        var statusColors = { pass: '#22c55e', warn: '#f59e0b', fail: '#ef4444', info: '#6366f1' };
        var statusIcons = { pass: '\u2713', warn: '!', fail: '\u2717', info: 'i' };
        var tabChecks = data.checks.filter(function(c) { return c.tab === activeCheckTab; });
        var passCount = tabChecks.filter(function(c) { return c.status === 'pass'; }).length;
        var totalCount = tabChecks.length;

        return el('div', {},
            el('div', { style: { textAlign: 'center', padding: '8px 0 12px' } },
                el('div', { style: { fontSize: '28px', fontWeight: '700', color: data.score >= 80 ? '#22c55e' : data.score >= 60 ? '#f59e0b' : data.score >= 40 ? '#f97316' : '#ef4444' } }, data.score),
                el('div', { style: { fontSize: '11px', color: '#9ca3af' } }, __('Overall Score', 'pylon-seo') + ' (' + passCount + '/' + totalCount + ' ' + __('pass', 'pylon-seo') + ')'),
                el('div', { style: { height: '4px', background: '#e5e7eb', borderRadius: '2px', marginTop: '8px', overflow: 'hidden' } },
                    el('div', {
                        style: {
                            height: '100%', width: data.score + '%',
                            background: data.score >= 80 ? '#22c55e' : data.score >= 60 ? '#f59e0b' : data.score >= 40 ? '#f97316' : '#ef4444',
                            borderRadius: '2px', transition: 'width 0.3s'
                        }
                    })
                )
            ),
            el('div', { style: { display: 'flex', gap: '2px', marginBottom: '12px', borderBottom: '1px solid #e5e7eb', paddingBottom: '8px', flexWrap: 'wrap' } },
                tabs.map(function(t) {
                    var isActive = activeCheckTab === t.key;
                    var label = t.key === 'issues' ? t.label + ' (' + t.count + ')' : t.label + ' (' + t.score + ')';
                    return el('div', {
                        key: t.key,
                        onClick: function() { setActiveCheckTab(t.key); },
                        style: {
                            flex: '1 1 0', minWidth: '48px', textAlign: 'center', padding: '6px 2px', cursor: 'pointer',
                            fontSize: '9px', fontWeight: isActive ? '700' : '400',
                            color: isActive ? '#1a73e8' : '#6b7280',
                            borderBottom: isActive ? '2px solid #1a73e8' : '2px solid transparent'
                        }
                    }, label);
                })
            ),
            activeCheckTab !== 'issues' ? el(Fragment, {},
                el('div', { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '4px 0 8px', fontSize: '11px', color: '#9ca3af' } },
                    el('span', {}, passCount + '/' + totalCount + ' ' + __('passed', 'pylon-seo')),
                    el('span', {}, (totalCount - passCount) + ' ' + __('issues', 'pylon-seo'))
                ),
                tabChecks.map(function(c) {
                    return el('div', {
                        key: c.id,
                        style: {
                            display: 'flex', alignItems: 'flex-start', padding: '6px 0',
                            borderBottom: '1px solid #f3f4f6', fontSize: '11px', gap: '8px'
                        }
                    },
                        el('span', {
                            style: {
                                width: '18px', height: '18px', borderRadius: '50%', display: 'flex',
                                alignItems: 'center', justifyContent: 'center', fontSize: '10px', fontWeight: '700',
                                color: '#fff', background: statusColors[c.status] || '#9ca3af', flexShrink: 0, marginTop: '1px'
                            }
                        }, statusIcons[c.status] || 'i'),
                        el('div', { style: { flex: 1, minWidth: 0 } },
                            el('div', { style: { fontWeight: '500', color: '#374151', lineHeight: '1.3' } }, c.label),
                            c.value ? el('div', { style: { color: '#9ca3af', fontSize: '10px', marginTop: '2px' } }, c.value) : null,
                            c.status !== 'pass' && c.status !== 'info' && c.suggestion ? el('div', {
                                style: {
                                    color: '#6b7280', fontSize: '10px', marginTop: '4px', padding: '6px 8px',
                                    background: '#f9fafb', borderRadius: '4px', lineHeight: '1.4',
                                    borderLeft: '3px solid ' + (statusColors[c.status] || '#9ca3af')
                                }
                            }, c.suggestion) : null
                        )
                    );
                })
            ) : el('div', {},
                el('div', { style: { padding: '6px 0 10px', fontSize: '11px', color: '#374151', fontWeight: '600' } },
                    (highlights || []).length + ' ' + __('content issues found', 'pylon-seo')
                ),
                !highlights || highlights.length === 0 ?
                    el('div', { style: { textAlign: 'center', padding: '20px 12px', color: '#9ca3af', fontSize: '12px' } },
                        el('div', { style: { fontSize: '24px', marginBottom: '6px' } }, '\uD83C\uDF89'),
                        __('No content issues found. Great work!', 'pylon-seo')
                    ) :
                    highlights.map(function(issue) {
                        return el('div', {
                            key: issue.id,
                            style: {
                                display: 'flex', alignItems: 'flex-start', gap: '8px', padding: '8px',
                                borderRadius: '6px', marginBottom: '4px', fontSize: '11px',
                                background: issue.severity === 'fail' ? '#fef2f2' : '#fffbeb',
                                border: '1px solid ' + (issue.severity === 'fail' ? '#fecaca' : '#fde68a')
                            }
                        },
                            el('span', { style: { fontSize: '14px', flexShrink: 0, marginTop: '1px' } }, issue.icon),
                            el('div', { style: { flex: 1, minWidth: 0 } },
                                el('div', { style: { fontWeight: '600', color: '#374151', lineHeight: '1.3', marginBottom: '2px' } }, issue.label),
                                el('div', {
                                    style: {
                                        color: '#6b7280', fontSize: '10px', lineHeight: '1.4',
                                        overflow: 'hidden', textOverflow: 'ellipsis', display: '-webkit-box',
                                        WebkitLineClamp: 2, WebkitBoxOrient: 'vertical'
                                    }
                                }, issue.text)
                            )
                        );
                    })
            )
        );
    }

    function AdvancedAnalysisPanel() {
        var data = window.pylonGutenbergData && window.pylonGutenbergData.advanced_checks;
        if (!data || !data.checks || data.checks.length === 0) {
            return el('div', { style: { padding: '16px', textAlign: 'center', fontSize: '12px', color: '#9ca3af' } },
                __('Save the post to see advanced analysis.', 'pylon-seo')
            );
        }

        var _advTabState = useState('eeat');
        var activeTab = _advTabState[0];
        var setActiveTab = _advTabState[1];
        var tabs = [
            { key: 'eeat', label: 'E-E-A-T', score: data.scores.eeat || 0, icon: '\u2B50' },
            { key: 'topical', label: __('Authority', 'pylon-seo'), score: data.scores.topical || 0, icon: '\uD83C\uDFAF' },
            { key: 'uniqueness', label: __('Originality', 'pylon-seo'), score: data.scores.uniqueness || 0, icon: '\u2728' },
        ];

        var statusColors = { pass: '#22c55e', warn: '#f59e0b', fail: '#ef4444', info: '#6366f1' };
        var tabChecks = data.checks.filter(function(c) { return c.tab === activeTab; });
        var passCount = tabChecks.filter(function(c) { return c.status === 'pass'; }).length;
        var totalCount = tabChecks.length;

        function getScoreColor(score) {
            return score >= 80 ? '#22c55e' : score >= 60 ? '#f59e0b' : score >= 40 ? '#f97316' : '#ef4444';
        }

        var eeat = data.scores.eeat || 0;
        var topical = data.scores.topical || 0;
        var uniqueness = data.scores.uniqueness || 0;
        var overall = Math.round((eeat + topical + uniqueness) / 3);

        return el('div', {},
            el('div', { style: { textAlign: 'center', padding: '8px 0 12px' } },
                el('div', { style: { display: 'flex', justifyContent: 'center', gap: '16px', marginBottom: '8px' } },
                    el('div', { style: { textAlign: 'center' } },
                        el('div', { style: { fontSize: '18px', fontWeight: '700', color: getScoreColor(eeat) } }, eeat),
                        el('div', { style: { fontSize: '9px', color: '#6b7280' } }, 'E-E-A-T')
                    ),
                    el('div', { style: { textAlign: 'center' } },
                        el('div', { style: { fontSize: '18px', fontWeight: '700', color: getScoreColor(topical) } }, topical),
                        el('div', { style: { fontSize: '9px', color: '#6b7280' } }, __('Authority', 'pylon-seo'))
                    ),
                    el('div', { style: { textAlign: 'center' } },
                        el('div', { style: { fontSize: '18px', fontWeight: '700', color: getScoreColor(uniqueness) } }, uniqueness),
                        el('div', { style: { fontSize: '9px', color: '#6b7280' } }, __('Originality', 'pylon-seo'))
                    )
                ),
                el('div', { style: { height: '4px', background: '#e5e7eb', borderRadius: '2px', overflow: 'hidden' } },
                    el('div', { style: { height: '100%', width: overall + '%', background: getScoreColor(overall), borderRadius: '2px', transition: 'width 0.3s' } })
                ),
                el('div', { style: { fontSize: '10px', color: '#9ca3af', marginTop: '4px', textAlign: 'center' } },
                    __('Advanced Score', 'pylon-seo') + ': ' + overall + '/100'
                )
            ),
            el('div', { style: { display: 'flex', gap: '2px', marginBottom: '12px', borderBottom: '1px solid #e5e7eb', paddingBottom: '8px' } },
                tabs.map(function(t) {
                    var isActive = activeTab === t.key;
                    return el('div', {
                        key: t.key,
                        onClick: function() { setActiveTab(t.key); },
                        style: {
                            flex: '1 1 0', textAlign: 'center', padding: '6px 4px', cursor: 'pointer',
                            fontSize: '10px', fontWeight: isActive ? '700' : '400',
                            color: isActive ? '#1a73e8' : '#6b7280',
                            borderBottom: isActive ? '2px solid #1a73e8' : '2px solid transparent'
                        }
                    }, t.label + ' (' + t.score + ')');
                })
            ),
            el('div', { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '4px 0 8px', fontSize: '11px', color: '#9ca3af' } },
                el('span', {}, passCount + '/' + totalCount + ' ' + __('passed', 'pylon-seo')),
                el('span', {}, (totalCount - passCount) + ' ' + __('issues', 'pylon-seo'))
            ),
            tabChecks.map(function(c) {
                return el('div', {
                    key: c.id,
                    style: {
                        display: 'flex', alignItems: 'flex-start', padding: '6px 0',
                        borderBottom: '1px solid #f3f4f6', fontSize: '11px', gap: '8px'
                    }
                },
                    el('span', {
                        style: {
                            width: '8px', height: '8px', borderRadius: '50%', flexShrink: 0, marginTop: '4px',
                            background: statusColors[c.status] || '#9ca3af'
                        }
                    }),
                    el('div', { style: { flex: 1, minWidth: 0 } },
                        el('div', { style: { fontWeight: '500', color: c.status === 'pass' ? '#16a34a' : '#374151', lineHeight: '1.3' } }, c.label),
                        c.value ? el('div', { style: { color: '#9ca3af', fontSize: '10px', marginTop: '2px' } }, c.value) : null,
                        c.status !== 'pass' && c.suggestion ? el('div', {
                            style: { color: '#6b7280', fontSize: '10px', marginTop: '4px', padding: '6px 8px', background: '#f9fafb', borderRadius: '4px', lineHeight: '1.4', borderLeft: '3px solid ' + (statusColors[c.status] || '#9ca3af') }
                        }, c.suggestion) : null
                    )
                );
            })
        );
    }
})(window.wp);
