(function (window, document) {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
            return;
        }

        document.addEventListener('DOMContentLoaded', fn);
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function createElement(html) {
        var holder = document.createElement('div');
        holder.innerHTML = html.trim();
        return holder.firstElementChild;
    }

    function clampNumber(value, min, max, fallback) {
        var parsed = parseInt(value, 10);
        if (isNaN(parsed)) {
            return fallback;
        }

        return Math.max(min, Math.min(max, parsed));
    }

    function toHex(value, fallback) {
        if (!value) {
            return fallback;
        }

        if (value.indexOf('#') === 0) {
            return value;
        }

        var match = value.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
        if (!match) {
            return fallback;
        }

        return '#'
            + ('0' + parseInt(match[1], 10).toString(16)).slice(-2)
            + ('0' + parseInt(match[2], 10).toString(16)).slice(-2)
            + ('0' + parseInt(match[3], 10).toString(16)).slice(-2);
    }

    function isValidLink(value) {
        var normalized = String(value || '').trim();
        if (normalized === '' || normalized === '#') {
            return true;
        }

        return /^(https?:\/\/|mailto:|tel:|\{\{unsubscribe_url\}\})/i.test(normalized);
    }

    function containsUnsubscribeTokenOrLink(value) {
        var normalized = String(value || '');
        var candidates = [normalized];
        var decoder = document.createElement('textarea');
        var patterns = [
            '{{unsubscribe_url}}',
            '%7b%7bunsubscribe_url%7d%7d',
            '&#123;&#123;unsubscribe_url&#125;&#125;',
            '&lcub;&lcub;unsubscribe_url&rcub;&rcub;',
            '\\u007b\\u007bunsubscribe_url\\u007d\\u007d',
            'href="{{unsubscribe_url}}"',
            '/marketing/unsubscribe/',
            '>unsubscribe<',
            'unsubscribe here'
        ];

        try {
            candidates.push(decodeURIComponent(normalized));
        } catch (error) {
        }

        decoder.innerHTML = normalized;
        candidates.push(decoder.value || '');

        return candidates.some(function (candidate) {
            var haystack = String(candidate || '').toLowerCase();
            return patterns.some(function (pattern) {
                return haystack.indexOf(pattern) !== -1;
            });
        });
    }

    function hasUnsubscribeToken(value) {
        return containsUnsubscribeTokenOrLink(value);
    }

    function normalizePlainTextValue(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function isUnsafeDefaultPlainText(value) {
        var normalized = normalizePlainTextValue(value);
        var signatures = [
            'Your headline goes here Introduce the offer, explain the value, and set up the email. Call to Action',
            'Your headline goes here Introduce the offer, explain the value, and set up the email. Call to Action Add your marketing copy here. Shop now Your Company 123 Market Street City, State Unsubscribe'
        ];

        return signatures.indexOf(normalized) !== -1;
    }

    function qrSvgDataUri(text, size, caption, foreground, background) {
        var safeText = escapeHtml(text || '');
        var safeCaption = escapeHtml(caption || '');
        var fg = foreground || '#111827';
        var bg = background || '#ffffff';
        var module = size / 12;
        var svg = ''
            + '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '" viewBox="0 0 240 240">'
            + '<rect width="240" height="240" rx="18" fill="' + bg + '"/>'
            + '<rect x="18" y="18" width="204" height="204" rx="18" fill="' + bg + '" stroke="' + fg + '" stroke-width="4"/>'
            + '<rect x="28" y="28" width="56" height="56" rx="8" fill="' + fg + '"/>'
            + '<rect x="156" y="28" width="56" height="56" rx="8" fill="' + fg + '"/>'
            + '<rect x="28" y="156" width="56" height="56" rx="8" fill="' + fg + '"/>'
            + '<rect x="' + (96 + module) + '" y="44" width="18" height="18" fill="' + fg + '"/>'
            + '<rect x="' + (122 + module) + '" y="70" width="18" height="18" fill="' + fg + '"/>'
            + '<rect x="' + (96 + module) + '" y="96" width="18" height="18" fill="' + fg + '"/>'
            + '<rect x="' + (122 + module) + '" y="122" width="18" height="18" fill="' + fg + '"/>'
            + '<rect x="' + (148 + module) + '" y="96" width="18" height="18" fill="' + fg + '"/>'
            + '<rect x="' + (148 + module) + '" y="148" width="18" height="18" fill="' + fg + '"/>'
            + '<text x="120" y="205" font-size="15" text-anchor="middle" fill="' + fg + '" font-family="Arial, sans-serif">QR</text>'
            + '<text x="120" y="222" font-size="10" text-anchor="middle" fill="#64748b" font-family="Arial, sans-serif">' + safeCaption + '</text>'
            + '</svg>';

        return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg + '<!--' + safeText + '-->');
    }

    function heroPlaceholderDataUri() {
        var svg = ''
            + '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="260" viewBox="0 0 640 260">'
            + '<defs><linearGradient id="g" x1="0" x2="1"><stop offset="0%" stop-color="#dbeafe"/><stop offset="100%" stop-color="#eff6ff"/></linearGradient></defs>'
            + '<rect width="640" height="260" rx="24" fill="url(#g)"/>'
            + '<rect x="48" y="48" width="128" height="36" rx="18" fill="#bfdbfe"/>'
            + '<rect x="48" y="104" width="300" height="22" rx="11" fill="#2563eb" opacity=".85"/>'
            + '<rect x="48" y="138" width="252" height="14" rx="7" fill="#60a5fa" opacity=".7"/>'
            + '<rect x="48" y="174" width="132" height="36" rx="18" fill="#2563eb"/>'
            + '<circle cx="498" cy="132" r="74" fill="#ffffff" opacity=".9"/>'
            + '<path d="M470 132h56M498 104v56" stroke="#2563eb" stroke-width="12" stroke-linecap="round"/>'
            + '</svg>';

        return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
    }

    ready(function () {
        var config = window.MarketingCampaignBuilderConfig || {};
        var strings = config.strings || {};
        var form = document.getElementById('marketing_campaign_form');
        var canvas = document.getElementById('marketing-builder-canvas');
        var palette = document.getElementById('marketing-builder-palette');
        var properties = document.getElementById('marketing-builder-properties');
        var canvasFrame = document.getElementById('marketing-builder-canvas-frame');
        var canvasModeLabel = document.getElementById('marketing-builder-canvas-mode-label');
        var stage = canvas ? canvas.closest('.marketing-builder-stage') : null;
        var htmlField = document.getElementById('html_content');
        var builderStateField = document.getElementById('builder_state');
        var textField = document.getElementById('text_content');
        var previewShell = document.getElementById('marketing-builder-preview-shell');
        var previewFrame = document.getElementById('marketing-builder-preview-frame');
        var fileInput = document.getElementById('marketing-builder-image-input');
        var paletteSearch = document.getElementById('marketing-builder-search');
        var libraryHeader = document.getElementById('marketing-builder-library-header');
        var insertNowButton = document.getElementById('marketing-builder-insert-unsubscribe-now');
        var previewModal = window.jQuery ? window.jQuery('#marketingBuilderPreviewModal') : null;
        var imageModal = window.jQuery ? window.jQuery('#marketingBuilderImageModal') : null;
        var qrModal = window.jQuery ? window.jQuery('#marketingBuilderQrModal') : null;
        var csrf = config.csrf || { name: '', hash: '' };
        var companyAddressConfig = config.companyAddress || {};
        var mergeTags = Array.isArray(config.mergeTags) ? config.mergeTags : [];
        var DEFAULT_PADDING = 5;
        var EMAIL_FONT_OPTIONS = ['Arial', 'Helvetica', 'Georgia', 'Times New Roman', 'Verdana', 'Tahoma', 'Trebuchet MS', 'Courier New'];

        if (!form || !canvas || !palette || !properties || !htmlField) {
            return;
        }

        var state = {
            activeBlock: null,
            activeHeroChild: '',
            draggingBlock: null,
            draggingNewType: '',
            currentDrop: null,
            previewMode: 'desktop',
            inspectorTab: 'content',
            sidebarMode: 'library',
            paletteSearch: '',
            openCategory: 'basic',
            uploadRequestId: 0,
            imageModalBlock: null,
            qrModalBlock: null,
            inlineUploadBlock: null,
            global: {
                emailBackground: '#eef4ff',
                contentBackground: '#ffffff',
                textColor: '#0f172a',
                linkColor: '#2563eb'
            },
            sidebarEditor: null,
            suppressAutoSelect: false
        };

        var dropIndicator = createElement('<div class="marketing-builder-drop-indicator" aria-hidden="true"></div>');
        var imageModalFields = {
            status: document.getElementById('marketing-builder-image-upload-status'),
            url: document.getElementById('marketing-builder-image-url'),
            alt: document.getElementById('marketing-builder-image-alt'),
            width: document.getElementById('marketing-builder-image-width'),
            height: document.getElementById('marketing-builder-image-height'),
            align: document.getElementById('marketing-builder-image-align'),
            link: document.getElementById('marketing-builder-image-link'),
            paddingTop: document.getElementById('marketing-builder-image-padding-top'),
            paddingRight: document.getElementById('marketing-builder-image-padding-right'),
            paddingBottom: document.getElementById('marketing-builder-image-padding-bottom'),
            paddingLeft: document.getElementById('marketing-builder-image-padding-left'),
            marginTop: document.getElementById('marketing-builder-image-margin-top'),
            marginBottom: document.getElementById('marketing-builder-image-margin-bottom'),
            radius: document.getElementById('marketing-builder-image-radius'),
            uploadButton: document.getElementById('marketing-builder-image-upload-button'),
            removeButton: document.getElementById('marketing-builder-image-remove-button'),
            applyButton: document.getElementById('marketing-builder-image-apply')
        };
        var qrModalFields = {
            target: document.getElementById('marketing-builder-qr-target'),
            size: document.getElementById('marketing-builder-qr-size'),
            align: document.getElementById('marketing-builder-qr-align'),
            foreground: document.getElementById('marketing-builder-qr-foreground'),
            background: document.getElementById('marketing-builder-qr-background'),
            caption: document.getElementById('marketing-builder-qr-caption'),
            padding: document.getElementById('marketing-builder-qr-padding'),
            marginBottom: document.getElementById('marketing-builder-qr-margin-bottom'),
            applyButton: document.getElementById('marketing-builder-qr-apply')
        };
        var paletteGroups = [
            {
                id: 'basic',
                title: strings.basic || 'Basic',
                blocks: [
                    { type: 'heading', label: strings.headingBlock || 'Heading', hint: 'H1 / H2', icon: 'heading' },
                    { type: 'text', label: strings.textEditorBlock || 'Text Editor', hint: 'Copy', icon: 'text' },
                    { type: 'image', label: strings.image || 'Image', hint: 'Media', icon: 'image' },
                    { type: 'button', label: strings.button || 'Button', hint: 'CTA', icon: 'button' },
                    { type: 'divider', label: strings.divider || 'Divider', hint: 'Rule', icon: 'divider' },
                    { type: 'spacer', label: strings.spacer || 'Spacer', hint: 'Space', icon: 'spacer' },
                    { type: 'icon', label: strings.iconBlock || 'Icon', hint: 'Badge', icon: 'icon' }
                ]
            },
            {
                id: 'structure',
                title: strings.structure || 'Structure',
                blocks: [
                    { type: 'hero', label: strings.hero || 'Header / Hero', hint: 'Hero', icon: 'hero' },
                    { type: 'inner_section', label: strings.innerSectionBlock || 'Inner Section', hint: 'Container', icon: 'section' },
                    { type: 'columns2', label: strings.columns2 || '2 Columns', hint: '2-up', icon: 'columns2' },
                    { type: 'columns3', label: strings.columns3 || '3 Columns', hint: '3-up', icon: 'columns3' },
                    { type: 'footer', label: strings.footer || 'Footer', hint: 'Footer', icon: 'footer' }
                ]
            },
            {
                id: 'media',
                title: strings.media || 'Media',
                blocks: [
                    { type: 'logo', label: strings.logo || 'Logo', hint: 'Brand', icon: 'logo' },
                    { type: 'image_box', label: strings.imageBoxBlock || 'Image Box', hint: 'Image + text', icon: 'imageBox' },
                    { type: 'qr', label: strings.qr || 'QR Code', hint: 'QR', icon: 'qr' },
                    { type: 'gallery', label: strings.galleryBlock || 'Gallery Placeholder', hint: 'Gallery', icon: 'gallery' }
                ]
            },
            {
                id: 'marketing',
                title: strings.marketing || 'Marketing',
                blocks: [
                    { type: 'cta', label: strings.ctaBlock || 'Call to Action', hint: 'CTA', icon: 'cta' },
                    { type: 'feature_list', label: strings.featureListBlock || 'Feature List', hint: 'Features', icon: 'list' },
                    { type: 'testimonial', label: strings.testimonialBlock || 'Testimonial', hint: 'Quote', icon: 'quote' },
                    { type: 'coupon', label: strings.couponBlock || 'Coupon / Offer', hint: 'Offer', icon: 'coupon' },
                    { type: 'social', label: strings.socialIconsBlock || 'Social Icons', hint: 'Social', icon: 'social' }
                ]
            },
            {
                id: 'compliance',
                title: strings.compliance || 'Compliance',
                blocks: []
            }
        ];

        function blockShell(type, innerHtml, marginBottom) {
            return createElement(
                '<div class="mb-block" data-builder-block="' + type + '" draggable="true" style="margin-top:0;margin-bottom:' + (marginBottom || 16) + 'px;">'
                + innerHtml
                + '</div>'
            );
        }

        function createDropzoneMarkup() {
            return '<div class="mb-column-slot mb-dropzone" data-dropzone-slot="1"></div>';
        }

        function createRichText(text) {
            return '<div class="mb-richtext" contenteditable="true"><p style="margin:0;">' + escapeHtml(text) + '</p></div>';
        }

        function getSavedCompanyAddressHtml() {
            return String(companyAddressConfig.html || 'Your Company<br>123 Market Street<br>City, State');
        }

        function getSavedCompanyAddressText() {
            return String(companyAddressConfig.text || 'Your Company\n123 Market Street\nCity, State');
        }

        function getSavedCompanyAddressPayload() {
            var html = String(companyAddressConfig.html || '').trim();
            var text = String(companyAddressConfig.text || '').trim();
            var fallbackText;

            if (!html && text) {
                html = text.split(/\r?\n/).map(function (line) {
                    return escapeHtml(line);
                }).join('<br>');
            }

            if (!text && html) {
                text = htmlToFallbackPlainText(html);
            }

            if (!html) {
                html = 'Your Company<br>123 Market Street<br>City, State';
            }

            if (!text) {
                fallbackText = htmlToFallbackPlainText(html);
                text = fallbackText || 'Your Company\n123 Market Street\nCity, State';
            }

            return {
                html: html,
                text: text,
                configured: !!companyAddressConfig.configured,
                source: String(companyAddressConfig.source || 'marketing_module_fallback')
            };
        }

        function hasConfiguredCompanyAddress() {
            return !!companyAddressConfig.configured;
        }

        function getMergeTagTokenByKey(key) {
            var map = {
                first_name: '{{first_name}}',
                last_name: '{{last_name}}',
                full_name: '{{full_name}}',
                email: '{{email}}',
                company: '{{company}}',
                unsubscribe_url: '{{unsubscribe_url}}'
            };

            return map[key] || key;
        }

        function sanitizeEditableHtml(rawHtml) {
            var parser = new window.DOMParser();
            var doc = parser.parseFromString('<div>' + String(rawHtml || '') + '</div>', 'text/html');
            var allowedTags = {
                P: true,
                BR: true,
                UL: true,
                OL: true,
                LI: true,
                STRONG: true,
                B: true,
                EM: true,
                I: true,
                U: true,
                S: true,
                BLOCKQUOTE: true,
                A: true,
                H1: true,
                H2: true,
                H3: true,
                H4: true,
                DIV: true,
                SPAN: true
            };
            var allowedInlineStyles = {
                color: true,
                'background-color': true,
                'text-align': true,
                'padding-left': true
            };

            function cleanInlineStyle(styleValue) {
                var safeStyles = [];

                String(styleValue || '').split(';').forEach(function (declaration) {
                    var parts = declaration.split(':');
                    var property;
                    var value;

                    if (parts.length < 2) {
                        return;
                    }

                    property = String(parts.shift() || '').trim().toLowerCase();
                    value = String(parts.join(':') || '').trim();

                    if (!allowedInlineStyles[property] || value === '') {
                        return;
                    }

                    if (property === 'padding-left' && !/^\d+(px|em|rem|%)$/.test(value)) {
                        return;
                    }

                    if ((property === 'color' || property === 'background-color') && /expression|url\s*\(|javascript:/i.test(value)) {
                        return;
                    }

                    if (property === 'text-align' && ['left', 'center', 'right', 'justify'].indexOf(value.toLowerCase()) === -1) {
                        return;
                    }

                    safeStyles.push(property + ':' + value);
                });

                return safeStyles.join(';');
            }

            function extractQuillAlignment(node) {
                var className = String(node.getAttribute('class') || '');
                var alignMatch = className.match(/ql-align-(center|right|justify)/);

                if (alignMatch) {
                    return alignMatch[1];
                }

                return '';
            }

            function extractQuillIndent(node) {
                var className = String(node.getAttribute('class') || '');
                var indentMatch = className.match(/ql-indent-(\d+)/);
                var depth = indentMatch ? parseInt(indentMatch[1], 10) : 0;

                if (!depth || depth < 1) {
                    return '';
                }

                return String(depth * 24) + 'px';
            }

            function sanitizeNode(node) {
                if (!node) {
                    return null;
                }

                if (node.nodeType === 3) {
                    return document.createTextNode(node.nodeValue || '');
                }

                if (node.nodeType !== 1) {
                    return null;
                }

                var tagName = String(node.tagName || '').toUpperCase();
                if (!allowedTags[tagName]) {
                    var fragment = document.createDocumentFragment();
                    Array.prototype.forEach.call(node.childNodes || [], function (childNode) {
                        var sanitizedChild = sanitizeNode(childNode);
                        if (sanitizedChild) {
                            fragment.appendChild(sanitizedChild);
                        }
                    });
                    return fragment;
                }

                var safeTagName = tagName === 'DIV' ? 'P' : tagName;
                var cleanNode = document.createElement(safeTagName.toLowerCase());

                if (safeTagName === 'A') {
                    var href = String(node.getAttribute('href') || '').trim();
                    if (isValidLink(href)) {
                        cleanNode.setAttribute('href', href);
                    } else {
                        cleanNode.setAttribute('href', '#');
                    }
                    cleanNode.setAttribute('target', '_blank');
                    cleanNode.setAttribute('rel', 'noopener noreferrer');
                }

                var inlineStyles = cleanInlineStyle(node.getAttribute('style') || '');
                var quillAlign = extractQuillAlignment(node);
                var quillIndent = extractQuillIndent(node);
                var mergedStyles = [];

                if (inlineStyles) {
                    mergedStyles.push(inlineStyles);
                }
                if (quillAlign && inlineStyles.toLowerCase().indexOf('text-align:') === -1) {
                    mergedStyles.push('text-align:' + quillAlign);
                }
                if (quillIndent && inlineStyles.toLowerCase().indexOf('padding-left:') === -1) {
                    mergedStyles.push('padding-left:' + quillIndent);
                }

                if (mergedStyles.length && safeTagName !== 'A') {
                    cleanNode.setAttribute('style', mergedStyles.join(';'));
                }

                Array.prototype.forEach.call(node.childNodes || [], function (childNode) {
                    var sanitizedChild = sanitizeNode(childNode);
                    if (sanitizedChild) {
                        cleanNode.appendChild(sanitizedChild);
                    }
                });

                return cleanNode;
            }

            var holder = document.createElement('div');
            Array.prototype.forEach.call(doc.body.firstChild ? doc.body.firstChild.childNodes : [], function (childNode) {
                var sanitizedChild = sanitizeNode(childNode);
                if (sanitizedChild) {
                    holder.appendChild(sanitizedChild);
                }
            });

            return holder.innerHTML.trim();
        }

        function plainTextToSanitizedHtml(text) {
            var lines = String(text || '').replace(/\r\n/g, '\n').split('\n');
            var html = '';
            var paragraph = [];
            var listType = '';
            var listItems = [];

            function flushParagraph() {
                if (!paragraph.length) {
                    return;
                }
                html += '<p>' + escapeHtml(paragraph.join('\n')).replace(/\n/g, '<br>') + '</p>';
                paragraph = [];
            }

            function flushList() {
                if (!listType || !listItems.length) {
                    listType = '';
                    listItems = [];
                    return;
                }

                html += '<' + listType + '>' + listItems.map(function (item) {
                    return '<li>' + escapeHtml(item) + '</li>';
                }).join('') + '</' + listType + '>';
                listType = '';
                listItems = [];
            }

            lines.forEach(function (line) {
                var trimmed = String(line || '').trim();
                if (trimmed === '') {
                    flushParagraph();
                    flushList();
                    return;
                }

                if (/^[-*]\s+/.test(trimmed)) {
                    flushParagraph();
                    if (listType !== 'ul') {
                        flushList();
                        listType = 'ul';
                    }
                    listItems.push(trimmed.replace(/^[-*]\s+/, ''));
                    return;
                }

                if (/^\d+\.\s+/.test(trimmed)) {
                    flushParagraph();
                    if (listType !== 'ol') {
                        flushList();
                        listType = 'ol';
                    }
                    listItems.push(trimmed.replace(/^\d+\.\s+/, ''));
                    return;
                }

                flushList();
                paragraph.push(line);
            });

            flushParagraph();
            flushList();

            return html !== '' ? html : '<p>' + escapeHtml(String(text || '')) + '</p>';
        }

        function isSidebarRichTextBlockType(type) {
            return ['text', 'feature_list', 'testimonial', 'inner_section', 'company_address', 'footer', 'email_footer'].indexOf(String(type || '')) !== -1;
        }

        function getSidebarTextEditableNode(block) {
            if (!block) {
                return null;
            }

            return block.querySelector('.mb-richtext')
                || block.querySelector('.mb-company-address-text')
                || block.querySelector('.mb-footer-text');
        }

        function htmlToFallbackPlainText(html) {
            var parser = new window.DOMParser();
            var doc = parser.parseFromString('<div>' + String(html || '') + '</div>', 'text/html');
            var pieces = [];

            function appendBreak(count) {
                while (count-- > 0) {
                    pieces.push('\n');
                }
            }

            function walk(node, listContext) {
                if (!node) {
                    return;
                }

                if (node.nodeType === 3) {
                    pieces.push(node.nodeValue || '');
                    return;
                }

                if (node.nodeType !== 1) {
                    return;
                }

                var tagName = String(node.tagName || '').toUpperCase();
                if (tagName === 'BR') {
                    appendBreak(1);
                    return;
                }

                if (tagName === 'LI') {
                    pieces.push(listContext === 'ol' ? '1. ' : '- ');
                }

                Array.prototype.forEach.call(node.childNodes || [], function (childNode) {
                    walk(childNode, tagName === 'UL' || tagName === 'OL' ? tagName.toLowerCase() : listContext);
                });

                if (tagName === 'LI') {
                    appendBreak(1);
                } else if (tagName === 'P' || tagName === 'DIV' || tagName === 'H1' || tagName === 'H2' || tagName === 'H3' || tagName === 'H4') {
                    appendBreak(2);
                } else if (tagName === 'UL' || tagName === 'OL') {
                    appendBreak(1);
                }
            }

            Array.prototype.forEach.call(doc.body.firstChild ? doc.body.firstChild.childNodes : [], function (childNode) {
                walk(childNode, '');
            });

            return pieces.join('')
                .replace(/\n{3,}/g, '\n\n')
                .replace(/[ \t]+\n/g, '\n')
                .trim();
        }

        function destroySidebarTextEditor() {
            if (state.sidebarEditor && state.sidebarEditor.quill && state.sidebarEditor.onTextChange) {
                state.sidebarEditor.quill.off('text-change', state.sidebarEditor.onTextChange);
            }

            if (state.sidebarEditor && state.sidebarEditor.quill && state.sidebarEditor.onSelectionChange) {
                state.sidebarEditor.quill.off('selection-change', state.sidebarEditor.onSelectionChange);
            }

            state.sidebarEditor = null;
        }

        function applySidebarEditorHtml(block, node, html) {
            var sanitized = sanitizeEditableHtml(html);

            if (!block || !node) {
                return;
            }

            node.innerHTML = sanitized || '<p><br></p>';
            syncFields();
        }

        function getCurrentUnsubscribeStatusNode() {
            return document.getElementById('marketing-builder-unsubscribe-status');
        }

        function getSidebarEditorSelection(quill) {
            var editorState = state.sidebarEditor;
            var range;

            if (!quill) {
                return null;
            }

            range = quill.getSelection();
            if (range) {
                return range;
            }

            if (editorState && editorState.quill === quill && editorState.savedRange) {
                return {
                    index: editorState.savedRange.index,
                    length: editorState.savedRange.length
                };
            }

            return null;
        }

        function restoreSidebarEditorSelection(quill) {
            var range = getSidebarEditorSelection(quill);

            if (!quill || !range) {
                return null;
            }

            quill.focus();
            quill.setSelection(range.index, range.length, 'silent');
            return range;
        }

        function applySidebarQuillFormat(quill, format, value) {
            var range = restoreSidebarEditorSelection(quill);
            var currentFormat;
            var nextValue;
            var currentLink;
            var requestedLink;
            var targetLength;

            if (!quill || !range) {
                return;
            }

            targetLength = range.length > 0 ? range.length : 0;

            if (format === 'clean') {
                if (targetLength > 0) {
                    quill.removeFormat(range.index, targetLength, 'user');
                    quill.setSelection(range.index, targetLength, 'silent');
                }
                quill.focus();
                state.sidebarEditor.savedRange = {
                    index: range.index,
                    length: targetLength
                };
                return;
            }

            if (format === 'link') {
                currentLink = (quill.getFormat(range.index, Math.max(targetLength, 1)) || {}).link || 'https://';
                requestedLink = window.prompt(strings.linkUrl || 'Link URL', currentLink);

                if (requestedLink === null) {
                    return;
                }

                requestedLink = String(requestedLink || '').trim();
                if (requestedLink !== '' && !isValidLink(requestedLink)) {
                    window.alert(strings.invalidLink || 'Enter a valid link that starts with http://, https://, mailto:, tel:, or {{unsubscribe_url}}.');
                    return;
                }

                if (targetLength > 0) {
                    quill.formatText(range.index, targetLength, format, requestedLink === '' ? false : requestedLink, 'user');
                    quill.setSelection(range.index, targetLength, 'silent');
                } else {
                    quill.format(format, requestedLink === '' ? false : requestedLink, 'user');
                    quill.setSelection(range.index, 0, 'silent');
                }
                quill.focus();
                state.sidebarEditor.savedRange = {
                    index: range.index,
                    length: targetLength
                };
                return;
            }

            currentFormat = quill.getFormat(range.index, Math.max(targetLength, 1)) || {};
            nextValue = typeof value !== 'undefined' ? value : !currentFormat[format];

            if (targetLength > 0) {
                quill.formatText(range.index, targetLength, format, nextValue, 'user');
                quill.setSelection(range.index, targetLength, 'silent');
            } else {
                quill.format(format, nextValue, 'user');
                quill.setSelection(range.index, 0, 'silent');
            }

            quill.focus();
            state.sidebarEditor.savedRange = {
                index: range.index,
                length: targetLength
            };
        }

        function syncSidebarEditorFromBlock() {
            var editorState = state.sidebarEditor;
            var sourceHtml;
            var normalizedHtml;

            if (!editorState || !editorState.block || !editorState.node) {
                return;
            }

            sourceHtml = sanitizeEditableHtml(editorState.node.innerHTML || '');

            if (editorState.mode === 'quill' && editorState.quill) {
                normalizedHtml = sanitizeEditableHtml(editorState.quill.root.innerHTML || '');
                if (normalizedHtml === sourceHtml) {
                    return;
                }

                editorState.syncing = true;
                editorState.quill.setContents([]);
                editorState.quill.clipboard.dangerouslyPasteHTML(sourceHtml || '<p><br></p>');
                editorState.syncing = false;
                return;
            }

            if (editorState.mode === 'textarea' && editorState.textarea) {
                editorState.textarea.value = htmlToFallbackPlainText(sourceHtml);
            }
        }

        function initializeSidebarTextEditor(block) {
            var editorHost;
            var fallbackShell;
            var fallbackTextarea;
            var sourceNode;
            var initialHtml;
            var quill;
            var textChangeHandler;

            destroySidebarTextEditor();

            if (!block || !isSidebarRichTextBlockType(block.dataset.builderBlock)) {
                return;
            }

            sourceNode = getSidebarTextEditableNode(block);
            editorHost = properties.querySelector('[data-marketing-builder-quill-editor="1"]');
            fallbackShell = properties.querySelector('[data-marketing-builder-textarea-fallback-shell="1"]');
            fallbackTextarea = properties.querySelector('[data-marketing-builder-textarea-fallback="1"]');
            initialHtml = sanitizeEditableHtml(sourceNode ? sourceNode.innerHTML : '');

            if (!sourceNode || !editorHost || !fallbackShell || !fallbackTextarea) {
                return;
            }

            if (window.Quill) {
                quill = new window.Quill(editorHost, {
                    theme: 'snow',
                    modules: {
                        toolbar: [
                            [{ header: [false, 1, 2, 3] }],
                            ['bold', 'italic', 'underline', 'strike'],
                            [{ color: [] }, { background: [] }],
                            [{ list: 'ordered' }, { list: 'bullet' }, { indent: '-1' }, { indent: '+1' }],
                            [{ align: [] }],
                            ['link', 'blockquote'],
                            ['clean']
                        ]
                    }
                });

                var quillToolbar = editorHost.parentNode.querySelector('.ql-toolbar');
                var toolbarModule = quill.getModule('toolbar');
                if (quillToolbar) {
                    Array.prototype.forEach.call(quillToolbar.querySelectorAll('button'), function (buttonNode) {
                        buttonNode.setAttribute('type', 'button');
                        buttonNode.addEventListener('mousedown', function (event) {
                            event.preventDefault();
                            restoreSidebarEditorSelection(quill);
                        });
                        buttonNode.addEventListener('click', function () {
                            restoreSidebarEditorSelection(quill);
                        });
                    });
                }

                fallbackShell.classList.add('hide');
                editorHost.parentNode.classList.remove('hide');

                state.sidebarEditor = {
                    mode: 'quill',
                    block: block,
                    node: sourceNode,
                    quill: quill,
                    fallbackShell: fallbackShell,
                    textarea: fallbackTextarea,
                    syncing: true,
                    onTextChange: null,
                    onSelectionChange: null,
                    savedRange: null
                };

                quill.clipboard.dangerouslyPasteHTML(initialHtml || '<p><br></p>');
                state.sidebarEditor.syncing = false;

                textChangeHandler = function () {
                    if (!state.sidebarEditor || state.sidebarEditor.syncing || state.sidebarEditor.block !== block) {
                        return;
                    }

                    applySidebarEditorHtml(block, sourceNode, quill.root.innerHTML || '');
                };

                state.sidebarEditor.onTextChange = textChangeHandler;
                quill.on('text-change', textChangeHandler);

                state.sidebarEditor.onSelectionChange = function (range) {
                    if (!state.sidebarEditor || state.sidebarEditor.block !== block || !range) {
                        return;
                    }

                    state.sidebarEditor.savedRange = {
                        index: range.index,
                        length: range.length
                    };
                };
                quill.on('selection-change', state.sidebarEditor.onSelectionChange);

                if (toolbarModule) {
                    ['bold', 'italic', 'underline', 'strike'].forEach(function (format) {
                        toolbarModule.addHandler(format, function (value) {
                            applySidebarQuillFormat(quill, format, value);
                        });
                    });
                    toolbarModule.addHandler('link', function (value) {
                        applySidebarQuillFormat(quill, 'link', value);
                    });
                    toolbarModule.addHandler('clean', function () {
                        applySidebarQuillFormat(quill, 'clean');
                    });
                }

                quill.focus();
                state.sidebarEditor.savedRange = quill.getSelection() || { index: Math.max(0, quill.getLength() - 1), length: 0 };
                return;
            }

            editorHost.parentNode.classList.add('hide');
            fallbackShell.classList.remove('hide');
            fallbackTextarea.value = htmlToFallbackPlainText(initialHtml);
            fallbackTextarea.addEventListener('input', function () {
                if (!state.sidebarEditor || state.sidebarEditor.block !== block) {
                    return;
                }

                applySidebarEditorHtml(block, sourceNode, plainTextToSanitizedHtml(fallbackTextarea.value));
            });

            state.sidebarEditor = {
                mode: 'textarea',
                block: block,
                node: sourceNode,
                textarea: fallbackTextarea,
                fallbackShell: fallbackShell,
                syncing: false
            };
        }

        function getPrimaryEditableNode(block) {
            if (!block) {
                return null;
            }

            if (block.dataset.builderBlock === 'hero' && state.activeHeroChild) {
                var activeHeroPart = block.querySelector('[data-hero-part="' + state.activeHeroChild + '"]');
                if (activeHeroPart) {
                    if (activeHeroPart.matches('[contenteditable="true"]')) {
                        return activeHeroPart;
                    }
                    return activeHeroPart.querySelector('[contenteditable="true"]');
                }
            }

            return block.querySelector('.mb-richtext')
                || block.querySelector('.mb-company-address-text')
                || block.querySelector('.mb-footer-text')
                || block.querySelector('.mb-heading-text')
                || block.querySelector('.mb-hero-headline')
                || block.querySelector('.mb-hero-subheadline')
                || block.querySelector('.mb-image-caption')
                || block.querySelector('.mb-icon-title')
                || block.querySelector('.mb-icon-description')
                || block.querySelector('[contenteditable="true"]');
        }

        function focusEditableNode(node) {
            if (!node) {
                return false;
            }

            node.focus();
            return true;
        }

        function insertContentAtCursor(node, content, asHtml) {
            if (!node) {
                return false;
            }

            focusEditableNode(node);
            if (document.queryCommandSupported && document.queryCommandSupported(asHtml ? 'insertHTML' : 'insertText')) {
                document.execCommand(asHtml ? 'insertHTML' : 'insertText', false, content);
                return true;
            }

            var selection = window.getSelection();
            if (!selection || selection.rangeCount === 0) {
                return false;
            }

            var range = selection.getRangeAt(0);
            range.deleteContents();

            if (asHtml) {
                var fragment = range.createContextualFragment(content);
                range.insertNode(fragment);
            } else {
                range.insertNode(document.createTextNode(content));
            }

            selection.removeAllRanges();
            return true;
        }

        function executeRichTextCommand(command, value) {
            var editable = document.activeElement && document.activeElement.closest
                ? document.activeElement.closest('[contenteditable="true"]')
                : null;

            if (!editable || !state.activeBlock || !state.activeBlock.contains(editable)) {
                editable = getPrimaryEditableNode(state.activeBlock);
            }

            if (!editable) {
                return;
            }

            focusEditableNode(editable);
            document.execCommand(command, false, value || null);
            if (editable.classList.contains('mb-richtext') || editable.classList.contains('mb-company-address-text') || editable.classList.contains('mb-footer-text')) {
                editable.innerHTML = sanitizeEditableHtml(editable.innerHTML);
            }
            syncFields();
            renderProperties();
        }

        function insertMergeTag(token) {
            var normalizedToken = String(token || '').trim();
            if (normalizedToken === '') {
                window.alert(strings.mergeTagSelectPrompt || 'Select a merge tag first.');
                return;
            }

            if (state.sidebarEditor && state.sidebarEditor.block === state.activeBlock) {
                if (state.sidebarEditor.mode === 'quill' && state.sidebarEditor.quill) {
                    var range = state.sidebarEditor.quill.getSelection(true);
                    var index = range ? range.index : Math.max(0, state.sidebarEditor.quill.getLength() - 1);
                    state.sidebarEditor.quill.insertText(index, normalizedToken, 'user');
                    state.sidebarEditor.quill.setSelection(index + normalizedToken.length, 0, 'silent');
                    state.sidebarEditor.quill.focus();
                    return;
                }

                if (state.sidebarEditor.mode === 'textarea' && state.sidebarEditor.textarea) {
                    var textarea = state.sidebarEditor.textarea;
                    var start = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : textarea.value.length;
                    var end = typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : textarea.value.length;
                    textarea.value = textarea.value.slice(0, start) + normalizedToken + textarea.value.slice(end);
                    textarea.selectionStart = textarea.selectionEnd = start + normalizedToken.length;
                    textarea.dispatchEvent(new Event('input', { bubbles: true }));
                    textarea.focus();
                    return;
                }
            }

            var activeElement = document.activeElement;
            if (activeElement && (activeElement.tagName === 'TEXTAREA' || activeElement.tagName === 'INPUT')) {
                var start = typeof activeElement.selectionStart === 'number' ? activeElement.selectionStart : activeElement.value.length;
                var end = typeof activeElement.selectionEnd === 'number' ? activeElement.selectionEnd : activeElement.value.length;
                var currentValue = String(activeElement.value || '');
                activeElement.value = currentValue.slice(0, start) + normalizedToken + currentValue.slice(end);
                activeElement.selectionStart = activeElement.selectionEnd = start + normalizedToken.length;
                activeElement.dispatchEvent(new Event('input', { bubbles: true }));
                return;
            }

            if (!insertContentAtCursor(getPrimaryEditableNode(state.activeBlock), normalizedToken, false)) {
                window.alert(strings.selectBlock || 'Select a block to edit it.');
                return;
            }

            syncFields();
        }

        function applySavedCompanyAddress(block) {
            var payload;
            var sanitizedHtml;
            var textValue;
            var surfaceNode;
            var sidebarEditorNode;

            if (!block) {
                return;
            }

            payload = getSavedCompanyAddressPayload();
            sanitizedHtml = sanitizeEditableHtml(payload.html || '');
            textValue = String(payload.text || '');
            var addressNode = block.querySelector('.mb-company-address-text') || block.querySelector('.mb-footer-text');
            if (!addressNode) {
                return;
            }

            addressNode.innerHTML = sanitizedHtml || '<p><br></p>';
            addressNode.setAttribute('data-saved-company-address-source', payload.source);
            addressNode.setAttribute('data-saved-company-address-text', textValue);

            surfaceNode = getSurface(block);
            if (surfaceNode) {
                surfaceNode.setAttribute('data-company-address-source', payload.source);
            }

            sidebarEditorNode = getSidebarTextEditableNode(block);
            if (state.sidebarEditor && state.sidebarEditor.block === block && sidebarEditorNode) {
                state.sidebarEditor.node = sidebarEditorNode;
                syncSidebarEditorFromBlock();
            }

            syncFields();
            if (state.activeBlock === block) {
                renderProperties();
            }
        }

        function findExistingFooterBlock() {
            return canvas.querySelector('.mb-block[data-builder-block="footer"], .mb-block[data-builder-block="email_footer"]');
        }

        function insertOrFocusCompanyAddressBlock() {
            var block = state.activeBlock;
            var existingBlock;
            var targetBlock;

            if (block && block.dataset.builderBlock === 'company_address') {
                applySavedCompanyAddress(block);
                setActiveBlock(block);
                return;
            }

            existingBlock = canvas.querySelector('.mb-block[data-builder-block="company_address"]');
            targetBlock = existingBlock || createCompanyAddressBlock();

            if (!existingBlock) {
                insertBlockAtPlacement(targetBlock, {
                    container: canvas,
                    beforeNode: findExistingFooterBlock() || findExistingUnsubscribeBlock() || null
                });
            }

            applySavedCompanyAddress(targetBlock);
            setActiveBlock(targetBlock);
        }

        function insertOrFocusEmailFooterBlock() {
            var existingFooter = findExistingFooterBlock();
            var footerBlock = existingFooter || createFooterBlock();

            if (!existingFooter) {
                insertBlockAtPlacement(footerBlock, {
                    container: canvas,
                    beforeNode: findExistingUnsubscribeBlock() || null
                });
            }

            if (companyAddressConfig.configured) {
                applySavedCompanyAddress(footerBlock);
            } else {
                syncFields();
            }
            setActiveBlock(footerBlock);
        }

        function renderMergeTagButtons() {
            if (!mergeTags.length) {
                return '';
            }

            return '<div class="marketing-builder-merge-tag-list" data-marketing-builder-merge-tag-list="1">'
                + mergeTags.map(function (tag) {
                    return '<button type="button" class="marketing-builder-merge-tag-chip" data-merge-tag-token="' + escapeHtml(tag.token || '') + '">' + escapeHtml(tag.label || tag.token || '') + '</button>';
                }).join('')
                + '</div>';
        }

        function renderTemplateOptions() {
            return ''
                + '<option value="default">' + escapeHtml(strings.defaultTemplateName || 'Start Email Design') + '</option>'
                + '<option value="newsletter">' + escapeHtml(strings.presetNewsletter || 'Newsletter') + '</option>'
                + '<option value="promotion">' + escapeHtml(strings.presetPromotion || 'Promotion / Offer') + '</option>'
                + '<option value="real_estate">' + escapeHtml(strings.presetRealEstate || 'Real Estate Listing') + '</option>'
                + '<option value="product">' + escapeHtml(strings.presetProduct || 'Product Announcement') + '</option>'
                + '<option value="simple_text">' + escapeHtml(strings.presetSimpleText || 'Simple Text Email') + '</option>'
                + '<option value="conference_event">' + escapeHtml(strings.presetConferenceEvent || 'Conference / Event Invitation') + '</option>';
        }

        function renderStaticPaletteSection(id, title, bodyHtml, extraClass) {
            var isOpen = !!state.paletteSearch || state.openCategory === id;

            return '<div class="marketing-builder-palette-group marketing-builder-palette-group--static' + (isOpen ? ' is-open' : '') + (extraClass ? ' ' + extraClass : '') + '" data-category="' + escapeHtml(id) + '">'
                + '<button type="button" class="marketing-builder-palette-group-title marketing-builder-palette-group-title--static" data-category-toggle="' + escapeHtml(id) + '">' + escapeHtml(title) + '</button>'
                + '<div class="marketing-builder-palette-static-body">' + bodyHtml + '</div>'
                + '</div>';
        }

        function renderSidebarActionCard(options) {
            var classes = 'marketing-builder-sidebar-action-card';
            var iconHtml = getPaletteIcon(options.icon || 'text');

            if (options.primary) {
                classes += ' marketing-builder-sidebar-action-card--primary';
            }

            return '<button type="button" class="' + classes + '" data-sidebar-action="' + escapeHtml(options.action) + '">'
                + '<span class="marketing-builder-sidebar-action-card-icon">' + iconHtml + '</span>'
                + '<span class="marketing-builder-sidebar-action-card-copy">'
                + '<span class="marketing-builder-sidebar-action-card-label">' + escapeHtml(options.label) + '</span>'
                + (options.note ? '<span class="marketing-builder-sidebar-action-card-note">' + escapeHtml(options.note) + '</span>' : '')
                + '</span>'
                + '</button>';
        }

        function renderSidebarLoadTemplateSection() {
            return renderStaticPaletteSection(
                'load-template',
                strings.applyPreset || 'Load Template',
                '<div class="form-group no-margin">'
                    + '<select id="marketing-builder-preset-select" class="form-control marketing-builder-sidebar-select">'
                    + renderTemplateOptions()
                    + '</select>'
                + '</div>'
                + '<button type="button" class="btn btn-primary btn-block mtop10" id="marketing-builder-apply-preset" data-sidebar-action="load-template">' + escapeHtml(strings.applyPreset || 'Load Template') + '</button>',
                'marketing-builder-palette-group--actions'
            );
        }

        function renderSidebarComplianceSection() {
            var statusText = hasConfiguredCompanyAddress()
                ? (strings.companyAddressSavedConfigured || 'Saved company address settings are ready to insert into this block.')
                : (strings.companyAddressSavedMissing || 'Saved company address settings are not configured yet. Update them in Marketing Settings first.');

            return renderStaticPaletteSection(
                'compliance',
                strings.compliance || 'Compliance',
                '<span id="marketing-builder-unsubscribe-status" class="marketing-builder-status-chip marketing-builder-status-chip--missing">' + escapeHtml(strings.unsubscribeMissing || 'Missing unsubscribe link') + '</span>'
                + '<div class="marketing-builder-sidebar-action-list marketing-builder-sidebar-action-list--grid">'
                + renderSidebarActionCard({ action: 'insert-unsubscribe', label: strings.insertUnsubscribe || 'Insert Unsubscribe Link', note: strings.unsubscribe || 'Required', primary: true, icon: 'unsubscribe' })
                + renderSidebarActionCard({ action: 'insert-company-address', label: strings.companyAddressBlock || 'Company Address', note: hasConfiguredCompanyAddress() ? (strings.companyAddressUseSaved || 'Use Saved Company Address') : (strings.companyAddressSavedMissing || 'Saved settings not configured yet.'), icon: 'address' })
                + renderSidebarActionCard({ action: 'use-saved-company-address', label: strings.companyAddressUseSaved || 'Use Saved Company Address', note: hasConfiguredCompanyAddress() ? (strings.companyAddressSavedConfigured || 'Saved company address settings are ready to insert into this block.') : (strings.companyAddressSavedMissing || 'Saved settings not configured yet.'), icon: 'address' })
                + renderSidebarActionCard({ action: 'insert-email-footer', label: strings.emailFooterBlock || 'Email Footer', note: strings.footer || 'Footer', icon: 'footer' })
                + '</div>'
                + '<p class="marketing-builder-settings-note no-margin">' + escapeHtml(statusText) + '</p>',
                'marketing-builder-palette-group--actions'
            );
        }

        function renderSidebarMergeTagsSection() {
            return renderStaticPaletteSection(
                'merge-tags',
                strings.insertMergeTag || 'Insert Merge Tags',
                '<p class="marketing-builder-settings-note no-margin">' + escapeHtml(strings.personalizeHelp || 'Insert personalization placeholders at the current cursor position inside the selected block.') + '</p>'
                + renderMergeTagButtons(),
                'marketing-builder-palette-group--actions'
            );
        }

        function renderSidebarPreviewSection(mode) {
            var buttonId = mode === 'mobile' ? 'marketing-builder-preview-mobile' : 'marketing-builder-preview-desktop';
            var label = mode === 'mobile'
                ? (strings.previewMobile || 'Preview Mobile')
                : (strings.previewDesktop || 'Preview Desktop');

            return renderStaticPaletteSection(
                mode === 'mobile' ? 'preview-mobile' : 'preview-desktop',
                label,
                '<button type="button" class="marketing-builder-sidebar-action-card marketing-builder-sidebar-action-card--primary" id="' + escapeHtml(buttonId) + '" data-sidebar-action="' + escapeHtml(mode === 'mobile' ? 'preview-mobile' : 'preview-desktop') + '">'
                + '<span class="marketing-builder-sidebar-action-card-label">' + escapeHtml(label) + '</span>'
                + '</button>',
                'marketing-builder-palette-group--actions'
            );
        }

        function renderRichTextToolbar(block) {
            var toolbar = '<div class="marketing-builder-rich-text-toolbar" data-marketing-builder-rich-text-toolbar="1">'
                + '<button type="button" class="marketing-builder-rich-text-button" data-rich-text-command="bold">' + escapeHtml(strings.richTextBold || 'Bold') + '</button>'
                + '<button type="button" class="marketing-builder-rich-text-button" data-rich-text-command="italic">' + escapeHtml(strings.richTextItalic || 'Italic') + '</button>'
                + '<button type="button" class="marketing-builder-rich-text-button" data-rich-text-command="underline">' + escapeHtml(strings.richTextUnderline || 'Underline') + '</button>'
                + '<button type="button" class="marketing-builder-rich-text-button" data-rich-text-command="formatBlock" data-rich-text-value="P">' + escapeHtml(strings.richTextParagraph || 'Paragraph') + '</button>'
                + '<button type="button" class="marketing-builder-rich-text-button" data-rich-text-command="formatBlock" data-rich-text-value="H2">' + escapeHtml(strings.richTextHeading2 || 'Heading 2') + '</button>'
                + '<button type="button" class="marketing-builder-rich-text-button" data-rich-text-command="formatBlock" data-rich-text-value="H3">' + escapeHtml(strings.richTextHeading3 || 'Heading 3') + '</button>'
                + '<button type="button" class="marketing-builder-rich-text-button" data-rich-text-command="insertUnorderedList">' + escapeHtml(strings.richTextBullets || 'Bullet List') + '</button>'
                + '<button type="button" class="marketing-builder-rich-text-button" data-rich-text-command="insertOrderedList">' + escapeHtml(strings.richTextNumbers || 'Numbered List') + '</button>'
                + '<button type="button" class="marketing-builder-rich-text-button" data-rich-text-command="createLink">' + escapeHtml(strings.richTextLink || 'Add Link') + '</button>'
                + '<button type="button" class="marketing-builder-rich-text-button" data-rich-text-command="removeFormat">' + escapeHtml(strings.richTextClear || 'Clear Formatting') + '</button>'
                + '</div>';

            toolbar += '<p class="marketing-builder-settings-note">' + escapeHtml(strings.richTextHelp || 'Select text on the canvas, then use these controls to format and sanitize the selected content.') + '</p>';

            if (mergeTags.length) {
                toolbar += '<div class="marketing-builder-settings-note-card"><strong>' + escapeHtml(strings.mergeTags || 'Merge Tags') + '</strong>'
                    + '<p class="marketing-builder-settings-note no-margin">' + escapeHtml(strings.personalizeHelp || 'Insert personalization placeholders at the current cursor position inside the selected block.') + '</p>'
                    + renderMergeTagButtons()
                    + '</div>';
            }

            if (block && (block.dataset.builderBlock === 'company_address' || block.dataset.builderBlock === 'footer' || block.dataset.builderBlock === 'email_footer')) {
                toolbar += '<div class="marketing-builder-settings-note-card">'
                    + '<strong>' + escapeHtml(strings.companyAddressBlock || 'Company Address') + '</strong>'
                    + '<p class="marketing-builder-settings-note no-margin">' + escapeHtml(companyAddressConfig.configured ? (strings.companyAddressSavedConfigured || 'Saved company address settings are available for this block.') : (strings.companyAddressSavedMissing || 'Saved company address settings are not configured yet.')) + '</p>'
                    + '<button type="button" class="btn btn-default btn-sm mtop10" data-action="use-saved-company-address">' + escapeHtml(strings.companyAddressUseSaved || 'Use Saved Company Address') + '</button>'
                    + '</div>';
            }

            return toolbar;
        }

        function createConferenceEventPreset() {
            var blocks = [];
            var hero = createHeroBlock();
            var intro = createTextBlock();
            var details = createInnerSectionBlock();
            var agenda = createFeatureListBlock();
            var cta = createCtaBlock();
            var footer = createFooterBlock();

            hero.querySelector('.mb-hero-headline').textContent = 'Conference / Event Invitation';
            hero.querySelector('.mb-hero-subheadline').textContent = 'Invite your audience to a live event with polished details, a clear CTA, and mobile-friendly structure.';
            hero.querySelector('.mb-cta-label').textContent = 'Reserve your seat';
            hero.querySelector('.mb-cta-link').setAttribute('href', 'https://example.com/register');

            intro.querySelector('.mb-richtext').innerHTML = sanitizeEditableHtml('<p>Dear {{first_name}},</p><p>We would love to welcome you to our upcoming conference. Use this reusable template to share the headline, event story, and why this invitation matters to each recipient.</p>');

            details.setAttribute('data-custom-label', strings.presetConferenceEvent || 'Conference / Event Invitation');
            details.querySelector('.mb-richtext').innerHTML = sanitizeEditableHtml('<p><strong>Event details</strong></p><p>Date: Add your event date<br>Time: Add your event time<br>Location: Add your venue or streaming link</p>');

            agenda.querySelector('.mb-heading-text').textContent = 'Agenda / Highlights';
            agenda.querySelector('.mb-richtext').innerHTML = sanitizeEditableHtml('<ul><li>Opening keynote or welcome message</li><li>Key sessions, speakers, or product highlights</li><li>Networking, breakout topics, or next steps</li></ul>');

            cta.querySelector('.mb-heading-text').textContent = 'Save your place today';
            cta.querySelector('.mb-richtext').innerHTML = sanitizeEditableHtml('<p>Use this section for your registration push, ticket note, or RSVP reminder. Include any deadline or attendance benefit that matters.</p>');
            cta.querySelector('.mb-button-label').textContent = 'Register now';
            cta.querySelector('.mb-button-link').setAttribute('href', 'https://example.com/register');

            footer.querySelector('.mb-footer-text').innerHTML = getSavedCompanyAddressHtml();

            blocks.push(hero, intro, details, agenda, cta, footer);

            return blocks;
        }

        function defaultSurfaceStyle(extraStyle) {
            return 'background:#ffffff;padding:' + DEFAULT_PADDING + 'px;border-radius:14px;' + (extraStyle || '');
        }

        function createHeroBlock() {
            return blockShell('hero', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="background:#0f172a;padding:' + DEFAULT_PADDING + 'px;border-radius:18px;text-align:center;color:#ffffff;">'
                + '<a href="" class="mb-hero-image-link" data-hero-part="image" style="display:block;text-decoration:none;">'
                + '<img src="' + heroPlaceholderDataUri() + '" alt="' + escapeHtml(strings.optionalImage || 'Optional image') + '" class="mb-hero-image" style="display:block;margin:0 auto 18px;max-width:100%;width:320px;height:auto;border-radius:18px;">'
                + '</a>'
                + '<h1 class="mb-hero-headline" data-hero-part="headline" contenteditable="true" style="margin:0 0 14px;font-family:Arial, sans-serif;font-size:32px;line-height:1.2;font-weight:700;color:#ffffff;">Your headline goes here</h1>'
                + '<p class="mb-hero-subheadline" data-hero-part="subheadline" contenteditable="true" style="margin:0 0 24px;font-family:Arial, sans-serif;font-size:16px;line-height:1.7;font-weight:400;color:#dbeafe;">Introduce the offer, explain the value, and set up the email.</p>'
                + '<a href="https://example.com" class="mb-cta-link" data-hero-part="button" target="_blank" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:14px 26px;border-radius:999px;font-family:Arial, sans-serif;font-size:16px;line-height:1.2;font-weight:700;"><span class="mb-cta-label" contenteditable="true">Call to Action</span></a>'
                + '</td></tr></table>');
        }

        function createLogoBlock() {
            return blockShell('logo', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="' + defaultSurfaceStyle('text-align:center;') + '">'
                + '<a href="https://example.com" class="mb-image-link" target="_blank" style="text-decoration:none;">'
                + '<img src="https://via.placeholder.com/180x60?text=Logo" alt="Logo" class="mb-logo-image" style="display:inline-block;width:180px;max-width:100%;height:auto;border-radius:0;">'
                + '</a></td></tr></table>');
        }

        function createTextBlock() {
            return blockShell('text', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="' + defaultSurfaceStyle('color:#0f172a;font-family:Arial, sans-serif;font-size:16px;line-height:1.7;font-weight:400;') + '">'
                + createRichText('Add your marketing copy here.')
                + '</td></tr></table>');
        }

        function createHeadingBlock() {
            return blockShell('heading', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="' + defaultSurfaceStyle('color:#0f172a;') + '">'
                + '<h2 class="mb-heading-text" contenteditable="true" style="margin:0;font-family:Arial, sans-serif;font-size:32px;line-height:1.2;font-weight:700;color:#0f172a;">Add a strong heading</h2>'
                + '</td></tr></table>');
        }

        function createIconBlock() {
            return blockShell('icon', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="' + defaultSurfaceStyle('text-align:center;color:#2563eb;') + '">'
                + '<div class="mb-icon-symbol" contenteditable="true" style="font-size:42px;line-height:1;">&#9733;</div>'
                + '<div class="mb-icon-title" contenteditable="true" style="margin-top:10px;font-family:Arial, sans-serif;font-size:20px;font-weight:700;line-height:1.3;color:#0f172a;">Icon title</div>'
                + '<div class="mb-icon-description" contenteditable="true" style="margin-top:8px;font-family:Arial, sans-serif;font-size:16px;font-weight:400;color:#475569;line-height:1.7;">Highlight one key point.</div>'
                + '<a href="" class="mb-icon-link" style="display:none;color:#2563eb;text-decoration:none;margin-top:10px;font-family:Arial, sans-serif;font-size:15px;font-weight:600;line-height:1.5;">Learn more</a>'
                + '</td></tr></table>');
        }

        function createImageBlock() {
            return blockShell('image', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="' + defaultSurfaceStyle('text-align:center;') + '">'
                + '<a href="" class="mb-image-link" style="text-decoration:none;">'
                + '<img src="https://via.placeholder.com/640x320?text=Image" alt="" class="mb-image" style="display:inline-block;width:320px;max-width:100%;height:auto;border-radius:12px;">'
                + '</a>'
                + '<div class="mb-image-caption" contenteditable="true" style="margin-top:12px;font-family:Arial, sans-serif;font-size:15px;font-weight:400;line-height:1.7;color:#64748b;">Optional caption</div>'
                + '</td></tr></table>');
        }

        function createButtonBlock() {
            return blockShell('button', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="' + defaultSurfaceStyle('text-align:center;') + '">'
                + '<a href="https://example.com" class="mb-button-link" target="_blank" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:14px 24px;border-radius:999px;font-family:Arial, sans-serif;font-size:16px;font-weight:700;line-height:1.2;">'
                + '<span class="mb-button-label" contenteditable="true">Shop now</span>'
                + '</a></td></tr></table>');
        }

        function createInnerSectionBlock() {
            return blockShell('inner_section', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="background:#f8fbff;padding:' + DEFAULT_PADDING + 'px;border:1px dashed #cbd5e1;border-radius:16px;color:#0f172a;font-family:Arial, sans-serif;font-size:16px;line-height:1.7;font-weight:400;">'
                + createRichText('Use this inner section to nest content, highlight a subsection, or break up longer emails.')
                + '</td></tr></table>');
        }

        function createImageBoxBlock() {
            return blockShell('image_box', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="' + defaultSurfaceStyle('') + '">'
                + '<div style="text-align:center;"><a href="" class="mb-image-link" style="text-decoration:none;"><img src="https://via.placeholder.com/520x220?text=Feature" alt="" class="mb-image" style="display:inline-block;width:280px;max-width:100%;height:auto;border-radius:12px;"></a></div>'
                + '<h3 class="mb-heading-text" contenteditable="true" style="margin:18px 0 8px;font-family:Arial, sans-serif;font-size:24px;line-height:1.3;font-weight:700;color:#0f172a;">Feature spotlight</h3>'
                + '<div class="mb-image-caption" contenteditable="true" style="font-family:Arial, sans-serif;font-size:15px;font-weight:400;color:#64748b;line-height:1.7;">Pair a visual with a short supporting explanation.</div>'
                + '</td></tr></table>');
        }

        function createGalleryBlock() {
            return blockShell('gallery', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="' + defaultSurfaceStyle('border-radius:16px;') + '">'
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
                + '<td style="padding:5px;"><div class="mb-slot-placeholder">Image 1</div></td>'
                + '<td style="padding:5px;"><div class="mb-slot-placeholder">Image 2</div></td>'
                + '<td style="padding:5px;"><div class="mb-slot-placeholder">Image 3</div></td>'
                + '</tr></table></td></tr></table>');
        }

        function createCtaBlock() {
            return blockShell('cta', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="background:#eff6ff;padding:' + DEFAULT_PADDING + 'px;border-radius:18px;text-align:center;">'
                + '<div class="mb-heading-text" contenteditable="true" style="margin:0 0 10px;font-family:Arial, sans-serif;font-size:28px;line-height:1.2;font-weight:700;color:#0f172a;">Ready to take the next step?</div>'
                + '<div class="mb-richtext" contenteditable="true" style="margin:0 0 18px;font-family:Arial, sans-serif;font-size:16px;font-weight:400;color:#475569;line-height:1.7;">Use this call-to-action block for urgent or conversion-focused messages.</div>'
                + '<a href="https://example.com" class="mb-button-link" target="_blank" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:14px 24px;border-radius:999px;font-family:Arial, sans-serif;font-size:16px;font-weight:700;line-height:1.2;"><span class="mb-button-label" contenteditable="true">Book now</span></a>'
                + '</td></tr></table>');
        }

        function createFeatureListBlock() {
            return blockShell('feature_list', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="' + defaultSurfaceStyle('color:#0f172a;') + '">'
                + '<div class="mb-heading-text" contenteditable="true" style="margin:0 0 10px;font-family:Arial, sans-serif;font-size:26px;line-height:1.2;font-weight:700;">Top reasons to choose this offer</div>'
                + '<div class="mb-richtext" contenteditable="true" style="font-family:Arial, sans-serif;font-size:16px;font-weight:400;line-height:1.8;"><ul style="margin:0;padding-left:20px;line-height:1.8;"><li>Benefit one with a short explanation</li><li>Benefit two with a short explanation</li><li>Benefit three with a short explanation</li></ul></div>'
                + '</td></tr></table>');
        }

        function createTestimonialBlock() {
            return blockShell('testimonial', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="background:#ffffff;padding:' + DEFAULT_PADDING + 'px;border-radius:16px;border:1px solid #e2e8f0;color:#0f172a;">'
                + '<div class="mb-richtext" contenteditable="true" style="font-family:Georgia, serif;font-size:20px;font-weight:400;line-height:1.6;color:#0f172a;">“This email builder helped us launch polished campaigns much faster.”</div>'
                + '<div class="mb-image-caption" contenteditable="true" style="margin-top:14px;font-family:Arial, sans-serif;font-size:14px;font-weight:500;color:#64748b;line-height:1.6;">Customer Name, Role</div>'
                + '</td></tr></table>');
        }

        function createCouponBlock() {
            return blockShell('coupon', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="background:#fff7ed;padding:' + DEFAULT_PADDING + 'px;border:1px dashed #fb923c;border-radius:18px;text-align:center;color:#9a3412;">'
                + '<div class="mb-heading-text" contenteditable="true" style="font-family:Arial, sans-serif;font-size:30px;line-height:1.1;font-weight:800;">SAVE20</div>'
                + '<div class="mb-image-caption" contenteditable="true" style="margin-top:10px;font-family:Arial, sans-serif;font-size:16px;font-weight:400;line-height:1.7;">Use this code to unlock your offer before it expires.</div>'
                + '</td></tr></table>');
        }

        function createCompanyAddressBlock() {
            var savedCompanyAddress = getSavedCompanyAddressPayload();

            return blockShell('company_address', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="' + defaultSurfaceStyle('text-align:center;color:#64748b;font-family:Arial, sans-serif;font-size:13px;line-height:1.7;font-weight:400;') + '">'
                + '<div class="mb-company-address-text" contenteditable="true" data-saved-company-address-source="' + escapeHtml(savedCompanyAddress.source) + '" data-saved-company-address-text="' + escapeHtml(savedCompanyAddress.text) + '">' + savedCompanyAddress.html + '</div>'
                + '</td></tr></table>');
        }

        function createDividerBlock() {
            return blockShell('divider', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="background:#ffffff;padding:' + DEFAULT_PADDING + 'px;border-radius:14px;">'
                + '<div class="mb-divider-line" style="border-top:1px solid #cbd5e1;"></div>'
                + '</td></tr></table>', 12);
        }

        function createSpacerBlock() {
            return blockShell('spacer', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="height:36px;font-size:0;line-height:0;background:transparent;">&nbsp;</td></tr></table>', 10);
        }

        function createColumnsBlock(type, preset) {
            var widths = preset.split('/');
            var cells = '';
            var i;

            for (i = 0; i < widths.length; i++) {
                cells += '<td class="mb-column-cell" width="' + escapeHtml(widths[i]) + '%" valign="top" style="padding:' + DEFAULT_PADDING + 'px;">'
                    + createDropzoneMarkup()
                    + '</td>';
            }

            return blockShell(type, ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="background:#ffffff;padding:' + DEFAULT_PADDING + 'px;border-radius:16px;">'
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' + cells + '</tr></table>'
                + '</td></tr></table>');
        }

        function createQrBlock() {
            return blockShell('qr', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="background:#ffffff;padding:' + DEFAULT_PADDING + 'px;border-radius:18px;text-align:center;border:1px solid #e2e8f0;box-shadow:0 10px 24px rgba(15,23,42,.06);">'
                + '<img src="' + qrSvgDataUri('https://example.com', 180, strings.qrFallbackNote || 'QR preview', '#111827', '#ffffff') + '" alt="QR Code" class="mb-qr-image" style="display:inline-block;width:180px;max-width:100%;height:auto;border-radius:16px;">'
                + '<div class="mb-qr-caption" contenteditable="true" style="margin-top:12px;font-family:Arial, sans-serif;font-size:15px;font-weight:500;line-height:1.6;color:#475569;">Scan to visit the linked page.</div>'
                + '<div class="mb-qr-meta hide" data-qr-target="https://example.com" data-qr-size="180" data-qr-align="center" data-qr-foreground="#111827" data-qr-background="#ffffff" data-qr-padding="' + DEFAULT_PADDING + '" data-qr-margin-bottom="16" data-qr-radius="16"></div>'
                + '</td></tr></table>');
        }

        function createUnsubscribeBlock() {
            return blockShell('unsubscribe', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="background:#f8fafc;padding:' + DEFAULT_PADDING + 'px;border-radius:14px;text-align:center;color:#64748b;font-family:Arial, sans-serif;font-size:13px;line-height:1.7;font-weight:400;">'
                + 'To opt out of future emails, <a href="{{unsubscribe_url}}" style="color:#2563eb;text-decoration:underline;">unsubscribe here</a>.'
                + '</td></tr></table>');
        }

        function createSocialBlock() {
            return blockShell('social', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="' + defaultSurfaceStyle('text-align:center;') + '">'
                + '<a href="" class="mb-social-link mb-social-facebook" style="display:none;color:#2563eb;text-decoration:none;margin:0 8px;font-family:Arial, sans-serif;font-size:15px;font-weight:600;line-height:1.5;">Facebook</a>'
                + '<a href="https://instagram.com" class="mb-social-link mb-social-instagram" style="color:#2563eb;text-decoration:none;margin:0 8px;font-family:Arial, sans-serif;font-size:15px;font-weight:600;line-height:1.5;">Instagram</a>'
                + '<a href="" class="mb-social-link mb-social-twitter" style="display:none;color:#2563eb;text-decoration:none;margin:0 8px;font-family:Arial, sans-serif;font-size:15px;font-weight:600;line-height:1.5;">X</a>'
                + '<a href="" class="mb-social-link mb-social-youtube" style="display:none;color:#2563eb;text-decoration:none;margin:0 8px;font-family:Arial, sans-serif;font-size:15px;font-weight:600;line-height:1.5;">YouTube</a>'
                + '<a href="" class="mb-social-link mb-social-linkedin" style="display:none;color:#2563eb;text-decoration:none;margin:0 8px;font-family:Arial, sans-serif;font-size:15px;font-weight:600;line-height:1.5;">LinkedIn</a>'
                + '<a href="" class="mb-social-link mb-social-website" style="display:none;color:#2563eb;text-decoration:none;margin:0 8px;font-family:Arial, sans-serif;font-size:15px;font-weight:600;line-height:1.5;">Website</a>'
                + '<div class="mb-social-meta hide" data-facebook="" data-instagram="https://instagram.com" data-twitter="" data-youtube="" data-linkedin="" data-website=""></div>'
                + '</td></tr></table>');
        }

        function createFooterBlock() {
            var savedCompanyAddress = getSavedCompanyAddressPayload();

            return blockShell('footer', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="background:#0f172a;padding:' + DEFAULT_PADDING + 'px;border-radius:16px;text-align:center;color:#cbd5e1;font-family:Arial, sans-serif;font-size:13px;line-height:1.7;font-weight:400;">'
                + '<div class="mb-footer-text" contenteditable="true" data-saved-company-address-source="' + escapeHtml(savedCompanyAddress.source) + '" data-saved-company-address-text="' + escapeHtml(savedCompanyAddress.text) + '">' + savedCompanyAddress.html + '</div>'
                + '<div style="margin-top:12px;"><a href="{{unsubscribe_url}}" style="color:#93c5fd;text-decoration:underline;">Unsubscribe</a></div>'
                + '</td></tr></table>');
        }

        function createEmailFooterBlock() {
            return createFooterBlock();
        }

        function ensureFooterUnsubscribeToken(block) {
            if (!block) {
                return;
            }

            var type = block.dataset.builderBlock;
            if (type !== 'footer' && type !== 'email_footer') {
                return;
            }

            if (hasUnsubscribeToken(block.innerHTML)) {
                return;
            }

            var surface = getSurface(block);
            if (!surface) {
                return;
            }

            surface.appendChild(createElement('<div style="margin-top:12px;"><a href="{{unsubscribe_url}}" style="color:#93c5fd;text-decoration:underline;">Unsubscribe</a></div>'));
        }

        function createLegacyBlock(rawHtml) {
            var preview = String(rawHtml || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            if (preview.length > 120) {
                preview = preview.slice(0, 117) + '...';
            }

            var block = blockShell('legacy', ''
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td data-block-surface="1" style="background:#fff7ed;padding:22px;border:1px dashed #fb923c;border-radius:16px;color:#9a3412;">'
                + '<div class="bold">' + escapeHtml(strings.legacyBlock || 'Legacy HTML block') + '</div>'
                + '<p class="no-margin mtop10">' + escapeHtml(preview || 'Legacy HTML preserved.') + '</p>'
                + '</td></tr></table>');

            block.dataset.rawHtml = String(rawHtml || '');
            return block;
        }

        function createBlock(type) {
            if (type === 'heading') {
                return createHeadingBlock();
            }
            if (type === 'hero') {
                return createHeroBlock();
            }
            if (type === 'icon') {
                return createIconBlock();
            }
            if (type === 'logo') {
                return createLogoBlock();
            }
            if (type === 'text') {
                return createTextBlock();
            }
            if (type === 'inner_section') {
                return createInnerSectionBlock();
            }
            if (type === 'image') {
                return createImageBlock();
            }
            if (type === 'image_box') {
                return createImageBoxBlock();
            }
            if (type === 'button') {
                return createButtonBlock();
            }
            if (type === 'cta') {
                return createCtaBlock();
            }
            if (type === 'feature_list') {
                return createFeatureListBlock();
            }
            if (type === 'testimonial') {
                return createTestimonialBlock();
            }
            if (type === 'coupon') {
                return createCouponBlock();
            }
            if (type === 'divider') {
                return createDividerBlock();
            }
            if (type === 'spacer') {
                return createSpacerBlock();
            }
            if (type === 'columns2') {
                return createColumnsBlock('columns2', '50/50');
            }
            if (type === 'columns3') {
                return createColumnsBlock('columns3', '33/33/33');
            }
            if (type === 'qr') {
                return createQrBlock();
            }
            if (type === 'gallery') {
                return createGalleryBlock();
            }
            if (type === 'unsubscribe') {
                return createUnsubscribeBlock();
            }
            if (type === 'social') {
                return createSocialBlock();
            }
            if (type === 'company_address') {
                return createCompanyAddressBlock();
            }
            if (type === 'email_footer') {
                return createEmailFooterBlock();
            }
            if (type === 'footer') {
                return createFooterBlock();
            }

            return createTextBlock();
        }

        function getSurface(block) {
            return block ? block.querySelector('[data-block-surface]') : null;
        }

        function getBodySurface(block) {
            if (!block) {
                return null;
            }

            return block.querySelector('.mb-richtext')
                || block.querySelector('.mb-company-address-text')
                || block.querySelector('.mb-footer-text');
        }

        function getBorderTarget(block) {
            var surface = getSurface(block);
            var headingNode = block ? block.querySelector('.mb-heading-text') : null;
            var bodyNode = getBodySurface(block);

            if (headingNode && bodyNode && bodyNode !== headingNode) {
                return bodyNode;
            }

            return surface || bodyNode;
        }

        function getBlockLabel(block) {
            if (!block) {
                return strings.blockSettings || 'Block Settings';
            }

            if (block.getAttribute('data-custom-label')) {
                return block.getAttribute('data-custom-label');
            }

            var type = block.dataset.builderBlock || '';
            var map = {
                heading: strings.headingBlock || 'Heading',
                hero: strings.hero || 'Header / Hero',
                icon: strings.iconBlock || 'Icon',
                logo: strings.logo || 'Logo',
                text: strings.textEditorBlock || strings.text || 'Text Editor',
                inner_section: strings.innerSectionBlock || 'Inner Section',
                image: strings.image || 'Image',
                image_box: strings.imageBoxBlock || 'Image Box',
                button: strings.button || 'Button',
                cta: strings.ctaBlock || 'Call to Action',
                feature_list: strings.featureListBlock || 'Feature List',
                testimonial: strings.testimonialBlock || 'Testimonial',
                coupon: strings.couponBlock || 'Coupon / Offer',
                divider: strings.divider || 'Divider',
                spacer: strings.spacer || 'Spacer',
                columns2: strings.columns2 || '2 Columns',
                columns3: strings.columns3 || '3 Columns',
                qr: strings.qr || 'QR Code',
                gallery: strings.galleryBlock || 'Gallery Placeholder',
                unsubscribe: strings.unsubscribe || 'Unsubscribe Link',
                social: strings.socialIconsBlock || strings.social || 'Social Icons',
                company_address: strings.companyAddressBlock || 'Company Address',
                email_footer: strings.emailFooterBlock || 'Email Footer',
                footer: strings.footer || 'Footer',
                legacy: strings.legacyBlock || 'Legacy HTML block'
            };

            return map[type] || type;
        }

        function getImageNode(block) {
            if (!block) {
                return null;
            }

            if (block.dataset.builderBlock === 'hero') {
                return block.querySelector('.mb-hero-image');
            }
            if (block.dataset.builderBlock === 'logo') {
                return block.querySelector('.mb-logo-image');
            }
            if (block.dataset.builderBlock === 'image') {
                return block.querySelector('.mb-image');
            }
            if (block.dataset.builderBlock === 'image_box') {
                return block.querySelector('.mb-image');
            }

            return null;
        }

        function getImageLinkNode(block) {
            if (!block) {
                return null;
            }

            if (block.dataset.builderBlock === 'hero') {
                return block.querySelector('.mb-hero-image-link');
            }

            return block.querySelector('.mb-image-link');
        }

        function getIconLinkNode(block) {
            return block ? block.querySelector('.mb-icon-link') : null;
        }

        function getHeroPartMap(block) {
            if (!block || block.dataset.builderBlock !== 'hero') {
                return {};
            }

            return {
                image: block.querySelector('.mb-hero-image-link'),
                headline: block.querySelector('.mb-hero-headline'),
                subheadline: block.querySelector('.mb-hero-subheadline'),
                button: block.querySelector('.mb-cta-link')
            };
        }

        function getHeroChildLabel(key) {
            var map = {
                image: strings.heroImage || 'Hero Image',
                headline: strings.heroHeadline || 'Hero Headline',
                subheadline: strings.heroSubheadline || 'Hero Subheadline',
                button: strings.heroButton || 'Hero Button'
            };

            return map[key] || (strings.hero || 'Header / Hero');
        }

        function getHeroChildOrder(block) {
            var partMap = getHeroPartMap(block);
            var fallback = ['image', 'headline', 'subheadline', 'button'];
            var order = String(block && block.dataset.heroOrder ? block.dataset.heroOrder : '').split(',').map(function (item) {
                return item.trim();
            }).filter(function (item) {
                return partMap[item];
            });

            fallback.forEach(function (key) {
                if (partMap[key] && order.indexOf(key) === -1) {
                    order.push(key);
                }
            });

            return order;
        }

        function syncHeroOrder(block) {
            if (!block || block.dataset.builderBlock !== 'hero') {
                return;
            }

            var surface = getSurface(block);
            var partMap = getHeroPartMap(block);
            var order = getHeroChildOrder(block);

            order.forEach(function (key) {
                if (partMap[key] && partMap[key].parentNode === surface) {
                    surface.appendChild(partMap[key]);
                }
            });

            block.dataset.heroOrder = order.join(',');
        }

        function isHeroPartVisible(node) {
            return !!node && node.getAttribute('data-hero-hidden') !== '1';
        }

        function setHeroPartVisibility(block, key, visible) {
            var partMap = getHeroPartMap(block);
            var node = partMap[key];

            if (!node) {
                return;
            }

            node.setAttribute('data-hero-hidden', visible ? '0' : '1');
            node.style.display = visible ? (key === 'button' ? 'inline-block' : 'block') : 'none';

            if (key === 'image') {
                var imageNode = block.querySelector('.mb-hero-image');
                if (imageNode) {
                    imageNode.style.display = visible ? 'block' : 'none';
                }
            }

            if (!visible && state.activeHeroChild === key) {
                state.activeHeroChild = '';
            }
        }

        function normalizeHeroBlock(block) {
            if (!block || block.dataset.builderBlock !== 'hero') {
                return;
            }

            var partMap = getHeroPartMap(block);
            var order = [];

            Object.keys(partMap).forEach(function (key) {
                var node = partMap[key];
                if (!node) {
                    return;
                }

                node.setAttribute('data-hero-part', key);
                order.push(key);

                if (typeof node.getAttribute('data-hero-hidden') === 'string' && node.getAttribute('data-hero-hidden') !== '') {
                    setHeroPartVisibility(block, key, node.getAttribute('data-hero-hidden') !== '1');
                } else if (window.getComputedStyle(node).display === 'none') {
                    node.setAttribute('data-hero-hidden', '1');
                } else {
                    node.setAttribute('data-hero-hidden', '0');
                }
            });

            if (!block.dataset.heroOrder) {
                block.dataset.heroOrder = order.join(',');
            }

            syncHeroOrder(block);
        }

        function updateToolbarCompactState(block) {
            if (!block) {
                return;
            }

            block.classList.toggle('mb-block--compact-toolbar', block.offsetWidth > 0 && block.offsetWidth < 260);
        }

        function getSocialLinkDefinitions() {
            return [
                { key: 'facebook', selector: '.mb-social-facebook', label: 'Facebook' },
                { key: 'instagram', selector: '.mb-social-instagram', label: 'Instagram' },
                { key: 'twitter', selector: '.mb-social-twitter', label: 'X' },
                { key: 'youtube', selector: '.mb-social-youtube', label: 'YouTube' },
                { key: 'linkedin', selector: '.mb-social-linkedin', label: 'LinkedIn' },
                { key: 'website', selector: '.mb-social-website', label: 'Website' }
            ];
        }

        function ensureSocialBlockStructure(block) {
            var surface;
            var meta;

            if (!block || block.dataset.builderBlock !== 'social') {
                return null;
            }

            surface = getSurface(block);
            if (!surface) {
                return null;
            }

            meta = block.querySelector('.mb-social-meta');
            if (!meta) {
                meta = createElement('<div class="mb-social-meta hide" data-facebook="" data-instagram="https://instagram.com" data-twitter="" data-youtube="" data-linkedin="" data-website=""></div>');
                surface.appendChild(meta);
            }

            getSocialLinkDefinitions().forEach(function (definition) {
                var link = block.querySelector(definition.selector);
                if (!link) {
                    link = createElement('<a href="" class="mb-social-link ' + definition.selector.replace('.', '') + '" style="display:none;color:#2563eb;text-decoration:none;margin:0 8px;font-family:Arial, sans-serif;font-size:15px;font-weight:600;line-height:1.5;">' + escapeHtml(definition.label) + '</a>');
                    surface.insertBefore(link, meta);
                }

                if (!meta.hasAttribute('data-' + definition.key)) {
                    meta.setAttribute('data-' + definition.key, '');
                }
            });

            return meta;
        }

        function getInspectorLabel(block) {
            if (!block) {
                return strings.blockSettings || 'Block Settings';
            }

            if (block.dataset.builderBlock === 'hero' && state.activeHeroChild) {
                return getHeroChildLabel(state.activeHeroChild);
            }

            return getBlockLabel(block);
        }

        function updateSocialLinks(block, values) {
            var meta = ensureSocialBlockStructure(block);
            if (!block || !meta) {
                return;
            }

            getSocialLinkDefinitions().forEach(function (item) {
                var value = String(values[item.key] || '').trim();
                var link = block.querySelector(item.selector);
                meta.setAttribute('data-' + item.key, value);
                if (!link) {
                    return;
                }
                if (value === '') {
                    link.style.display = 'none';
                    link.setAttribute('href', '');
                } else {
                    link.style.display = 'inline-block';
                    link.setAttribute('href', value);
                }
            });
        }

        function clearDropIndicator() {
            if (dropIndicator.parentNode) {
                dropIndicator.parentNode.removeChild(dropIndicator);
            }

            Array.prototype.forEach.call(canvas.querySelectorAll('.mb-dropzone.is-over'), function (zone) {
                zone.classList.remove('is-over');
            });

            state.currentDrop = null;
        }

        function ensureSlotPlaceholder(slot) {
            if (!slot) {
                return;
            }

            var hasBlocks = !!slot.querySelector(':scope > .mb-block');
            var placeholder = slot.querySelector(':scope > .mb-slot-placeholder');
            if (!hasBlocks && !placeholder) {
                slot.appendChild(createElement('<div class="mb-slot-placeholder">' + escapeHtml(strings.dropHere || 'Drop a block here') + '</div>'));
            }
            if (hasBlocks && placeholder) {
                placeholder.parentNode.removeChild(placeholder);
            }
        }

        function ensureCanvasPlaceholder() {
            var emptyState = canvas.querySelector('[data-empty-canvas="1"]');
            var hasBlocks = !!canvas.querySelector(':scope > .mb-block');

            if (!hasBlocks && !emptyState) {
                canvas.appendChild(createElement('<div class="marketing-builder-canvas-dropzone" data-empty-canvas="1">' + escapeHtml(strings.dropHere || 'Drop a block here') + '</div>'));
            }

            if (hasBlocks && emptyState) {
                emptyState.parentNode.removeChild(emptyState);
            }
        }

        function bindBlock(block) {
            if (!block || block.dataset.boundBlock === '1') {
                return;
            }

            var socialMeta;

            if (block.dataset.builderBlock === 'hero') {
                normalizeHeroBlock(block);
            }
            ensureFooterUnsubscribeToken(block);
            if (block.dataset.builderBlock === 'social') {
                socialMeta = ensureSocialBlockStructure(block);
                updateSocialLinks(block, {
                    facebook: socialMeta ? (socialMeta.getAttribute('data-facebook') || '') : '',
                    instagram: socialMeta ? (socialMeta.getAttribute('data-instagram') || '') : '',
                    twitter: socialMeta ? (socialMeta.getAttribute('data-twitter') || '') : '',
                    youtube: socialMeta ? (socialMeta.getAttribute('data-youtube') || '') : '',
                    linkedin: socialMeta ? (socialMeta.getAttribute('data-linkedin') || '') : '',
                    website: socialMeta ? (socialMeta.getAttribute('data-website') || '') : ''
                });
            }

            block.dataset.boundBlock = '1';
            block.setAttribute('draggable', 'true');
            if (!block.querySelector(':scope > .mb-block-toolbar')) {
                block.appendChild(createElement(
                    '<div class="mb-block-toolbar">'
                    + '<button type="button" class="mb-block-toolbar-button" data-canvas-action="move" title="Drag">&#8942;</button>'
                    + '<button type="button" class="mb-block-toolbar-button" data-canvas-action="duplicate" title="' + escapeHtml(strings.duplicateBlock || 'Duplicate') + '">&#x2398;</button>'
                    + '<button type="button" class="mb-block-toolbar-button mb-block-toolbar-button--danger" data-canvas-action="remove" title="' + escapeHtml(strings.removeBlock || 'Remove') + '">&times;</button>'
                    + '</div>'
                ));
            }

            block.addEventListener('dragstart', function (event) {
                state.draggingBlock = block;
                state.draggingNewType = '';
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', block.dataset.builderBlock || 'block');
            });

            block.addEventListener('dragend', function () {
                state.draggingBlock = null;
                clearDropIndicator();
            });
        }

        function refreshCanvasState() {
            Array.prototype.forEach.call(canvas.querySelectorAll('.mb-block'), bindBlock);
            Array.prototype.forEach.call(canvas.querySelectorAll('.mb-block'), updateToolbarCompactState);
            Array.prototype.forEach.call(canvas.querySelectorAll('.mb-column-slot'), ensureSlotPlaceholder);
            ensureCanvasPlaceholder();
            applyGlobalCanvasStyles();
        }

        function setActiveBlock(block) {
            Array.prototype.forEach.call(canvas.querySelectorAll('.mb-block.is-active'), function (node) {
                node.classList.remove('is-active');
            });
            Array.prototype.forEach.call(canvas.querySelectorAll('.mb-block [data-hero-part].is-active'), function (node) {
                node.classList.remove('is-active');
            });

            if (block !== state.activeBlock) {
                state.inspectorTab = 'content';
                state.activeHeroChild = '';
            }
            state.activeBlock = block || null;
            state.sidebarMode = state.activeBlock ? 'settings' : 'library';

            if (state.activeBlock) {
                if (state.activeBlock.dataset.builderBlock === 'hero') {
                    normalizeHeroBlock(state.activeBlock);
                }
                state.activeBlock.classList.add('is-active');
                updateToolbarCompactState(state.activeBlock);
                if (state.activeBlock.dataset.builderBlock === 'hero' && state.activeHeroChild) {
                    var activeHeroNode = state.activeBlock.querySelector('[data-hero-part="' + state.activeHeroChild + '"]');
                    if (activeHeroNode) {
                        activeHeroNode.classList.add('is-active');
                    }
                }
            }

            updateSidebarMode();
            renderProperties();
        }

        function updateSidebarMode() {
            if (!palette || !properties) {
                return;
            }

            if (state.sidebarMode === 'settings' && state.activeBlock) {
                palette.classList.add('hide');
                properties.classList.remove('hide');
                if (libraryHeader) {
                    libraryHeader.classList.add('hide');
                }
            } else {
                palette.classList.remove('hide');
                properties.classList.add('hide');
                if (libraryHeader) {
                    libraryHeader.classList.remove('hide');
                }
            }
        }

        function insertBlockAtPlacement(block, placement) {
            var container = placement && placement.container ? placement.container : canvas;
            var beforeNode = placement ? placement.beforeNode : null;
            var placeholder = container.querySelector(':scope > .mb-slot-placeholder');

            if (beforeNode && beforeNode.parentNode === container) {
                container.insertBefore(block, beforeNode);
            } else if (placeholder) {
                container.insertBefore(block, placeholder);
            } else {
                container.appendChild(block);
            }

            refreshCanvasState();
            if (state.suppressAutoSelect) {
                setActiveBlock(null);
            } else {
                setActiveBlock(block);
            }
            syncFields();
        }

        function applyPreset(name, confirmReplace) {
            var presetBlocks = [];

            if (confirmReplace && canvas.querySelector('.mb-block') && !window.confirm(strings.replaceConfirm || 'Replace the current email design with this template? This will remove the current design.')) {
                return;
            }

            canvas.innerHTML = '';
            state.activeBlock = null;

            if (name === 'newsletter') {
                insertBlockAtPlacement(createHeroBlock(), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createTextBlock(), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createColumnsBlock('columns2', '60/40'), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createFooterBlock(), { container: canvas, beforeNode: null });
            } else if (name === 'promotion') {
                insertBlockAtPlacement(createHeroBlock(), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createImageBlock(), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createButtonBlock(), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createUnsubscribeBlock(), { container: canvas, beforeNode: null });
            } else if (name === 'real_estate') {
                insertBlockAtPlacement(createHeroBlock(), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createColumnsBlock('columns2', '50/50'), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createTextBlock(), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createFooterBlock(), { container: canvas, beforeNode: null });
            } else if (name === 'product') {
                insertBlockAtPlacement(createHeroBlock(), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createImageBlock(), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createTextBlock(), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createButtonBlock(), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createFooterBlock(), { container: canvas, beforeNode: null });
            } else if (name === 'simple_text') {
                insertBlockAtPlacement(createTextBlock(), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createUnsubscribeBlock(), { container: canvas, beforeNode: null });
            } else if (name === 'conference_event' || name === 'event') {
                presetBlocks = createConferenceEventPreset();
                presetBlocks.forEach(function (presetBlock) {
                    insertBlockAtPlacement(presetBlock, { container: canvas, beforeNode: null });
                });
            } else {
                insertBlockAtPlacement(createHeroBlock(), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createTextBlock(), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createButtonBlock(), { container: canvas, beforeNode: null });
                insertBlockAtPlacement(createFooterBlock(), { container: canvas, beforeNode: null });
            }

            clearDropIndicator();
            setActiveBlock(canvas.querySelector('.mb-block'));
            syncFields();
        }

        function findExistingUnsubscribeBlock() {
            return canvas.querySelector('.mb-block[data-builder-block="unsubscribe"]');
        }

        function findBlockContainingUnsubscribeToken() {
            var matched = null;

            Array.prototype.forEach.call(canvas.querySelectorAll('.mb-block'), function (block) {
                if (!matched && hasUnsubscribeToken(block.innerHTML)) {
                    matched = block;
                }
            });

            return matched;
        }

        function getPreferredUnsubscribePlacement() {
            var existingFooter = canvas.querySelector('.mb-block[data-builder-block="footer"], .mb-block[data-builder-block="email_footer"]');

            if (existingFooter && existingFooter.parentNode) {
                return {
                    container: existingFooter.parentNode,
                    beforeNode: existingFooter.nextElementSibling
                };
            }

            return {
                container: canvas,
                beforeNode: null
            };
        }

        function loadTemplate(name) {
            applyPreset(name, true);
        }

        function findContainerBlock(target, container) {
            var block = target.closest('.mb-block');
            while (block && block.parentNode !== container) {
                block = block.parentNode ? block.parentNode.closest('.mb-block') : null;
            }
            return block;
        }

        function getDropPlacement(event) {
            var target = event.target;
            var slot = target.closest('.mb-column-slot');
            var container = slot && canvas.contains(slot) ? slot : canvas;
            var block = findContainerBlock(target, container);
            var beforeNode = null;

            if (block) {
                var rect = block.getBoundingClientRect();
                var insertBefore = event.clientY < (rect.top + rect.height / 2);
                beforeNode = insertBefore ? block : block.nextElementSibling;
            }

            if (state.draggingBlock) {
                if (container === state.draggingBlock || state.draggingBlock.contains(container)) {
                    return null;
                }
                if (beforeNode && state.draggingBlock.contains(beforeNode) && beforeNode !== state.draggingBlock) {
                    return null;
                }
            }

            return {
                container: container,
                beforeNode: beforeNode
            };
        }

        function showDropPlacement(placement) {
            if (!placement || !placement.container) {
                clearDropIndicator();
                return;
            }

            clearDropIndicator();
            state.currentDrop = placement;

            if (placement.beforeNode && placement.beforeNode.parentNode === placement.container) {
                placement.container.insertBefore(dropIndicator, placement.beforeNode);
            } else {
                var placeholder = placement.container.querySelector(':scope > .mb-slot-placeholder');
                if (placeholder) {
                    placement.container.insertBefore(dropIndicator, placeholder);
                } else {
                    placement.container.appendChild(dropIndicator);
                }
            }

            if (placement.container.classList.contains('mb-dropzone')) {
                placement.container.classList.add('is-over');
            }
        }

        function applyGlobalCanvasStyles() {
            canvas.style.background = state.global.emailBackground;
        }

        function autoScrollStageDuringDrag(event) {
            if (!stage) {
                return;
            }

            var rect = stage.getBoundingClientRect();
            var edgeThreshold = Math.min(80, Math.max(40, rect.height * 0.12));
            var delta = 0;

            if (event.clientY < rect.top + edgeThreshold) {
                delta = -24;
            } else if (event.clientY > rect.bottom - edgeThreshold) {
                delta = 24;
            }

            if (delta !== 0) {
                stage.scrollTop += delta;
            }
        }

        function updateUnsubscribeStatus() {
            var html = renderEmailHtml();
            var builderMarkup = builderStateField ? builderStateField.value : serializeBuilderState();
            var hasToken = hasUnsubscribeToken(html) || hasUnsubscribeToken(builderMarkup);
            var unsubscribeStatus = getCurrentUnsubscribeStatusNode();

            if (!unsubscribeStatus) {
                return;
            }

            unsubscribeStatus.textContent = hasToken ? (strings.unsubscribePresent || 'Unsubscribe link included') : (strings.unsubscribeMissing || 'Missing unsubscribe link');
            unsubscribeStatus.className = 'marketing-builder-status-chip ' + (hasToken ? 'marketing-builder-status-chip--ok' : 'marketing-builder-status-chip--missing');
        }

        function serializeBlock(block) {
            if (!block) {
                return '';
            }

            if (block.dataset.builderBlock === 'legacy') {
                return String(block.dataset.rawHtml || '');
            }

            var clone = block.cloneNode(true);

            Array.prototype.forEach.call(clone.querySelectorAll('.mb-block-toolbar'), function (node) {
                node.parentNode.removeChild(node);
            });
            Array.prototype.forEach.call(clone.querySelectorAll('.mb-slot-placeholder'), function (node) {
                node.parentNode.removeChild(node);
            });

            Array.prototype.forEach.call(clone.querySelectorAll('.mb-column-slot'), function (slot) {
                var fragment = document.createDocumentFragment();
                while (slot.firstChild) {
                    fragment.appendChild(slot.firstChild);
                }
                slot.parentNode.replaceChild(fragment, slot);
            });

            Array.prototype.forEach.call(clone.querySelectorAll('*'), function (node) {
                node.removeAttribute('contenteditable');
                node.removeAttribute('draggable');

                Array.prototype.slice.call(node.attributes).forEach(function (attribute) {
                    if (attribute.name.indexOf('data-') === 0) {
                        node.removeAttribute(attribute.name);
                    }
                });

                if (node.className) {
                    node.className = String(node.className)
                        .split(/\s+/)
                        .filter(function (className) {
                            return className && className.indexOf('mb-') !== 0 && className.indexOf('marketing-builder-') !== 0 && className !== 'is-active' && className !== 'is-over';
                        })
                        .join(' ');
                }
            });

            clone.removeAttribute('draggable');
            clone.removeAttribute('data-builder-block');
            clone.removeAttribute('data-bound-block');
            clone.className = '';

            return clone.innerHTML;
        }

        function renderEmailHtml() {
            var blockHtml = '';
            Array.prototype.forEach.call(canvas.querySelectorAll(':scope > .mb-block'), function (block) {
                blockHtml += serializeBlock(block);
            });

            return ''
                + '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
                + '<body style="margin:0;padding:0;background:' + state.global.emailBackground + ';">'
                + '<center style="width:100%;background:' + state.global.emailBackground + ';padding:24px 0;">'
                + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:' + state.global.emailBackground + ';">'
                + '<tr><td style="padding:0 16px;color:' + state.global.textColor + ';">' + blockHtml + '</td></tr>'
                + '</table>'
                + '</center></body></html>';
        }

        function serializeBuilderState() {
            var holder = document.createElement('div');

            Array.prototype.forEach.call(canvas.querySelectorAll(':scope > .mb-block'), function (block) {
                var clone = block.cloneNode(true);

                Array.prototype.forEach.call(clone.querySelectorAll('.mb-block-toolbar'), function (node) {
                    node.parentNode.removeChild(node);
                });

                Array.prototype.forEach.call(clone.querySelectorAll('.is-active, .is-over'), function (node) {
                    node.classList.remove('is-active');
                    node.classList.remove('is-over');
                });

                clone.removeAttribute('data-bound-block');
                clone.removeAttribute('draggable');

                Array.prototype.forEach.call(clone.querySelectorAll('[data-bound-block]'), function (node) {
                    node.removeAttribute('data-bound-block');
                    node.removeAttribute('draggable');
                });

                holder.appendChild(clone);
            });

            return holder.innerHTML;
        }

        function syncFields() {
            var html = renderEmailHtml();
            var generatedText;
            htmlField.value = html;
            if (builderStateField) {
                builderStateField.value = serializeBuilderState();
            }
            if (textField) {
                if (isUnsafeDefaultPlainText(textField.value)) {
                    textField.value = '';
                }

                if (!String(textField.value || '').trim()) {
                    generatedText = html.replace(/<style[\s\S]*?<\/style>/gi, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                    if (!isUnsafeDefaultPlainText(generatedText)) {
                        textField.value = generatedText;
                    }
                }
            }
            updateUnsubscribeStatus();
        }

        function getPaletteIcon(icon) {
            var icons = {
                heading: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 6v12M19 6v12M5 12h14"/></svg>',
                text: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 7h14M5 12h10M5 17h14"/></svg>',
                image: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="5" width="16" height="14" rx="2"/><path d="m8 14 2-2 4 4 2-2 2 2"/><circle cx="9" cy="9" r="1.5"/></svg>',
                button: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="7" width="16" height="10" rx="5"/><path d="M9 12h6"/></svg>',
                divider: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 12h14"/></svg>',
                spacer: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 7v10M17 7v10M7 12h10"/></svg>',
                icon: '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="m12 3 2.6 5.2L20 9l-4 3.9.9 5.6L12 16l-4.9 2.5.9-5.6L4 9l5.4-.8L12 3Z"/></svg>',
                hero: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="5" width="16" height="14" rx="2"/><path d="M7 9h10M7 13h6"/></svg>',
                section: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="5" width="16" height="14" rx="2"/><path d="M8 9h8M8 13h8"/></svg>',
                columns2: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="5" width="7" height="14" rx="1.5"/><rect x="13" y="5" width="7" height="14" rx="1.5"/></svg>',
                columns3: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3.5" y="5" width="5" height="14" rx="1.2"/><rect x="9.5" y="5" width="5" height="14" rx="1.2"/><rect x="15.5" y="5" width="5" height="14" rx="1.2"/></svg>',
                footer: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="5" width="16" height="14" rx="2"/><path d="M4 15h16"/></svg>',
                logo: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="5"/><path d="M12 4v3M12 17v3M4 12h3M17 12h3"/></svg>',
                imageBox: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="5" width="16" height="14" rx="2"/><path d="M4 14h16"/></svg>',
                qr: '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM12 12h2v2h-2zM16 12h4v2h-2v2h2v4h-2v-2h-2v2h-2v-6h2z"/></svg>',
                gallery: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="6" width="5" height="12" rx="1"/><rect x="10" y="6" width="5" height="12" rx="1"/><rect x="16" y="6" width="4" height="12" rx="1"/></svg>',
                cta: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 12h10"/><path d="m12 7 5 5-5 5"/></svg>',
                list: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 7h10M9 12h10M9 17h10"/><circle cx="5.5" cy="7" r="1"/><circle cx="5.5" cy="12" r="1"/><circle cx="5.5" cy="17" r="1"/></svg>',
                quote: '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M7.5 11A3.5 3.5 0 1 1 11 7.5c0 3-2 4.5-4.5 5M16.5 11A3.5 3.5 0 1 1 20 7.5c0 3-2 4.5-4.5 5"/></svg>',
                coupon: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 8h14v8H5z"/><path d="m10 8 4 8"/></svg>',
                social: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="6" cy="12" r="2"/><circle cx="12" cy="7" r="2"/><circle cx="18" cy="12" r="2"/><path d="M7.7 10.8 10.3 8.2M13.7 8.2l2.6 2.6"/></svg>',
                unsubscribe: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16v10H4z"/><path d="m4 8 8 6 8-6"/></svg>',
                address: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20s6-5.3 6-10a6 6 0 1 0-12 0c0 4.7 6 10 6 10Z"/><circle cx="12" cy="10" r="2"/></svg>'
            };
            return icons[icon] || icons.text;
        }

        function renderPalette() {
            var hasSearch = !!state.paletteSearch;

            palette.innerHTML = '';
            palette.appendChild(createElement(renderSidebarLoadTemplateSection()));

            paletteGroups.forEach(function (group) {
                var filteredBlocks = group.blocks.filter(function (blockDef) {
                    if (!hasSearch) {
                        return true;
                    }
                    var haystack = (blockDef.label + ' ' + group.title).toLowerCase();
                    return haystack.indexOf(state.paletteSearch) !== -1;
                });

                if (!filteredBlocks.length) {
                    return;
                }

                var groupNode = createElement('<div class="marketing-builder-palette-group' + ((hasSearch || state.openCategory === group.id) ? ' is-open' : '') + '" data-category="' + escapeHtml(group.id) + '"></div>');
                groupNode.appendChild(createElement('<button type="button" class="marketing-builder-palette-group-title" data-category-toggle="' + escapeHtml(group.id) + '">' + escapeHtml(group.title) + '</button>'));
                var grid = createElement('<div class="marketing-builder-palette-grid"></div>');

                filteredBlocks.forEach(function (blockDef) {
                    var blockButton = createElement(
                        '<button type="button" class="marketing-builder-palette-item" draggable="true" data-block-type="' + escapeHtml(blockDef.type) + '">'
                        + '<span class="marketing-builder-palette-item-icon">' + getPaletteIcon(blockDef.icon) + '</span>'
                        + '<span class="marketing-builder-palette-item-label">' + escapeHtml(blockDef.label) + '</span>'
                        + '</button>'
                    );

                    blockButton.addEventListener('click', function () {
                        insertBlockAtPlacement(createBlock(blockDef.type), { container: canvas, beforeNode: null });
                    });

                    blockButton.addEventListener('dragstart', function (event) {
                        state.draggingNewType = blockDef.type;
                        state.draggingBlock = null;
                        event.dataTransfer.effectAllowed = 'copy';
                        event.dataTransfer.setData('text/plain', blockDef.type);
                    });

                    blockButton.addEventListener('dragend', function () {
                        state.draggingNewType = '';
                        clearDropIndicator();
                    });

                    grid.appendChild(blockButton);
                });

                groupNode.appendChild(grid);
                palette.appendChild(groupNode);
            });

            palette.appendChild(createElement(renderSidebarComplianceSection()));
            palette.appendChild(createElement(renderSidebarMergeTagsSection()));
        }

        function duplicateActiveBlock() {
            if (!state.activeBlock) {
                return;
            }

            var clone = state.activeBlock.cloneNode(true);
            clone.classList.remove('is-active');
            delete clone.dataset.boundBlock;
            state.activeBlock.parentNode.insertBefore(clone, state.activeBlock.nextElementSibling);
            refreshCanvasState();
            setActiveBlock(clone);
            syncFields();
        }

        function moveActiveBlock(direction) {
            if (!state.activeBlock) {
                return;
            }

            var sibling = direction < 0 ? state.activeBlock.previousElementSibling : state.activeBlock.nextElementSibling;
            if (!sibling || !sibling.classList.contains('mb-block')) {
                return;
            }

            if (direction < 0) {
                state.activeBlock.parentNode.insertBefore(state.activeBlock, sibling);
            } else {
                state.activeBlock.parentNode.insertBefore(sibling, state.activeBlock);
            }

            refreshCanvasState();
            setActiveBlock(state.activeBlock);
            syncFields();
        }

        function removeActiveBlock() {
            if (!state.activeBlock) {
                return;
            }

            if (state.activeBlock.dataset.builderBlock === 'unsubscribe' && !window.confirm(strings.removeUnsubscribeConfirm || 'Remove unsubscribe link?')) {
                return;
            }

            var parent = state.activeBlock.parentNode;
            parent.removeChild(state.activeBlock);
            refreshCanvasState();
            setActiveBlock(canvas.querySelector('.mb-block'));
            syncFields();
        }

        function getControlValue(node, fallback) {
            return node ? node.value : fallback;
        }

        function renderGlobalControls() {
            return ''
                + '<hr>'
                + '<h5 class="marketing-builder-settings-section-title">' + escapeHtml(strings.globalSettings || 'Global Settings') + '</h5>'
                + renderColorRow([
                    { label: strings.emailBackground || 'Email Background', globalControl: 'emailBackground', value: state.global.emailBackground },
                    { label: strings.linkColor || 'Link Color', globalControl: 'linkColor', value: state.global.linkColor }
                ]);
        }

        function renderColorControl(options) {
            var attr = options.globalControl ? 'data-global-control="' + escapeHtml(options.globalControl) + '"' : 'data-control="' + escapeHtml(options.control) + '"';
            return ''
                + '<div class="marketing-builder-color-field">'
                + '<label>' + escapeHtml(options.label) + '</label>'
                + '<input type="color" class="marketing-builder-color-swatch" ' + attr + ' value="' + escapeHtml(options.value || '#ffffff') + '">'
                + '</div>';
        }

        function renderColorRow(items) {
            return '<div class="marketing-builder-color-row">' + items.map(renderColorControl).join('') + '</div>';
        }

        function renderFontFamilyOptions(currentValue) {
            var current = String(currentValue || '').replace(/['"]/g, '').split(',')[0].trim() || 'Arial';
            return EMAIL_FONT_OPTIONS.map(function (font) {
                return '<option value="' + escapeHtml(font) + '"' + (current.toLowerCase() === font.toLowerCase() ? ' selected' : '') + '>' + escapeHtml(font) + '</option>';
            }).join('');
        }

        function getTypographyControls(styleSource, prefix, heading) {
            var currentWeight = String(parseInt(styleSource.fontWeight, 10) || 400);
            var currentSize = String(parseInt(styleSource.fontSize, 10) || 16);
            var currentLineHeight = String(parseFloat(styleSource.lineHeight) || 1.6);
            var currentColor = toHex(styleSource.color || '', '#0f172a');
            var html = '';

            if (heading) {
                html += '<h5 class="marketing-builder-settings-section-title">' + escapeHtml(heading) + '</h5>';
            }

            html += '<div class="row marketing-builder-typography-row">'
                + '<div class="col-sm-12"><div class="form-group"><label>' + escapeHtml(strings.fontFamily || 'Font Family') + '</label><select class="form-control" data-control="' + prefix + '-font-family">' + renderFontFamilyOptions(styleSource.fontFamily) + '</select></div></div>'
                + '</div>';
            html += '<div class="row marketing-builder-typography-row">'
                + '<div class="col-sm-4"><div class="form-group"><label>' + escapeHtml(strings.fontSize || 'Font Size') + '</label><input type="number" class="form-control" data-control="' + prefix + '-font-size" value="' + escapeHtml(currentSize) + '"></div></div>'
                + '<div class="col-sm-4"><div class="form-group"><label>' + escapeHtml(strings.fontWeight || 'Font Weight') + '</label><select class="form-control" data-control="' + prefix + '-font-weight"><option value="300"' + (currentWeight === '300' ? ' selected' : '') + '>300</option><option value="400"' + (currentWeight === '400' ? ' selected' : '') + '>400</option><option value="500"' + (currentWeight === '500' ? ' selected' : '') + '>500</option><option value="600"' + (currentWeight === '600' ? ' selected' : '') + '>600</option><option value="700"' + (currentWeight === '700' ? ' selected' : '') + '>700</option><option value="800"' + (currentWeight === '800' ? ' selected' : '') + '>800</option></select></div></div>'
                + '<div class="col-sm-4"><div class="form-group"><label>' + escapeHtml(strings.lineHeight || 'Line Height') + '</label><input type="number" step="0.1" class="form-control" data-control="' + prefix + '-line-height" value="' + escapeHtml(currentLineHeight) + '"></div></div>'
                + '</div>';
            html += renderColorRow([{ label: strings.textColor || 'Text', control: prefix + '-font-color', value: currentColor }]);
            return html;
        }

        function getNodeStyle(node, fallbackStyle) {
            return node ? window.getComputedStyle(node) : fallbackStyle;
        }

        function resolveTypographyNodes(block, prefix) {
            var map = {
                surface: function () {
                    return [getSurface(block)];
                },
                heading: function () {
                    var node = block.querySelector('.mb-heading-text');
                    return node ? [node] : [];
                },
                body: function () {
                    var node = block.querySelector('.mb-richtext') || block.querySelector('.mb-company-address-text') || block.querySelector('.mb-footer-text');
                    return node ? [node] : [];
                },
                caption: function () {
                    var node = block.querySelector('.mb-image-caption');
                    return node ? [node] : [];
                },
                heroHeadline: function () {
                    var node = block.querySelector('.mb-hero-headline');
                    return node ? [node] : [];
                },
                heroSubheadline: function () {
                    var node = block.querySelector('.mb-hero-subheadline');
                    return node ? [node] : [];
                },
                buttonText: function () {
                    var node = block.querySelector('.mb-button-link') || block.querySelector('.mb-cta-link');
                    return node ? [node] : [];
                },
                iconTitle: function () {
                    var node = block.querySelector('.mb-icon-title');
                    return node ? [node] : [];
                },
                iconDescription: function () {
                    var node = block.querySelector('.mb-icon-description');
                    return node ? [node] : [];
                },
                socialText: function () {
                    return Array.prototype.slice.call(block.querySelectorAll('.mb-social-link'));
                },
                qrCaption: function () {
                    var node = block.querySelector('.mb-qr-caption');
                    return node ? [node] : [];
                }
            };

            return map[prefix] ? map[prefix]() : [];
        }

        function applyTypographyValue(block, prefix, property, value) {
            var nodes = resolveTypographyNodes(block, prefix);
            Array.prototype.forEach.call(nodes, function (node) {
                var targets = [node];
                if (!node) {
                    return;
                }
                if (node.querySelectorAll) {
                    targets = targets.concat(Array.prototype.slice.call(node.querySelectorAll('p, ol, ul, li, span, strong, b, em, i, u, s, a, div, blockquote, h1, h2, h3')));
                }
                Array.prototype.forEach.call(targets, function (targetNode) {
                    if (property === 'fontFamily') {
                        targetNode.style.fontFamily = value || '';
                    } else if (property === 'fontSize') {
                        targetNode.style.fontSize = clampNumber(value, 8, 72, 16) + 'px';
                    } else if (property === 'fontWeight') {
                        targetNode.style.fontWeight = String(clampNumber(value, 300, 800, 400));
                    } else if (property === 'lineHeight') {
                        targetNode.style.lineHeight = Math.max(1, Math.min(3, parseFloat(value) || 1.6));
                    } else if (property === 'color') {
                        targetNode.style.color = value;
                    }
                });
            });
        }

        function renderHeroChildSelector(block) {
            var partMap = getHeroPartMap(block);
            var activeKey = state.activeHeroChild || '';
            var html = '<div class="marketing-builder-segmented-control">';

            getHeroChildOrder(block).forEach(function (key) {
                if (!partMap[key]) {
                    return;
                }

                html += '<button type="button" class="marketing-builder-segmented-control__button' + (activeKey === key ? ' is-active' : '') + '" data-action="select-hero-child" data-hero-child="' + escapeHtml(key) + '">'
                    + escapeHtml(getHeroChildLabel(key))
                    + (isHeroPartVisible(partMap[key]) ? '' : ' (' + escapeHtml(strings.hidden || 'Hidden') + ')')
                    + '</button>';
            });

            html += '</div>';
            return html;
        }

        function renderHeroContentTab(block) {
            var partMap = getHeroPartMap(block);
            var activeKey = state.activeHeroChild || '';
            var imageNode = block.querySelector('.mb-hero-image');
            var imageLink = block.querySelector('.mb-hero-image-link');
            var headlineNode = block.querySelector('.mb-hero-headline');
            var subheadlineNode = block.querySelector('.mb-hero-subheadline');
            var buttonNode = block.querySelector('.mb-cta-link');
            var buttonLabelNode = block.querySelector('.mb-cta-label');
            var html = renderHeroChildSelector(block);

            if (activeKey) {
                var actionLabel = isHeroPartVisible(partMap[activeKey]) ? (strings.hide || 'Hide') : (strings.restore || 'Restore');
                html += '<div class="marketing-builder-inline-actions">'
                    + '<button type="button" class="btn btn-default btn-sm" data-action="toggle-hero-child" data-hero-child="' + escapeHtml(activeKey) + '">' + escapeHtml(actionLabel + ' ' + getHeroChildLabel(activeKey)) + '</button>';
                if (activeKey === 'image') {
                    html += '<button type="button" class="btn btn-default btn-sm" data-action="upload-image-inline">' + escapeHtml(strings.uploadImage || 'Upload Image') + '</button>';
                }
                html += '<button type="button" class="btn btn-default btn-sm" data-action="move-hero-child-up" data-hero-child="' + escapeHtml(activeKey) + '">' + escapeHtml(strings.moveUp || 'Move Up') + '</button>'
                    + '<button type="button" class="btn btn-default btn-sm" data-action="move-hero-child-down" data-hero-child="' + escapeHtml(activeKey) + '">' + escapeHtml(strings.moveDown || 'Move Down') + '</button>'
                    + '</div>';
            }

            html += '<div class="marketing-builder-hero-restore-list">';
            getHeroChildOrder(block).forEach(function (key) {
                if (!partMap[key] || isHeroPartVisible(partMap[key])) {
                    return;
                }

                html += '<button type="button" class="btn btn-default btn-sm" data-action="restore-hero-child" data-hero-child="' + escapeHtml(key) + '">' + escapeHtml((strings.addRestore || 'Add / Restore') + ' ' + getHeroChildLabel(key)) + '</button>';
            });
            html += '</div>';

            if (!activeKey || activeKey === 'image') {
                html += '<h5 class="marketing-builder-settings-section-title">' + escapeHtml(strings.heroImage || 'Hero Image') + '</h5>'
                    + '<div class="form-group"><label>' + escapeHtml(strings.imageUrl || 'Image URL') + '</label><input type="text" class="form-control" data-control="inline-image-url" value="' + escapeHtml(imageNode ? (imageNode.getAttribute('src') || '') : '') + '"></div>'
                    + '<div class="form-group"><label>' + escapeHtml(strings.altText || 'Alt Text') + '</label><input type="text" class="form-control" data-control="inline-image-alt" value="' + escapeHtml(imageNode ? (imageNode.getAttribute('alt') || '') : '') + '"></div>'
                    + '<div class="form-group"><label>' + escapeHtml(strings.linkUrl || 'Link URL') + '</label><input type="text" class="form-control" data-control="inline-image-link" value="' + escapeHtml(imageLink ? (imageLink.getAttribute('href') || '') : '') + '"></div>';
            }
            if (!activeKey || activeKey === 'headline') {
                html += '<h5 class="marketing-builder-settings-section-title">' + escapeHtml(strings.heroHeadline || 'Hero Headline') + '</h5>'
                    + '<div class="form-group"><label>' + escapeHtml(strings.headline || 'Headline') + '</label><textarea class="form-control" rows="3" data-control="hero-headline">' + escapeHtml(headlineNode ? headlineNode.textContent : '') + '</textarea></div>';
            }
            if (!activeKey || activeKey === 'subheadline') {
                html += '<h5 class="marketing-builder-settings-section-title">' + escapeHtml(strings.heroSubheadline || 'Hero Subheadline') + '</h5>'
                    + '<div class="form-group"><label>' + escapeHtml(strings.subheadline || 'Subheadline') + '</label><textarea class="form-control" rows="4" data-control="hero-subheadline">' + escapeHtml(subheadlineNode ? subheadlineNode.textContent : '') + '</textarea></div>';
            }
            if (!activeKey || activeKey === 'button') {
                html += '<h5 class="marketing-builder-settings-section-title">' + escapeHtml(strings.heroButton || 'Hero Button') + '</h5>'
                    + '<div class="form-group"><label>' + escapeHtml(strings.buttonLabel || 'Button Label') + '</label><input type="text" class="form-control" data-control="generic-button-label" value="' + escapeHtml(buttonLabelNode ? buttonLabelNode.textContent : '') + '"></div>'
                    + '<div class="form-group"><label>' + escapeHtml(strings.linkUrl || 'Link URL') + '</label><input type="text" class="form-control" data-control="generic-button-url" value="' + escapeHtml(buttonNode ? (buttonNode.getAttribute('href') || '') : '') + '"></div>';
            }

            return html;
        }

        function renderHeroStyleTab(block, surfaceStyle, imageNode, imageStyle) {
            var activeKey = state.activeHeroChild || '';
            var buttonNode = block.querySelector('.mb-cta-link');
            var html = '';

            if (!activeKey || activeKey === 'image') {
                html += '<h5 class="marketing-builder-settings-section-title">' + escapeHtml(strings.heroImage || 'Hero Image') + '</h5>'
                    + '<div class="row"><div class="col-sm-6"><div class="form-group"><label>' + escapeHtml(strings.width || 'Width') + '</label><input type="number" class="form-control" data-control="inline-image-width" value="' + escapeHtml(String(imageNode && imageNode.style.width ? parseInt(imageNode.style.width, 10) : 320)) + '"></div></div><div class="col-sm-6"><div class="form-group"><label>' + escapeHtml(strings.height || 'Height') + '</label><input type="number" class="form-control" data-control="inline-image-height" value="' + escapeHtml(String(imageNode && imageNode.style.height && imageNode.style.height !== 'auto' ? parseInt(imageNode.style.height, 10) : 0)) + '"></div></div></div>'
                    + '<div class="form-group"><label>' + escapeHtml(strings.align || 'Align') + '</label><select class="form-control" data-control="inline-image-align"><option value="left"' + (surfaceStyle.textAlign === 'left' ? ' selected' : '') + '>' + escapeHtml(strings.alignLeft || 'Left') + '</option><option value="center"' + (surfaceStyle.textAlign === 'center' ? ' selected' : '') + '>' + escapeHtml(strings.alignCenter || 'Center') + '</option><option value="right"' + (surfaceStyle.textAlign === 'right' ? ' selected' : '') + '>' + escapeHtml(strings.alignRight || 'Right') + '</option></select></div>'
                    + '<div class="form-group"><label>' + escapeHtml(strings.objectFit || 'Image Fit') + '</label><select class="form-control" data-control="inline-image-fit"><option value="contain">Contain</option><option value="cover"' + (imageStyle && imageStyle.objectFit === 'cover' ? ' selected' : '') + '>Cover</option><option value="fill"' + (imageStyle && imageStyle.objectFit === 'fill' ? ' selected' : '') + '>Fill</option></select></div>'
                    + '<div class="form-group"><label>' + escapeHtml(strings.borderRadius || 'Border Radius') + '</label><input type="number" class="form-control" data-control="inline-image-radius" value="' + escapeHtml(String(imageNode && imageNode.style.borderRadius ? parseInt(imageNode.style.borderRadius, 10) : 18)) + '"></div>';
            }
            if (!activeKey || activeKey === 'headline') {
                html += getTypographyControls(getNodeStyle(block.querySelector('.mb-hero-headline'), surfaceStyle), 'heroHeadline', strings.heroHeadline || 'Hero Headline');
            }
            if (!activeKey || activeKey === 'subheadline') {
                html += getTypographyControls(getNodeStyle(block.querySelector('.mb-hero-subheadline'), surfaceStyle), 'heroSubheadline', strings.heroSubheadline || 'Hero Subheadline');
            }
            if (!activeKey || activeKey === 'button') {
                html += getTypographyControls(getNodeStyle(buttonNode, surfaceStyle), 'buttonText', strings.heroButton || 'Hero Button');
                html += renderColorRow([
                    { label: strings.buttonColor || 'Button Color', control: 'generic-button-color', value: toHex(buttonNode ? buttonNode.style.backgroundColor : '', '#2563eb') },
                    { label: strings.buttonText || 'Button Text', control: 'generic-button-text-color', value: toHex(buttonNode ? buttonNode.style.color : '', '#ffffff') }
                ]);
            }

            return html;
        }

        function renderProperties() {
            var block = state.activeBlock;
            var html = '';

            if (!block) {
                destroySidebarTextEditor();
                properties.innerHTML = '<div class="marketing-builder-settings-empty"><p class="marketing-builder-help no-margin">' + escapeHtml(strings.selectBlock || 'Select a block to edit it.') + '</p></div>'
                    + renderGlobalControls();
                return;
            }

            var surface = getSurface(block);
            var borderTarget = getBorderTarget(block) || surface;
            var surfaceStyle = window.getComputedStyle(surface);
            var borderStyle = window.getComputedStyle(borderTarget);
            var blockStyle = window.getComputedStyle(block);
            var type = block.dataset.builderBlock;
            var imageNode = getImageNode(block);
            var imageStyle = imageNode ? window.getComputedStyle(imageNode) : null;
            var contentTab = '';
            var styleTab = '';
            var advancedTab = '';
            var headingStyleNode = block.querySelector('.mb-heading-text');
            var bodyStyleNode = getBodySurface(block) || block.querySelector('.mb-hero-subheadline') || block.querySelector('.mb-image-caption');

            html += '<div class="marketing-builder-settings-header">'
                + '<button type="button" class="btn btn-default marketing-builder-back-button" data-action="back-to-elements">' + escapeHtml(strings.backToElements || '← Elements') + '</button>'
                + '<h4 class="marketing-builder-settings-title">' + escapeHtml((strings.editBlock || 'Edit %s').replace('%s', getInspectorLabel(block))) + '</h4>'
                + '<div class="marketing-builder-settings-subtitle">' + escapeHtml(getBlockLabel(block)) + '</div>'
                + '</div>';

            if (type === 'heading' || type === 'cta' || type === 'feature_list' || type === 'coupon' || type === 'testimonial' || type === 'inner_section' || type === 'image_box') {
                var headingNode = block.querySelector('.mb-heading-text') || block.querySelector('.mb-hero-headline');
                contentTab += '<div class="form-group"><label>' + escapeHtml(strings.headline || 'Headline') + '</label><textarea class="form-control" rows="3" data-control="generic-heading">' + escapeHtml(headingNode ? headingNode.textContent : '') + '</textarea></div>';
            }
            if (isSidebarRichTextBlockType(type)) {
                contentTab += '<div class="marketing-builder-sidebar-editor-shell" data-marketing-builder-rich-text-editor="1">'
                    + '<label class="marketing-builder-settings-help-title">' + escapeHtml(strings.text || 'Text') + '</label>'
                    + '<div class="marketing-builder-quill-shell" data-marketing-builder-quill-shell="1">'
                    + '<div class="marketing-builder-quill-editor" data-marketing-builder-quill-editor="1"></div>'
                    + '</div>'
                    + '<div class="marketing-builder-sidebar-editor-fallback-shell hide" data-marketing-builder-textarea-fallback-shell="1">'
                    + '<label class="marketing-builder-settings-help-title">' + escapeHtml(strings.richTextEditorFallback || 'Fallback Text Editor') + '</label>'
                    + '<textarea class="form-control marketing-builder-sidebar-editor-fallback" rows="10" data-marketing-builder-textarea-fallback="1"></textarea>'
                    + '<p class="marketing-builder-settings-note no-margin">' + escapeHtml(strings.richTextEditorUnavailable || 'Rich text editor unavailable. Plain text fallback is active.') + '</p>'
                    + '</div>'
                    + '<p class="marketing-builder-settings-note">' + escapeHtml(strings.richTextHelp || 'Use the editor below to keep paragraphs, lists, and links clean for email clients.') + '</p>';

                if (mergeTags.length) {
                    contentTab += '<div class="marketing-builder-settings-note-card"><strong>' + escapeHtml(strings.mergeTags || 'Merge Tags') + '</strong>'
                        + '<p class="marketing-builder-settings-note no-margin">' + escapeHtml(strings.personalizeHelp || 'Insert personalization placeholders at the current cursor position inside the selected block.') + '</p>'
                        + renderMergeTagButtons()
                        + '</div>';
                }

                if (block && (block.dataset.builderBlock === 'company_address' || block.dataset.builderBlock === 'footer' || block.dataset.builderBlock === 'email_footer')) {
                    contentTab += '<div class="marketing-builder-settings-note-card">'
                        + '<strong>' + escapeHtml(strings.companyAddressBlock || 'Company Address') + '</strong>'
                        + '<p class="marketing-builder-settings-note no-margin">' + escapeHtml(companyAddressConfig.configured ? (strings.companyAddressSavedConfigured || 'Saved company address settings are available for this block.') : (strings.companyAddressSavedMissing || 'Saved company address settings are not configured yet.')) + '</p>'
                        + '<button type="button" class="btn btn-default btn-sm mtop10" data-action="use-saved-company-address">' + escapeHtml(strings.companyAddressUseSaved || 'Use Saved Company Address') + '</button>'
                        + '</div>';
                }

                contentTab += '</div>';
            }
            if (type === 'hero') {
                contentTab += renderHeroContentTab(block);
            }
            if (type === 'social') {
                var socialMeta = ensureSocialBlockStructure(block);
                contentTab += '<div class="form-group"><label>Facebook URL</label><input type="text" class="form-control" data-control="social-facebook" value="' + escapeHtml(socialMeta ? (socialMeta.getAttribute('data-facebook') || '') : '') + '"></div>';
                contentTab += '<div class="form-group"><label>Instagram URL</label><input type="text" class="form-control" data-control="social-instagram" value="' + escapeHtml(socialMeta ? (socialMeta.getAttribute('data-instagram') || '') : '') + '"></div>';
                contentTab += '<div class="form-group"><label>X / Twitter URL</label><input type="text" class="form-control" data-control="social-twitter" value="' + escapeHtml(socialMeta ? (socialMeta.getAttribute('data-twitter') || '') : '') + '"></div>';
                contentTab += '<div class="form-group"><label>YouTube URL</label><input type="text" class="form-control" data-control="social-youtube" value="' + escapeHtml(socialMeta ? (socialMeta.getAttribute('data-youtube') || '') : '') + '"></div>';
                contentTab += '<div class="form-group"><label>LinkedIn URL</label><input type="text" class="form-control" data-control="social-linkedin" value="' + escapeHtml(socialMeta ? (socialMeta.getAttribute('data-linkedin') || '') : '') + '"></div>';
                contentTab += '<div class="form-group"><label>Website URL</label><input type="text" class="form-control" data-control="social-website" value="' + escapeHtml(socialMeta ? (socialMeta.getAttribute('data-website') || '') : '') + '"></div>';
            }
            if (type === 'icon') {
                var iconLink = getIconLinkNode(block);
                contentTab += '<div class="form-group"><label>Icon Symbol</label><input type="text" class="form-control" data-control="icon-symbol" value="' + escapeHtml((block.querySelector('.mb-icon-symbol') || {}).textContent || '') + '"></div>';
                contentTab += '<div class="form-group"><label>Title</label><input type="text" class="form-control" data-control="icon-title" value="' + escapeHtml((block.querySelector('.mb-icon-title') || {}).textContent || '') + '"></div>';
                contentTab += '<div class="form-group"><label>Description</label><textarea class="form-control" rows="4" data-control="icon-description">' + escapeHtml((block.querySelector('.mb-icon-description') || {}).textContent || '') + '</textarea></div>';
                contentTab += '<div class="form-group"><label>' + escapeHtml(strings.linkUrl || 'Link URL') + '</label><input type="text" class="form-control" data-control="icon-link" value="' + escapeHtml(iconLink ? (iconLink.getAttribute('href') || '') : '') + '"></div>';
            }
            if (type === 'button' || type === 'cta') {
                var buttonLink = block.querySelector('.mb-button-link') || block.querySelector('.mb-cta-link');
                var buttonLabel = block.querySelector('.mb-button-label') || block.querySelector('.mb-cta-label');
                contentTab += '<div class="form-group"><label>' + escapeHtml(strings.buttonLabel || 'Button Label') + '</label><input type="text" class="form-control" data-control="generic-button-label" value="' + escapeHtml(buttonLabel ? buttonLabel.textContent : '') + '"></div>';
                contentTab += '<div class="form-group"><label>' + escapeHtml(strings.linkUrl || 'Link URL') + '</label><input type="text" class="form-control" data-control="generic-button-url" value="' + escapeHtml(buttonLink ? buttonLink.getAttribute('href') : '') + '"></div>';
            }
            if (type === 'image' || type === 'logo' || type === 'image_box') {
                var linkNode = getImageLinkNode(block);
                contentTab += '<div class="form-group"><label>' + escapeHtml(strings.imageUrl || 'Image URL') + '</label><input type="text" class="form-control" data-control="inline-image-url" value="' + escapeHtml(imageNode ? (imageNode.getAttribute('src') || '') : '') + '"></div>';
                contentTab += '<div class="form-group"><button type="button" class="btn btn-default btn-block" data-action="upload-image-inline">' + escapeHtml(strings.uploadImage || 'Upload Image') + '</button></div>';
                contentTab += '<div class="form-group"><label>' + escapeHtml(strings.altText || 'Alt Text') + '</label><input type="text" class="form-control" data-control="inline-image-alt" value="' + escapeHtml(imageNode ? (imageNode.getAttribute('alt') || '') : '') + '"></div>';
                contentTab += '<div class="form-group"><label>' + escapeHtml(strings.linkUrl || 'Link URL') + '</label><input type="text" class="form-control" data-control="inline-image-link" value="' + escapeHtml(linkNode ? (linkNode.getAttribute('href') || '') : '') + '"></div>';
            }
            if (type === 'image' || type === 'image_box') {
                contentTab += '<div class="form-group"><label>' + escapeHtml(strings.caption || 'Caption') + '</label><input type="text" class="form-control" data-control="image-caption" value="' + escapeHtml((block.querySelector('.mb-image-caption') || {}).textContent || '') + '"></div>';
            }
            if (type === 'qr') {
                var meta = block.querySelector('.mb-qr-meta');
                contentTab += '<div class="form-group"><label>' + escapeHtml(strings.qrTarget || 'QR Target URL / Text') + '</label><input type="text" class="form-control" data-control="qr-target-inline" value="' + escapeHtml(meta ? (meta.getAttribute('data-qr-target') || '') : '') + '"></div>';
                contentTab += '<div class="form-group"><label>' + escapeHtml(strings.caption || 'Caption') + '</label><input type="text" class="form-control" data-control="qr-caption-inline" value="' + escapeHtml((block.querySelector('.mb-qr-caption') || {}).textContent || '') + '"></div>';
            }
            if (type === 'columns2' || type === 'columns3') {
                var selectedPreset = type === 'columns2' ? (block.dataset.columnPreset || '50/50') : (block.dataset.columnPreset || '33/33/33');
                var options = type === 'columns2' ? ['50/50', '60/40', '40/60'] : ['33/33/33', '50/25/25', '25/50/25', '25/25/50'];
                contentTab += '<div class="form-group"><label>' + escapeHtml(strings.columnPreset || 'Column Preset') + '</label><select class="form-control" data-control="column-preset">';
                options.forEach(function (option) {
                    contentTab += '<option value="' + escapeHtml(option) + '"' + (selectedPreset === option ? ' selected' : '') + '>' + escapeHtml(option) + '</option>';
                });
                contentTab += '</select></div>';
            }
            if (type === 'legacy') {
                contentTab += '<div class="alert alert-warning no-margin">' + escapeHtml(strings.legacyBlock || 'Legacy HTML block') + '. ' + escapeHtml(strings.selectBlock || 'Select a block to edit it.') + '</div>';
            }

            styleTab += '<div class="form-group"><label>' + escapeHtml(strings.align || 'Align') + '</label><select class="form-control" data-control="surface-text-align"><option value="left"' + (surfaceStyle.textAlign === 'left' ? ' selected' : '') + '>' + escapeHtml(strings.alignLeft || 'Left') + '</option><option value="center"' + (surfaceStyle.textAlign === 'center' ? ' selected' : '') + '>' + escapeHtml(strings.alignCenter || 'Center') + '</option><option value="right"' + (surfaceStyle.textAlign === 'right' ? ' selected' : '') + '>' + escapeHtml(strings.alignRight || 'Right') + '</option></select></div>';
            styleTab += renderColorRow([
                { label: strings.background || 'Background', control: 'surface-background', value: toHex(surfaceStyle.backgroundColor, '#ffffff') },
                { label: strings.textColor || 'Text', control: 'surface-text-color', value: toHex(surfaceStyle.color, '#0f172a') },
                { label: strings.linkColor || 'Link', control: 'surface-link-color', value: state.global.linkColor }
            ]);
            if (type === 'hero') {
                styleTab += renderHeroStyleTab(block, surfaceStyle, imageNode, imageStyle);
            } else {
                if (headingStyleNode && (type === 'heading' || type === 'cta' || type === 'feature_list' || type === 'coupon' || type === 'image_box')) {
                    styleTab += getTypographyControls(getNodeStyle(headingStyleNode, surfaceStyle), 'heading', 'Heading');
                }
                if (bodyStyleNode && (type === 'text' || type === 'testimonial' || type === 'inner_section' || type === 'company_address' || type === 'footer' || type === 'email_footer' || type === 'cta' || type === 'feature_list' || type === 'image_box')) {
                    styleTab += getTypographyControls(getNodeStyle(bodyStyleNode, surfaceStyle), 'body', type === 'text' ? 'Text' : 'Body');
                }
            }
            if (type === 'image' || type === 'image_box' || type === 'coupon' || type === 'testimonial' || type === 'qr') {
                var captionNode = block.querySelector('.mb-image-caption') || block.querySelector('.mb-qr-caption');
                var captionPrefix = type === 'qr' ? 'qrCaption' : 'caption';
                var captionTitle = type === 'qr' ? 'QR Caption' : 'Caption';
                if (captionNode) {
                    styleTab += getTypographyControls(getNodeStyle(captionNode, surfaceStyle), captionPrefix, captionTitle);
                }
            }
            if (type === 'icon') {
                var iconSymbolNode = block.querySelector('.mb-icon-symbol');
                styleTab += '<div class="row"><div class="col-sm-6"><div class="form-group"><label>Icon Size</label><input type="number" class="form-control" data-control="icon-size" value="' + escapeHtml(String(iconSymbolNode ? parseInt(window.getComputedStyle(iconSymbolNode).fontSize, 10) : 42)) + '"></div></div><div class="col-sm-6"><div class="form-group"><label>' + escapeHtml(strings.align || 'Align') + '</label><select class="form-control" data-control="surface-text-align"><option value="left"' + (surfaceStyle.textAlign === 'left' ? ' selected' : '') + '>Left</option><option value="center"' + (surfaceStyle.textAlign === 'center' ? ' selected' : '') + '>Center</option><option value="right"' + (surfaceStyle.textAlign === 'right' ? ' selected' : '') + '>Right</option></select></div></div></div>';
                styleTab += renderColorRow([{ label: 'Icon', control: 'icon-color', value: toHex(iconSymbolNode ? window.getComputedStyle(iconSymbolNode).color : '', '#2563eb') }]);
                styleTab += getTypographyControls(getNodeStyle(block.querySelector('.mb-icon-title'), surfaceStyle), 'iconTitle', 'Icon Title');
                styleTab += getTypographyControls(getNodeStyle(block.querySelector('.mb-icon-description'), surfaceStyle), 'iconDescription', 'Icon Description');
            }
            if (type === 'image' || type === 'logo' || type === 'image_box') {
                styleTab += '<div class="row"><div class="col-sm-6"><div class="form-group"><label>' + escapeHtml(strings.width || 'Width') + '</label><input type="number" class="form-control" data-control="inline-image-width" value="' + escapeHtml(String(imageNode && imageNode.style.width ? parseInt(imageNode.style.width, 10) : 320)) + '"></div></div><div class="col-sm-6"><div class="form-group"><label>' + escapeHtml(strings.height || 'Height') + '</label><input type="number" class="form-control" data-control="inline-image-height" value="' + escapeHtml(String(imageNode && imageNode.style.height && imageNode.style.height !== 'auto' ? parseInt(imageNode.style.height, 10) : 0)) + '"></div></div></div>';
                styleTab += '<div class="form-group"><label>' + escapeHtml(strings.align || 'Align') + '</label><select class="form-control" data-control="inline-image-align"><option value="left"' + (surfaceStyle.textAlign === 'left' ? ' selected' : '') + '>' + escapeHtml(strings.alignLeft || 'Left') + '</option><option value="center"' + (surfaceStyle.textAlign === 'center' ? ' selected' : '') + '>' + escapeHtml(strings.alignCenter || 'Center') + '</option><option value="right"' + (surfaceStyle.textAlign === 'right' ? ' selected' : '') + '>' + escapeHtml(strings.alignRight || 'Right') + '</option></select></div>';
                styleTab += '<div class="form-group"><label>' + escapeHtml(strings.objectFit || 'Image Fit') + '</label><select class="form-control" data-control="inline-image-fit"><option value="contain">Contain</option><option value="cover"' + (imageStyle && imageStyle.objectFit === 'cover' ? ' selected' : '') + '>Cover</option><option value="fill"' + (imageStyle && imageStyle.objectFit === 'fill' ? ' selected' : '') + '>Fill</option></select></div>';
                styleTab += '<div class="form-group"><label>' + escapeHtml(strings.borderRadius || 'Border Radius') + '</label><input type="number" class="form-control" data-control="inline-image-radius" value="' + escapeHtml(String(imageNode && imageNode.style.borderRadius ? parseInt(imageNode.style.borderRadius, 10) : 0)) + '"></div>';
            }
            if (type === 'button' || type === 'cta') {
                var styleButton = block.querySelector('.mb-button-link') || block.querySelector('.mb-cta-link');
                styleTab += getTypographyControls(getNodeStyle(styleButton, surfaceStyle), 'buttonText', 'Button Label');
                styleTab += renderColorRow([
                    { label: strings.buttonColor || 'Button Color', control: 'generic-button-color', value: toHex(styleButton ? styleButton.style.backgroundColor : '', '#2563eb') },
                    { label: 'Button Text', control: 'generic-button-text-color', value: toHex(styleButton ? styleButton.style.color : '', '#ffffff') }
                ]);
            }
            if (type === 'qr') {
                var qrMeta = block.querySelector('.mb-qr-meta');
                styleTab += '<div class="row"><div class="col-sm-4"><div class="form-group"><label>' + escapeHtml(strings.size || 'Size') + '</label><input type="number" class="form-control" data-control="qr-size-inline" value="' + escapeHtml(qrMeta ? (qrMeta.getAttribute('data-qr-size') || '180') : '180') + '"></div></div><div class="col-sm-4"><div class="form-group"><label>' + escapeHtml(strings.align || 'Align') + '</label><select class="form-control" data-control="qr-align-inline"><option value="left">Left</option><option value="center"' + (qrMeta && qrMeta.getAttribute('data-qr-align') === 'center' ? ' selected' : '') + '>Center</option><option value="right"' + (qrMeta && qrMeta.getAttribute('data-qr-align') === 'right' ? ' selected' : '') + '>Right</option></select></div></div><div class="col-sm-4"><div class="form-group"><label>' + escapeHtml(strings.borderRadius || 'Radius') + '</label><input type="number" class="form-control" data-control="qr-radius-inline" value="' + escapeHtml(qrMeta ? (qrMeta.getAttribute('data-qr-radius') || '16') : '16') + '"></div></div></div>';
                styleTab += renderColorRow([
                    { label: strings.foregroundColor || 'Foreground Color', control: 'qr-foreground-inline', value: qrMeta ? (qrMeta.getAttribute('data-qr-foreground') || '#111827') : '#111827' },
                    { label: strings.backgroundColor || 'Background Color', control: 'qr-background-inline', value: qrMeta ? (qrMeta.getAttribute('data-qr-background') || '#ffffff') : '#ffffff' }
                ]);
            }
            if (type === 'social') {
                styleTab += getTypographyControls(getNodeStyle(block.querySelector('.mb-social-link'), surfaceStyle), 'socialText', 'Social Text');
            }

            advancedTab += '<div class="row"><div class="col-sm-6"><div class="form-group"><label>' + escapeHtml(strings.marginTop || 'Margin Top') + '</label><input type="number" class="form-control" data-control="block-margin-top" value="' + escapeHtml(String(parseInt(blockStyle.marginTop, 10) || 0)) + '"></div></div><div class="col-sm-6"><div class="form-group"><label>' + escapeHtml(strings.marginBottom || 'Margin Bottom') + '</label><input type="number" class="form-control" data-control="block-margin-bottom" value="' + escapeHtml(String(parseInt(blockStyle.marginBottom, 10) || 0)) + '"></div></div></div>';
            advancedTab += '<div class="row"><div class="col-sm-6"><div class="form-group"><label>' + escapeHtml(strings.paddingTop || 'Padding Top') + '</label><input type="number" class="form-control" data-control="surface-padding-top" value="' + escapeHtml(String(parseInt(surfaceStyle.paddingTop, 10) || 0)) + '"></div></div><div class="col-sm-6"><div class="form-group"><label>' + escapeHtml(strings.paddingRight || 'Padding Right') + '</label><input type="number" class="form-control" data-control="surface-padding-right" value="' + escapeHtml(String(parseInt(surfaceStyle.paddingRight, 10) || 0)) + '"></div></div></div>';
            advancedTab += '<div class="row"><div class="col-sm-6"><div class="form-group"><label>' + escapeHtml(strings.paddingBottom || 'Padding Bottom') + '</label><input type="number" class="form-control" data-control="surface-padding-bottom" value="' + escapeHtml(String(parseInt(surfaceStyle.paddingBottom, 10) || 0)) + '"></div></div><div class="col-sm-6"><div class="form-group"><label>' + escapeHtml(strings.paddingLeft || 'Padding Left') + '</label><input type="number" class="form-control" data-control="surface-padding-left" value="' + escapeHtml(String(parseInt(surfaceStyle.paddingLeft, 10) || 0)) + '"></div></div></div>';
            advancedTab += '<div class="form-group"><label>Border Type</label><select class="form-control" data-control="surface-border-type"><option value="none"' + ((borderStyle.borderTopStyle === 'none' || parseInt(borderStyle.borderTopWidth, 10) === 0) ? ' selected' : '') + '>None</option><option value="solid"' + (borderStyle.borderTopStyle === 'solid' ? ' selected' : '') + '>Solid line</option><option value="double"' + (borderStyle.borderTopStyle === 'double' ? ' selected' : '') + '>Double line</option><option value="dashed"' + (borderStyle.borderTopStyle === 'dashed' ? ' selected' : '') + '>Dashed</option><option value="dotted"' + (borderStyle.borderTopStyle === 'dotted' ? ' selected' : '') + '>Dotted</option><option value="zigzag"' + (borderStyle.borderTopStyle === 'zigzag' ? ' selected' : '') + '>Zig Zag</option></select></div>';
            advancedTab += '<div class="row"><div class="col-sm-6"><div class="form-group"><label>' + escapeHtml(strings.borderWidth || 'Border Width') + '</label><input type="number" class="form-control" data-control="surface-border-width" value="' + escapeHtml(String(parseInt(borderStyle.borderTopWidth, 10) || 0)) + '"></div></div><div class="col-sm-6"><div class="form-group"><label>' + escapeHtml(strings.borderRadius || 'Border Radius') + '</label><input type="number" class="form-control" data-control="surface-border-radius" value="' + escapeHtml(String(parseInt(borderStyle.borderTopLeftRadius, 10) || 0)) + '"></div></div></div>';
            advancedTab += renderColorRow([{ label: strings.borderColor || 'Border Color', control: 'surface-border-color', value: toHex(borderStyle.borderTopColor, '#dbe4ee') }]);
            advancedTab += '<div class="form-group"><label>' + escapeHtml(strings.customLabel || 'Custom Label') + '</label><input type="text" class="form-control" data-control="custom-label" value="' + escapeHtml(block.getAttribute('data-custom-label') || '') + '"></div>';
            advancedTab += renderGlobalControls();

            html += '<div class="marketing-builder-settings-tabs">'
                + '<button type="button" class="marketing-builder-settings-tab' + (state.inspectorTab === 'content' ? ' is-active' : '') + '" data-inspector-tab="content">' + escapeHtml(strings.settingsContent || 'Content') + '</button>'
                + '<button type="button" class="marketing-builder-settings-tab' + (state.inspectorTab === 'style' ? ' is-active' : '') + '" data-inspector-tab="style">' + escapeHtml(strings.settingsStyle || 'Style') + '</button>'
                + '<button type="button" class="marketing-builder-settings-tab' + (state.inspectorTab === 'advanced' ? ' is-active' : '') + '" data-inspector-tab="advanced">' + escapeHtml(strings.settingsAdvanced || 'Advanced') + '</button>'
                + '</div>';
            html += '<div class="marketing-builder-settings-panel">';
            html += state.inspectorTab === 'style' ? styleTab : (state.inspectorTab === 'advanced' ? advancedTab : contentTab);
            html += '</div>';

            properties.innerHTML = html;
            initializeSidebarTextEditor(block);
        }

        function applySurfaceLinkColor(block, color) {
            Array.prototype.forEach.call(block.querySelectorAll('a'), function (link) {
                if (!link.classList.contains('mb-button-link') && !link.classList.contains('mb-cta-link')) {
                    link.style.color = color;
                }
            });
        }

        function updateColumnPreset(block, preset) {
            var isThree = block.dataset.builderBlock === 'columns3';
            var widths = preset.split('/');
            var cells = block.querySelectorAll('.mb-column-cell');
            block.dataset.columnPreset = preset;

            Array.prototype.forEach.call(cells, function (cell, index) {
                cell.setAttribute('width', widths[index] + '%');
            });

            if (!isThree && cells.length === 2) {
                return;
            }
        }

        function moveHeroChild(block, key, direction) {
            if (!block || block.dataset.builderBlock !== 'hero') {
                return;
            }

            var order = getHeroChildOrder(block);
            var index = order.indexOf(key);
            var swapIndex = index + direction;

            if (index === -1 || swapIndex < 0 || swapIndex >= order.length) {
                return;
            }

            order.splice(index, 1);
            order.splice(swapIndex, 0, key);
            block.dataset.heroOrder = order.join(',');
            syncHeroOrder(block);
        }

        function handlePropertyChange(target) {
            var block = state.activeBlock;
            if (!target) {
                return;
            }

            if (target.hasAttribute('data-global-control')) {
                state.global[target.getAttribute('data-global-control')] = target.value;
                applyGlobalCanvasStyles();
                syncFields();
                return;
            }

            if (!block) {
                return;
            }

            var surface = getSurface(block);
            var borderTarget = getBorderTarget(block) || surface;
            var control = target.getAttribute('data-control');
            var buttonNode = block.querySelector('.mb-button-link') || block.querySelector('.mb-cta-link');
            var buttonLabelNode = block.querySelector('.mb-button-label') || block.querySelector('.mb-cta-label');
            var headingNode = block.querySelector('.mb-heading-text') || block.querySelector('.mb-hero-headline');
            var bodyNode = getBodySurface(block) || block.querySelector('.mb-hero-subheadline') || block.querySelector('.mb-image-caption');
            var currentImageNode = getImageNode(block);
            var currentImageLinkNode = getImageLinkNode(block);
            var iconLinkNode = getIconLinkNode(block);
            var typographyPrefixes = {
                'surface-font-family': ['surface', 'fontFamily'],
                'surface-font-size': ['surface', 'fontSize'],
                'surface-font-weight': ['surface', 'fontWeight'],
                'surface-line-height': ['surface', 'lineHeight'],
                'surface-font-color': ['surface', 'color'],
                'heading-font-family': ['heading', 'fontFamily'],
                'heading-font-size': ['heading', 'fontSize'],
                'heading-font-weight': ['heading', 'fontWeight'],
                'heading-line-height': ['heading', 'lineHeight'],
                'heading-font-color': ['heading', 'color'],
                'body-font-family': ['body', 'fontFamily'],
                'body-font-size': ['body', 'fontSize'],
                'body-font-weight': ['body', 'fontWeight'],
                'body-line-height': ['body', 'lineHeight'],
                'body-font-color': ['body', 'color'],
                'caption-font-family': ['caption', 'fontFamily'],
                'caption-font-size': ['caption', 'fontSize'],
                'caption-font-weight': ['caption', 'fontWeight'],
                'caption-line-height': ['caption', 'lineHeight'],
                'caption-font-color': ['caption', 'color'],
                'heroHeadline-font-family': ['heroHeadline', 'fontFamily'],
                'heroHeadline-font-size': ['heroHeadline', 'fontSize'],
                'heroHeadline-font-weight': ['heroHeadline', 'fontWeight'],
                'heroHeadline-line-height': ['heroHeadline', 'lineHeight'],
                'heroHeadline-font-color': ['heroHeadline', 'color'],
                'heroSubheadline-font-family': ['heroSubheadline', 'fontFamily'],
                'heroSubheadline-font-size': ['heroSubheadline', 'fontSize'],
                'heroSubheadline-font-weight': ['heroSubheadline', 'fontWeight'],
                'heroSubheadline-line-height': ['heroSubheadline', 'lineHeight'],
                'heroSubheadline-font-color': ['heroSubheadline', 'color'],
                'buttonText-font-family': ['buttonText', 'fontFamily'],
                'buttonText-font-size': ['buttonText', 'fontSize'],
                'buttonText-font-weight': ['buttonText', 'fontWeight'],
                'buttonText-line-height': ['buttonText', 'lineHeight'],
                'buttonText-font-color': ['buttonText', 'color'],
                'iconTitle-font-family': ['iconTitle', 'fontFamily'],
                'iconTitle-font-size': ['iconTitle', 'fontSize'],
                'iconTitle-font-weight': ['iconTitle', 'fontWeight'],
                'iconTitle-line-height': ['iconTitle', 'lineHeight'],
                'iconTitle-font-color': ['iconTitle', 'color'],
                'iconDescription-font-family': ['iconDescription', 'fontFamily'],
                'iconDescription-font-size': ['iconDescription', 'fontSize'],
                'iconDescription-font-weight': ['iconDescription', 'fontWeight'],
                'iconDescription-line-height': ['iconDescription', 'lineHeight'],
                'iconDescription-font-color': ['iconDescription', 'color'],
                'socialText-font-family': ['socialText', 'fontFamily'],
                'socialText-font-size': ['socialText', 'fontSize'],
                'socialText-font-weight': ['socialText', 'fontWeight'],
                'socialText-line-height': ['socialText', 'lineHeight'],
                'socialText-font-color': ['socialText', 'color'],
                'qrCaption-font-family': ['qrCaption', 'fontFamily'],
                'qrCaption-font-size': ['qrCaption', 'fontSize'],
                'qrCaption-font-weight': ['qrCaption', 'fontWeight'],
                'qrCaption-line-height': ['qrCaption', 'lineHeight'],
                'qrCaption-font-color': ['qrCaption', 'color']
            };

            if (typographyPrefixes[control]) {
                applyTypographyValue(block, typographyPrefixes[control][0], typographyPrefixes[control][1], target.value);
            } else
            if (control === 'generic-heading') {
                if (headingNode) {
                    headingNode.textContent = target.value;
                }
            } else if (control === 'generic-body') {
                if (bodyNode) {
                    bodyNode.textContent = target.value;
                }
            } else if (control === 'generic-button-label') {
                if (buttonLabelNode) {
                    buttonLabelNode.textContent = target.value;
                }
            } else if (control === 'generic-button-url') {
                if (!isValidLink(target.value)) {
                    window.alert(strings.invalidLink || 'Enter a valid link that starts with http://, https://, mailto:, tel:, or {{unsubscribe_url}}.');
                    return;
                }
                if (buttonNode) {
                    buttonNode.setAttribute('href', target.value || '#');
                }
            } else if (control === 'generic-button-color') {
                if (buttonNode) {
                    buttonNode.style.backgroundColor = target.value;
                }
            } else if (control === 'generic-button-text-color') {
                if (buttonNode) {
                    buttonNode.style.color = target.value;
                }
            } else if (control === 'hero-headline') {
                block.querySelector('.mb-hero-headline').textContent = target.value;
            } else if (control === 'hero-subheadline') {
                block.querySelector('.mb-hero-subheadline').textContent = target.value;
            } else if (control === 'hero-button-label') {
                block.querySelector('.mb-cta-label').textContent = target.value;
            } else if (control === 'hero-button-url') {
                if (!isValidLink(target.value)) {
                    window.alert(strings.invalidLink || 'Enter a valid link that starts with http://, https://, mailto:, tel:, or {{unsubscribe_url}}.');
                    return;
                }
                block.querySelector('.mb-cta-link').setAttribute('href', target.value || '#');
            } else if (control === 'hero-button-color') {
                block.querySelector('.mb-cta-link').style.backgroundColor = target.value;
            } else if (control === 'image-caption') {
                block.querySelector('.mb-image-caption').textContent = target.value;
            } else if (control === 'button-label') {
                block.querySelector('.mb-button-label').textContent = target.value;
            } else if (control === 'button-url') {
                if (!isValidLink(target.value)) {
                    window.alert(strings.invalidLink || 'Enter a valid link that starts with http://, https://, mailto:, tel:, or {{unsubscribe_url}}.');
                    return;
                }
                block.querySelector('.mb-button-link').setAttribute('href', target.value || '#');
            } else if (control === 'button-color') {
                block.querySelector('.mb-button-link').style.backgroundColor = target.value;
            } else if (control === 'column-preset') {
                updateColumnPreset(block, target.value);
            } else if (control === 'surface-background') {
                surface.style.backgroundColor = target.value;
            } else if (control === 'surface-text-color') {
                surface.style.color = target.value;
            } else if (control === 'surface-link-color') {
                applySurfaceLinkColor(block, target.value);
            } else if (control === 'surface-text-align') {
                surface.style.textAlign = target.value;
            } else if (control === 'surface-font-size') {
                surface.style.fontSize = clampNumber(target.value, 8, 64, 16) + 'px';
            } else if (control === 'surface-font-weight') {
                surface.style.fontWeight = clampNumber(target.value, 100, 900, 400);
            } else if (control === 'surface-line-height') {
                surface.style.lineHeight = Math.max(1, Math.min(3, parseFloat(target.value) || 1.6));
            } else if (control === 'surface-padding-top') {
                surface.style.paddingTop = clampNumber(target.value, 0, 160, 0) + 'px';
            } else if (control === 'surface-padding-right') {
                surface.style.paddingRight = clampNumber(target.value, 0, 160, 0) + 'px';
            } else if (control === 'surface-padding-bottom') {
                surface.style.paddingBottom = clampNumber(target.value, 0, 160, 0) + 'px';
            } else if (control === 'surface-padding-left') {
                surface.style.paddingLeft = clampNumber(target.value, 0, 160, 0) + 'px';
            } else if (control === 'block-margin-top') {
                block.style.marginTop = clampNumber(target.value, 0, 160, 0) + 'px';
            } else if (control === 'block-margin-bottom') {
                block.style.marginBottom = clampNumber(target.value, 0, 160, 16) + 'px';
            } else if (control === 'surface-border-width') {
                var width = clampNumber(target.value, 0, 20, 0) + 'px';
                borderTarget.style.borderStyle = parseInt(width, 10) > 0 ? (borderTarget.style.borderStyle || 'solid') : 'none';
                borderTarget.style.borderWidth = width;
            } else if (control === 'surface-border-type') {
                var borderType = target.value === 'zigzag' ? 'dashed' : target.value;
                borderTarget.style.borderStyle = borderType;
            } else if (control === 'surface-border-radius') {
                borderTarget.style.borderRadius = clampNumber(target.value, 0, 60, 0) + 'px';
            } else if (control === 'surface-border-color') {
                borderTarget.style.borderColor = target.value;
            } else if (control === 'icon-symbol') {
                block.querySelector('.mb-icon-symbol').textContent = target.value;
            } else if (control === 'icon-title') {
                block.querySelector('.mb-icon-title').textContent = target.value;
            } else if (control === 'icon-description') {
                block.querySelector('.mb-icon-description').textContent = target.value;
            } else if (control === 'icon-link') {
                if (!isValidLink(target.value)) {
                    window.alert(strings.invalidLink || 'Enter a valid link that starts with http://, https://, mailto:, tel:, or {{unsubscribe_url}}.');
                    return;
                }
                if (iconLinkNode) {
                    iconLinkNode.setAttribute('href', target.value || '');
                    iconLinkNode.style.display = target.value ? 'inline-block' : 'none';
                }
            } else if (control === 'icon-size') {
                block.querySelector('.mb-icon-symbol').style.fontSize = clampNumber(target.value, 12, 96, 42) + 'px';
            } else if (control === 'icon-color') {
                block.querySelector('.mb-icon-symbol').style.color = target.value;
            } else if (control === 'social-facebook' || control === 'social-instagram' || control === 'social-twitter' || control === 'social-youtube' || control === 'social-linkedin' || control === 'social-website') {
                if (target.value && !isValidLink(target.value)) {
                    window.alert(strings.invalidLink || 'Enter a valid link that starts with http://, https://, mailto:, tel:, or {{unsubscribe_url}}.');
                    return;
                }
                var socialMeta = ensureSocialBlockStructure(block);
                updateSocialLinks(block, {
                    facebook: control === 'social-facebook' ? target.value : (socialMeta ? socialMeta.getAttribute('data-facebook') : ''),
                    instagram: control === 'social-instagram' ? target.value : (socialMeta ? socialMeta.getAttribute('data-instagram') : ''),
                    twitter: control === 'social-twitter' ? target.value : (socialMeta ? socialMeta.getAttribute('data-twitter') : ''),
                    youtube: control === 'social-youtube' ? target.value : (socialMeta ? socialMeta.getAttribute('data-youtube') : ''),
                    linkedin: control === 'social-linkedin' ? target.value : (socialMeta ? socialMeta.getAttribute('data-linkedin') : ''),
                    website: control === 'social-website' ? target.value : (socialMeta ? socialMeta.getAttribute('data-website') : '')
                });
            } else if (control === 'inline-image-url') {
                if (currentImageNode) {
                    currentImageNode.setAttribute('src', target.value || '');
                    if (block.dataset.builderBlock === 'hero') {
                        setHeroPartVisibility(block, 'image', !!target.value);
                    } else {
                        currentImageNode.style.display = target.value ? 'inline-block' : 'none';
                    }
                }
            } else if (control === 'inline-image-alt') {
                if (currentImageNode) {
                    currentImageNode.setAttribute('alt', target.value || '');
                }
            } else if (control === 'inline-image-link') {
                if (!isValidLink(target.value)) {
                    window.alert(strings.invalidLink || 'Enter a valid link that starts with http://, https://, mailto:, tel:, or {{unsubscribe_url}}.');
                    return;
                }
                if (currentImageLinkNode) {
                    currentImageLinkNode.setAttribute('href', target.value || '');
                }
            } else if (control === 'inline-image-width') {
                if (currentImageNode) {
                    currentImageNode.style.width = clampNumber(target.value, 40, 600, 320) + 'px';
                    currentImageNode.style.maxWidth = '100%';
                }
            } else if (control === 'inline-image-height') {
                if (currentImageNode) {
                    var imageHeight = clampNumber(target.value, 0, 600, 0);
                    currentImageNode.style.height = imageHeight > 0 ? imageHeight + 'px' : 'auto';
                }
            } else if (control === 'inline-image-align') {
                surface.style.textAlign = target.value;
            } else if (control === 'inline-image-fit') {
                if (currentImageNode) {
                    currentImageNode.style.objectFit = target.value;
                }
            } else if (control === 'inline-image-radius') {
                if (currentImageNode) {
                    currentImageNode.style.borderRadius = clampNumber(target.value, 0, 80, 0) + 'px';
                }
            } else if (control === 'qr-target-inline' || control === 'qr-caption-inline' || control === 'qr-size-inline' || control === 'qr-align-inline' || control === 'qr-foreground-inline' || control === 'qr-background-inline') {
                var currentMeta = block.querySelector('.mb-qr-meta');
                updateQrBlock(block, {
                    target: control === 'qr-target-inline' ? target.value : ((currentMeta && currentMeta.getAttribute('data-qr-target')) || 'https://example.com'),
                    size: control === 'qr-size-inline' ? clampNumber(target.value, 100, 280, 180) : clampNumber(currentMeta ? currentMeta.getAttribute('data-qr-size') : 180, 100, 280, 180),
                    align: control === 'qr-align-inline' ? target.value : ((currentMeta && currentMeta.getAttribute('data-qr-align')) || 'center'),
                    foreground: control === 'qr-foreground-inline' ? target.value : ((currentMeta && currentMeta.getAttribute('data-qr-foreground')) || '#111827'),
                    background: control === 'qr-background-inline' ? target.value : ((currentMeta && currentMeta.getAttribute('data-qr-background')) || '#ffffff'),
                    caption: control === 'qr-caption-inline' ? target.value : ((block.querySelector('.mb-qr-caption') || {}).textContent || ''),
                    padding: clampNumber(currentMeta ? currentMeta.getAttribute('data-qr-padding') : DEFAULT_PADDING, 0, 120, DEFAULT_PADDING),
                    marginBottom: clampNumber(currentMeta ? currentMeta.getAttribute('data-qr-margin-bottom') : 16, 0, 120, 16)
                });
            } else if (control === 'qr-radius-inline') {
                var currentMetaRadius = block.querySelector('.mb-qr-meta');
                var qrImage = block.querySelector('.mb-qr-image');
                var radius = clampNumber(target.value, 0, 60, 16);
                if (currentMetaRadius) {
                    currentMetaRadius.setAttribute('data-qr-radius', String(radius));
                }
                if (qrImage) {
                    qrImage.style.borderRadius = radius + 'px';
                }
            } else if (control === 'custom-label') {
                block.setAttribute('data-custom-label', target.value || '');
            }

            syncFields();
        }

        function setImageVisibility(block, visible) {
            var imageNode = getImageNode(block);
            var linkNode = getImageLinkNode(block);

            if (!imageNode) {
                return;
            }

            if (block.dataset.builderBlock === 'hero') {
                setHeroPartVisibility(block, 'image', visible);
                return;
            }

            imageNode.style.display = visible ? 'inline-block' : 'none';
            if (linkNode) {
                linkNode.style.display = visible ? 'block' : 'none';
                linkNode.style.textDecoration = 'none';
            }
        }

        function openImageModal(block) {
            var imageNode = getImageNode(block);
            var linkNode = getImageLinkNode(block);
            var surface = getSurface(block);
            var computed = imageNode ? window.getComputedStyle(imageNode) : { width: '320px', height: 'auto' };
            var surfaceStyle = surface ? window.getComputedStyle(surface) : {};

            state.imageModalBlock = block;
            hideUploadStatus();

            imageModalFields.url.value = imageNode && imageNode.getAttribute('src') ? imageNode.getAttribute('src') : '';
            imageModalFields.alt.value = imageNode ? (imageNode.getAttribute('alt') || '') : '';
            imageModalFields.width.value = imageNode && imageNode.style.width ? parseInt(imageNode.style.width, 10) : (parseInt(computed.width, 10) || 320);
            imageModalFields.height.value = imageNode && imageNode.style.height && imageNode.style.height !== 'auto' ? parseInt(imageNode.style.height, 10) : '';
            imageModalFields.align.value = surfaceStyle.textAlign || 'center';
            imageModalFields.link.value = linkNode ? (linkNode.getAttribute('href') || '') : '';
            imageModalFields.paddingTop.value = parseInt(surfaceStyle.paddingTop, 10) || 0;
            imageModalFields.paddingRight.value = parseInt(surfaceStyle.paddingRight, 10) || 0;
            imageModalFields.paddingBottom.value = parseInt(surfaceStyle.paddingBottom, 10) || 0;
            imageModalFields.paddingLeft.value = parseInt(surfaceStyle.paddingLeft, 10) || 0;
            imageModalFields.marginTop.value = imageNode && imageNode.style.marginTop ? parseInt(imageNode.style.marginTop, 10) : 0;
            imageModalFields.marginBottom.value = imageNode && imageNode.style.marginBottom ? parseInt(imageNode.style.marginBottom, 10) : 0;
            imageModalFields.radius.value = imageNode && imageNode.style.borderRadius ? parseInt(imageNode.style.borderRadius, 10) : 0;

            if (imageModal) {
                imageModal.modal('show');
            }
        }

        function applyImageModalChanges() {
            var block = state.imageModalBlock;
            var imageNode = getImageNode(block);
            var linkNode = getImageLinkNode(block);
            var surface = getSurface(block);
            var url = String(getControlValue(imageModalFields.url, '') || '').trim();
            var width = clampNumber(imageModalFields.width.value, 40, 600, 320);
            var height = String(imageModalFields.height.value || '').trim();
            var link = String(getControlValue(imageModalFields.link, '') || '').trim();

            if (!block || !imageNode || !surface) {
                return;
            }

            if (link !== '' && !isValidLink(link)) {
                window.alert(strings.invalidLink || 'Enter a valid link that starts with http://, https://, mailto:, tel:, or {{unsubscribe_url}}.');
                return;
            }

            if (url === '') {
                setImageVisibility(block, false);
                imageNode.setAttribute('src', '');
            } else {
                setImageVisibility(block, true);
                imageNode.setAttribute('src', url);
            }

            imageNode.setAttribute('alt', imageModalFields.alt.value || '');
            imageNode.style.width = width + 'px';
            imageNode.style.maxWidth = '100%';
            imageNode.style.height = height !== '' ? clampNumber(height, 0, 600, width) + 'px' : 'auto';
            imageNode.style.borderRadius = clampNumber(imageModalFields.radius.value, 0, 80, 0) + 'px';
            imageNode.style.marginTop = clampNumber(imageModalFields.marginTop.value, 0, 160, 0) + 'px';
            imageNode.style.marginBottom = clampNumber(imageModalFields.marginBottom.value, 0, 160, 18) + 'px';
            if (block.dataset.builderBlock !== 'hero') {
                imageNode.style.display = url === '' ? 'none' : 'inline-block';
            }

            surface.style.textAlign = imageModalFields.align.value || 'center';
            surface.style.paddingTop = clampNumber(imageModalFields.paddingTop.value, 0, 160, 0) + 'px';
            surface.style.paddingRight = clampNumber(imageModalFields.paddingRight.value, 0, 160, 0) + 'px';
            surface.style.paddingBottom = clampNumber(imageModalFields.paddingBottom.value, 0, 160, 0) + 'px';
            surface.style.paddingLeft = clampNumber(imageModalFields.paddingLeft.value, 0, 160, 0) + 'px';

            if (linkNode) {
                linkNode.setAttribute('href', link || '');
                linkNode.setAttribute('target', '_blank');
                if (block.dataset.builderBlock !== 'hero') {
                    linkNode.style.display = url === '' ? 'none' : 'block';
                }
            }

            if (imageModal) {
                imageModal.modal('hide');
            }

            syncFields();
        }

        function showUploadStatus(type, message) {
            if (!imageModalFields.status) {
                return;
            }

            imageModalFields.status.className = 'alert marketing-builder-modal-status alert-' + type;
            imageModalFields.status.textContent = message;
            imageModalFields.status.classList.remove('hide');
        }

        function hideUploadStatus() {
            if (!imageModalFields.status) {
                return;
            }

            imageModalFields.status.className = 'alert marketing-builder-modal-status hide';
            imageModalFields.status.textContent = '';
        }

        function handleImageUpload(file) {
            if (!file) {
                return;
            }

            var requestId = ++state.uploadRequestId;
            var data = new window.FormData();
            data.append('builder_image', file);

            if (csrf.name && csrf.hash) {
                data.append(csrf.name, csrf.hash);
            }

            showUploadStatus('info', strings.uploading || 'Uploading...');

            window.fetch(config.uploadUrl, {
                method: 'POST',
                body: data,
                credentials: 'same-origin'
            }).then(function (response) {
                return response.text().then(function (text) {
                    var payload = {};
                    try {
                        payload = text ? JSON.parse(text) : {};
                    } catch (error) {
                        payload = {};
                    }

                    if (!response.ok) {
                        throw new Error(payload.message || '');
                    }

                    return payload;
                });
            }).then(function (payload) {
                if (requestId !== state.uploadRequestId) {
                    return;
                }

                if (payload && payload.csrf && payload.csrf.name && payload.csrf.hash) {
                    csrf.name = payload.csrf.name;
                    csrf.hash = payload.csrf.hash;
                }

                var url = payload.url || (payload.data && payload.data.url) || (payload.files && payload.files[0] && payload.files[0].url) || '';
                if (!url) {
                    throw new Error(strings.uploadFailed || 'Image upload failed.');
                }

                if (state.inlineUploadBlock) {
                    var inlineImageNode = getImageNode(state.inlineUploadBlock);
                    if (inlineImageNode) {
                        inlineImageNode.setAttribute('src', url);
                        if (state.inlineUploadBlock.dataset.builderBlock === 'hero') {
                            setHeroPartVisibility(state.inlineUploadBlock, 'image', true);
                        } else {
                            inlineImageNode.style.display = 'inline-block';
                        }
                    }
                    setActiveBlock(state.inlineUploadBlock);
                    syncFields();
                }

                imageModalFields.url.value = url;
                showUploadStatus('success', strings.uploadSuccess || 'Image uploaded.');
            }).catch(function () {
                if (requestId !== state.uploadRequestId) {
                    return;
                }

                showUploadStatus('danger', strings.uploadFailed || 'Image upload failed.');
            });
        }

        function openQrModal(block) {
            var meta = block.querySelector('.mb-qr-meta');
            if (!meta) {
                return;
            }

            state.qrModalBlock = block;
            qrModalFields.target.value = meta.getAttribute('data-qr-target') || 'https://example.com';
            qrModalFields.size.value = meta.getAttribute('data-qr-size') || 180;
            qrModalFields.align.value = meta.getAttribute('data-qr-align') || 'center';
            qrModalFields.foreground.value = meta.getAttribute('data-qr-foreground') || '#111827';
            qrModalFields.background.value = meta.getAttribute('data-qr-background') || '#ffffff';
            qrModalFields.caption.value = (block.querySelector('.mb-qr-caption') || {}).textContent || '';
            qrModalFields.padding.value = meta.getAttribute('data-qr-padding') || DEFAULT_PADDING;
            qrModalFields.marginBottom.value = meta.getAttribute('data-qr-margin-bottom') || 16;

            if (qrModal) {
                qrModal.modal('show');
            }
        }

        function updateQrBlock(block, values) {
            var meta = block ? block.querySelector('.mb-qr-meta') : null;
            var imageNode = block ? block.querySelector('.mb-qr-image') : null;
            var caption = block ? block.querySelector('.mb-qr-caption') : null;
            var surface = block ? getSurface(block) : null;

            if (!block || !meta || !imageNode || !surface) {
                return;
            }

            meta.setAttribute('data-qr-target', values.target);
            meta.setAttribute('data-qr-size', String(values.size));
            meta.setAttribute('data-qr-align', values.align);
            meta.setAttribute('data-qr-foreground', values.foreground);
            meta.setAttribute('data-qr-background', values.background);
            meta.setAttribute('data-qr-padding', String(values.padding));
            meta.setAttribute('data-qr-margin-bottom', String(values.marginBottom));
            meta.setAttribute('data-qr-radius', String(values.radius || 16));

            imageNode.setAttribute('src', qrSvgDataUri(values.target, values.size, strings.qrFallbackNote || 'QR preview', values.foreground, values.background));
            imageNode.style.width = values.size + 'px';
            imageNode.style.maxWidth = '100%';
            imageNode.style.height = 'auto';
            imageNode.style.marginBottom = values.marginBottom + 'px';
            imageNode.style.borderRadius = (values.radius || 16) + 'px';
            surface.style.textAlign = values.align;
            surface.style.padding = values.padding + 'px';

            if (caption) {
                caption.textContent = values.caption || '';
            }
        }

        function applyQrModalChanges() {
            var block = state.qrModalBlock;
            if (!block) {
                return;
            }

            updateQrBlock(block, {
                target: String(qrModalFields.target.value || '').trim() || 'https://example.com',
                size: clampNumber(qrModalFields.size.value, 100, 280, 180),
                align: qrModalFields.align.value || 'center',
                foreground: qrModalFields.foreground.value || '#111827',
                background: qrModalFields.background.value || '#ffffff',
                caption: qrModalFields.caption.value || '',
                padding: clampNumber(qrModalFields.padding.value, 0, 120, DEFAULT_PADDING),
                marginBottom: clampNumber(qrModalFields.marginBottom.value, 0, 120, 16),
                radius: clampNumber((block.querySelector('.mb-qr-meta') || {}).getAttribute ? block.querySelector('.mb-qr-meta').getAttribute('data-qr-radius') : 16, 0, 60, 16)
            });

            if (qrModal) {
                qrModal.modal('hide');
            }

            syncFields();
        }

        function insertUnsubscribeBlock() {
            var existingBlock = findExistingUnsubscribeBlock() || findBlockContainingUnsubscribeToken();
            if (existingBlock) {
                setActiveBlock(existingBlock);
                syncFields();
                return;
            }

            insertBlockAtPlacement(createUnsubscribeBlock(), getPreferredUnsubscribePlacement());
        }

        function openPreview(mode) {
            state.previewMode = mode === 'mobile' ? 'mobile' : 'desktop';
            previewShell.className = 'marketing-builder-preview-shell marketing-builder-preview-shell--' + state.previewMode;
            previewFrame.srcdoc = renderEmailHtml();

            if (canvasFrame) {
                canvasFrame.className = 'marketing-builder-canvas-frame marketing-builder-canvas-frame--' + state.previewMode;
                canvasModeLabel.textContent = state.previewMode === 'mobile'
                    ? (strings.mobileCanvas || 'Mobile preview')
                    : (strings.desktopCanvas || 'Desktop preview');
            }

            if (previewModal) {
                previewModal.modal('show');
            }
        }

        properties.addEventListener('click', function (event) {
            var tabNode = event.target.closest('[data-inspector-tab]');
            if (tabNode) {
                state.inspectorTab = tabNode.getAttribute('data-inspector-tab');
                renderProperties();
                return;
            }

            var commandNode = event.target.closest('[data-rich-text-command]');
            if (commandNode) {
                var command = commandNode.getAttribute('data-rich-text-command') || '';
                var commandValue = commandNode.getAttribute('data-rich-text-value') || '';

                if (command === 'createLink') {
                    commandValue = window.prompt(strings.linkUrl || 'Link URL', 'https://');
                    if (commandValue === null) {
                        return;
                    }
                    commandValue = String(commandValue || '').trim();
                    if (!isValidLink(commandValue)) {
                        window.alert(strings.invalidLink || 'Enter a valid link that starts with http://, https://, mailto:, tel:, or {{unsubscribe_url}}.');
                        return;
                    }
                }

                executeRichTextCommand(command, commandValue);
                syncFields();
                renderProperties();
                return;
            }

            var mergeTagNode = event.target.closest('[data-merge-tag-token]');
            if (mergeTagNode) {
                insertMergeTag(mergeTagNode.getAttribute('data-merge-tag-token') || '');
                return;
            }

            var actionNode = event.target.closest('[data-action]');
            if (!actionNode) {
                return;
            }

            var action = actionNode.getAttribute('data-action');
            if (action === 'move-up') {
                moveActiveBlock(-1);
            } else if (action === 'move-down') {
                moveActiveBlock(1);
            } else if (action === 'duplicate') {
                duplicateActiveBlock();
            } else if (action === 'remove') {
                removeActiveBlock();
            } else if (action === 'back-to-elements') {
                setActiveBlock(null);
            } else if (action === 'select-hero-child' && state.activeBlock && state.activeBlock.dataset.builderBlock === 'hero') {
                state.activeHeroChild = actionNode.getAttribute('data-hero-child') || '';
                setActiveBlock(state.activeBlock);
            } else if (action === 'toggle-hero-child' && state.activeBlock && state.activeBlock.dataset.builderBlock === 'hero') {
                var toggleKey = actionNode.getAttribute('data-hero-child') || '';
                var toggleNode = getHeroPartMap(state.activeBlock)[toggleKey];
                setHeroPartVisibility(state.activeBlock, toggleKey, !isHeroPartVisible(toggleNode));
                setActiveBlock(state.activeBlock);
                syncFields();
            } else if (action === 'restore-hero-child' && state.activeBlock && state.activeBlock.dataset.builderBlock === 'hero') {
                var restoreKey = actionNode.getAttribute('data-hero-child') || '';
                setHeroPartVisibility(state.activeBlock, restoreKey, true);
                state.activeHeroChild = restoreKey;
                setActiveBlock(state.activeBlock);
                syncFields();
            } else if (action === 'move-hero-child-up' && state.activeBlock && state.activeBlock.dataset.builderBlock === 'hero') {
                moveHeroChild(state.activeBlock, actionNode.getAttribute('data-hero-child') || '', -1);
                setActiveBlock(state.activeBlock);
                syncFields();
            } else if (action === 'move-hero-child-down' && state.activeBlock && state.activeBlock.dataset.builderBlock === 'hero') {
                moveHeroChild(state.activeBlock, actionNode.getAttribute('data-hero-child') || '', 1);
                setActiveBlock(state.activeBlock);
                syncFields();
            } else if (action === 'use-saved-company-address' && state.activeBlock) {
                applySavedCompanyAddress(state.activeBlock);
            } else if (action === 'upload-image-inline' && state.activeBlock) {
                state.inlineUploadBlock = state.activeBlock;
                if (fileInput) {
                    fileInput.click();
                }
            }
        });

        palette.addEventListener('click', function (event) {
            var sidebarAction = event.target.closest('[data-sidebar-action]');
            var mergeTagNode = event.target.closest('[data-merge-tag-token]');
            var toggle = event.target.closest('[data-category-toggle]');

            if (mergeTagNode) {
                insertMergeTag(mergeTagNode.getAttribute('data-merge-tag-token') || '');
                return;
            }

            if (sidebarAction) {
                var action = sidebarAction.getAttribute('data-sidebar-action') || '';

                if (action === 'load-template') {
                    var presetSelect = document.getElementById('marketing-builder-preset-select');
                    loadTemplate(presetSelect ? presetSelect.value : 'default');
                } else if (action === 'insert-unsubscribe') {
                    insertUnsubscribeBlock();
                } else if (action === 'insert-company-address') {
                    insertOrFocusCompanyAddressBlock();
                } else if (action === 'use-saved-company-address') {
                    insertOrFocusCompanyAddressBlock();
                    try {
                        var targetBlock = null;
                        if (state.activeBlock && state.activeBlock.dataset && state.activeBlock.dataset.builderBlock === 'company_address') {
                            targetBlock = state.activeBlock;
                        } else {
                            targetBlock = canvas.querySelector('.mb-block[data-builder-block="company_address"]');
                        }

                        if (typeof applySavedCompanyAddress === 'function' && targetBlock) {
                            if (typeof companyAddressConfig !== 'undefined' && companyAddressConfig && companyAddressConfig.configured) {
                                applySavedCompanyAddress(targetBlock);
                                setActiveBlock(targetBlock);
                                syncFields();
                            } else {
                                window.alert(strings.companyAddressSavedMissing || 'Saved company address settings are not configured yet.');
                                setActiveBlock(targetBlock);
                                syncFields();
                            }
                        }
                    } catch (err) {
                        console.error('use-saved-company-address handler failed:', err);
                    }
                } else if (action === 'insert-email-footer') {
                    insertOrFocusEmailFooterBlock();
                } else if (action === 'preview-desktop') {
                    openPreview('desktop');
                } else if (action === 'preview-mobile') {
                    openPreview('mobile');
                }
                return;
            }

            if (!toggle || state.paletteSearch) {
                return;
            }

            state.openCategory = toggle.getAttribute('data-category-toggle');
            var parent = toggle.closest('.marketing-builder-palette-group');
            if (parent && parent.classList.contains('is-open')) {
                state.openCategory = '';
            }
            renderPalette();
        });

        properties.addEventListener('input', function (event) {
            handlePropertyChange(event.target);
        });

        properties.addEventListener('change', function (event) {
            handlePropertyChange(event.target);
        });

        canvas.addEventListener('click', function (event) {
            if (event.target.closest('a')) {
                event.preventDefault();
            }
            var toolbarAction = event.target.closest('[data-canvas-action]');
            if (toolbarAction) {
                var toolbarBlock = toolbarAction.closest('.mb-block');
                setActiveBlock(toolbarBlock);
                if (toolbarAction.getAttribute('data-canvas-action') === 'duplicate') {
                    duplicateActiveBlock();
                } else if (toolbarAction.getAttribute('data-canvas-action') === 'remove') {
                    removeActiveBlock();
                }
                event.preventDefault();
                return;
            }

            var block = event.target.closest('.mb-block');
            if (!block || !canvas.contains(block)) {
                setActiveBlock(null);
                return;
            }

            if (block.dataset.builderBlock === 'hero') {
                var heroPart = event.target.closest('[data-hero-part]');
                state.activeHeroChild = heroPart ? (heroPart.getAttribute('data-hero-part') || '') : '';
            }
            setActiveBlock(block);
        });

        canvas.addEventListener('input', function (event) {
            if (state.sidebarEditor && state.activeBlock && event.target && state.activeBlock.contains(event.target)) {
                syncSidebarEditorFromBlock();
            }
            syncFields();
        });

        canvas.addEventListener('paste', function (event) {
            var editable = event.target.closest ? event.target.closest('[contenteditable="true"]') : null;
            var clipboard;
            var html;
            var text;
            var sanitized;

            if (!editable || !canvas.contains(editable)) {
                return;
            }

            event.preventDefault();
            clipboard = event.clipboardData || window.clipboardData;
            html = clipboard && clipboard.getData ? clipboard.getData('text/html') : '';
            text = clipboard && clipboard.getData ? clipboard.getData('text/plain') : '';
            sanitized = html ? sanitizeEditableHtml(html) : plainTextToSanitizedHtml(text);

            if (!sanitized) {
                sanitized = plainTextToSanitizedHtml(text);
            }

            insertContentAtCursor(editable, sanitized, true);

            if (editable.classList.contains('mb-richtext') || editable.classList.contains('mb-company-address-text') || editable.classList.contains('mb-footer-text')) {
                editable.innerHTML = sanitizeEditableHtml(editable.innerHTML);
            }

            syncFields();
        });

        canvas.addEventListener('dragover', function (event) {
            if (!state.draggingBlock && !state.draggingNewType) {
                return;
            }

            event.preventDefault();
            autoScrollStageDuringDrag(event);
            showDropPlacement(getDropPlacement(event));
        });

        canvas.addEventListener('drop', function (event) {
            var placement;
            var block;

            if (!state.draggingBlock && !state.draggingNewType) {
                return;
            }

            event.preventDefault();
            placement = state.currentDrop || getDropPlacement(event) || { container: canvas, beforeNode: null };

            if (state.draggingNewType) {
                block = createBlock(state.draggingNewType);
                insertBlockAtPlacement(block, placement);
            } else if (state.draggingBlock) {
                block = state.draggingBlock;
                insertBlockAtPlacement(block, placement);
            }

            state.draggingNewType = '';
            state.draggingBlock = null;
            clearDropIndicator();
        });

        canvas.addEventListener('dragleave', function (event) {
            if (event.target === canvas) {
                clearDropIndicator();
            }
        });

        document.addEventListener('dragend', function () {
            state.draggingNewType = '';
            state.draggingBlock = null;
            clearDropIndicator();
        });

        if (fileInput) {
            fileInput.addEventListener('change', function () {
                if (fileInput.files && fileInput.files[0]) {
                    handleImageUpload(fileInput.files[0]);
                }
                fileInput.value = '';
            });
        }

        if (imageModalFields.uploadButton) {
            imageModalFields.uploadButton.addEventListener('click', function () {
                hideUploadStatus();
                state.inlineUploadBlock = state.imageModalBlock;
                if (fileInput) {
                    fileInput.click();
                }
            });
        }

        if (imageModalFields.removeButton) {
            imageModalFields.removeButton.addEventListener('click', function () {
                imageModalFields.url.value = '';
                imageModalFields.alt.value = '';
                hideUploadStatus();
            });
        }

        if (imageModalFields.applyButton) {
            imageModalFields.applyButton.addEventListener('click', applyImageModalChanges);
        }

        if (qrModalFields.applyButton) {
            qrModalFields.applyButton.addEventListener('click', applyQrModalChanges);
        }

        if (insertNowButton) {
            insertNowButton.addEventListener('click', insertUnsubscribeBlock);
        }

        if (paletteSearch) {
            paletteSearch.addEventListener('input', function () {
                state.paletteSearch = String(paletteSearch.value || '').trim().toLowerCase();
                renderPalette();
            });
        }

        form.addEventListener('submit', function () {
            syncFields();
        });

        var previewDesktopButton = document.getElementById('marketing-builder-preview-desktop');
        var previewMobileButton = document.getElementById('marketing-builder-preview-mobile');

        if (previewDesktopButton) {
            previewDesktopButton.addEventListener('click', function () {
                openPreview('desktop');
            });
        }

        if (previewMobileButton) {
            previewMobileButton.addEventListener('click', function () {
                openPreview('mobile');
            });
        }

        renderPalette();

        if (config.initialBuilderState && String(config.initialBuilderState).trim() !== '') {
            state.suppressAutoSelect = true;
            canvas.innerHTML = String(config.initialBuilderState);
            refreshCanvasState();
            setActiveBlock(null);
            syncFields();
            state.suppressAutoSelect = false;
        } else if (config.initialHtml && String(config.initialHtml).trim() !== '') {
            state.suppressAutoSelect = true;
            insertBlockAtPlacement(createLegacyBlock(config.initialHtml), { container: canvas, beforeNode: null });
            setActiveBlock(null);
            renderProperties();
            state.suppressAutoSelect = false;
        } else if (config.isNewCampaign) {
            state.suppressAutoSelect = true;
            applyPreset('default', false);
            setActiveBlock(null);
            renderProperties();
            state.suppressAutoSelect = false;
        } else {
            ensureCanvasPlaceholder();
            renderProperties();
            syncFields();
        }
    });
}(window, document));
