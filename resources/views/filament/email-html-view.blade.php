{{-- Mailpit-style tabbed message viewer.
Rendered inside the infolist — must NOT wrap in <x-filament-panels::page>,
    that duplicates the page header/breadcrumbs --}}
    <div x-data="{
            tab: 'html',
            onPreviewMessage(e) {
                if (!e.data || e.data.nativemailerPreview !== true || !e.data.url) return;
                if (this.$refs.preview && e.source !== this.$refs.preview.contentWindow) return;
                $wire.openExternalLink(e.data.url);
            },
        }" x-on:message.window="onPreviewMessage($event)" class="email-viewer">

        @php
            use App\Support\MimeHeader;

            $email = $this->getRecord();
            $raw = $email->raw ?? '';

            // Parse the header block (before first blank line), unfold continuations
            $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
            $headerBlock = explode("\n\n", $normalized, 2)[0] ?? '';
            $headerBlock = preg_replace('/\n[ \t]+/', ' ', $headerBlock);
            $parsedHeaders = [];
            foreach (explode("\n", $headerBlock) as $line) {
                $pos = strpos($line, ':');
                if ($pos !== false && $pos > 0) {
                    $parsedHeaders[] = [trim(substr($line, 0, $pos)), MimeHeader::decode(trim(substr($line, $pos + 1)))];
                }
            }

            $attachments = $email->attachments ?? [];

            // The iframe is sandboxed with no allow-top-navigation and no allow-same-origin,
            // so a bare click on <a href> inside it is silently swallowed by the browser —
            // that's the bug. We allow-scripts just enough to run this small interceptor,
            // which stops the click, and hands the URL to the parent via postMessage so it
            // can be opened with the OS's default browser instead of navigating in-app.
            $linkInterceptorScript = <<<'HTML'
                <script>
                (function () {
                    function externalUrl(el) {
                        var href = el && el.getAttribute('href');
                        return href && /^(https?:|mailto:|tel:)/i.test(href) ? href : null;
                    }

                    document.addEventListener('click', function (e) {
                        var el = e.target;
                        while (el && el !== document && el.tagName !== 'A') {
                            el = el.parentElement;
                        }
                        var url = el ? externalUrl(el) : null;
                        if (!url) return;
                        e.preventDefault();
                        e.stopPropagation();
                        parent.postMessage({ nativemailerPreview: true, url: url }, '*');
                    }, true);

                    // Defensive: templates that navigate via window.open() instead of a plain click.
                    window.open = function (url) {
                        if (url) parent.postMessage({ nativemailerPreview: true, url: url }, '*');
                        return null;
                    };
                })();
                </script>
                HTML;

            $previewHtml = $linkInterceptorScript . ($email->body_html ?? '');
        @endphp

        {{-- Tab bar --}}
        <div class="ev-tabs" role="tablist">
            <button type="button" role="tab" @click="tab = 'html'"
                :class="{ 'ev-active': tab === 'html' }">HTML</button>
            <button type="button" role="tab" @click="tab = 'source'" :class="{ 'ev-active': tab === 'source' }">HTML
                Source</button>
            <button type="button" role="tab" @click="tab = 'text'"
                :class="{ 'ev-active': tab === 'text' }">Text</button>
            <button type="button" role="tab" @click="tab = 'headers'" :class="{ 'ev-active': tab === 'headers' }">
                Headers <span class="ev-badge">{{ count($parsedHeaders) }}</span>
            </button>
            <button type="button" role="tab" @click="tab = 'raw'" :class="{ 'ev-active': tab === 'raw' }">Raw</button>
            <button type="button" role="tab" @click="tab = 'attachments'"
                :class="{ 'ev-active': tab === 'attachments' }">
                Attachments <span class="ev-badge">{{ count($attachments) }}</span>
            </button>
            <span class="ev-meta">{{ number_format(strlen($raw) / 1024, 1) }} KB</span>
        </div>

        {{-- HTML preview --}}
        <div x-show="tab === 'html'" role="tabpanel">
            @if (filled($email->body_html))
                <div class="ev-panel ev-white">
                    {{-- Sandboxed with allow-scripts only (no allow-same-origin, no allow-top-navigation,
                        no allow-popups): the email HTML is untrusted and can't touch the app's cookies/DOM
                        or navigate/pop out on its own — it can only ask the parent (via the interceptor
                        script above) to hand a link off to the OS browser. --}}
                    <iframe srcdoc="{{ $previewHtml }}" class="ev-iframe" sandbox="allow-scripts"
                        x-ref="preview" title="Email Preview"></iframe>
                </div>
            @elseif (filled($email->body_text))
                <div class="ev-panel ev-white">
                    <pre class="ev-pre ev-dark-text">{{ $email->body_text }}</pre>
                </div>
            @else
                <div class="ev-empty">This email has no content.</div>
            @endif

            {{-- Attachment strip (Mailpit-style, below the message body) --}}
            @if (count($attachments))
                <div class="ev-attach-strip">
                    @foreach ($attachments as $att)
                        @php
                            $type = $att['content_type'] ?? 'application/octet-stream';
                            $isImage = str_starts_with($type, 'image/') && !empty($att['content']);
                            $dataUri = !empty($att['content']) ? "data:{$type};base64,{$att['content']}" : null;
                        @endphp
                        <a class="ev-attach-card" @if ($dataUri) href="{{ $dataUri }}"
                            download="{{ $att['name'] ?? 'attachment' }}" @endif>
                            @if ($isImage)
                                <img src="{{ $dataUri }}" alt="{{ $att['name'] ?? 'attachment' }}"
                                    class="ev-attach-thumb">
                            @else
                                <span class="ev-attach-icon">
                                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                                    </svg>
                                </span>
                            @endif
                            <span class="ev-attach-info">
                                <span class="ev-attach-name">{{ $att['name'] ?? 'unnamed' }}</span>
                                <span class="ev-attach-size">{{ number_format(($att['size'] ?? 0) / 1024, 1) }}
                                    KB</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- HTML source --}}
        <div x-show="tab === 'source'" role="tabpanel" style="display: none;">
            @if (filled($email->body_html))
                <div class="ev-panel">
                    <pre class="ev-pre">{{ $email->body_html }}</pre>
                </div>
            @else
                <div class="ev-empty">No HTML part.</div>
            @endif
        </div>

        {{-- Text part --}}
        <div x-show="tab === 'text'" role="tabpanel" style="display: none;">
            @if (filled($email->body_text))
                <div class="ev-panel">
                    <pre class="ev-pre">{{ $email->body_text }}</pre>
                </div>
            @else
                <div class="ev-empty">No text part.</div>
            @endif
        </div>

        {{-- Headers --}}
        <div x-show="tab === 'headers'" role="tabpanel" style="display: none;">
            @if (count($parsedHeaders))
                <div class="ev-panel">
                    <table class="ev-headers">
                        @foreach ($parsedHeaders as [$name, $value])
                            <tr>
                                <td class="ev-hname">{{ $name }}</td>
                                <td class="ev-hvalue">{{ $value }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @else
                <div class="ev-empty">No headers captured.</div>
            @endif
        </div>

        {{-- Raw message --}}
        <div x-show="tab === 'raw'" role="tabpanel" style="display: none;">
            @if (filled($raw))
                <div class="ev-panel">
                    <pre class="ev-pre">{{ $raw }}</pre>
                </div>
            @else
                <div class="ev-empty">Raw message not stored.</div>
            @endif
        </div>

        {{-- Attachments --}}
        <div x-show="tab === 'attachments'" role="tabpanel" style="display: none;">
            @if (count($attachments))
                <div class="ev-panel">
                    <table class="ev-headers">
                        @foreach ($attachments as $att)
                            <tr>
                                <td class="ev-hname">{{ $att['name'] ?? 'unnamed' }}</td>
                                <td class="ev-hvalue">
                                    {{ $att['content_type'] ?? 'application/octet-stream' }}
                                    &middot; {{ number_format(($att['size'] ?? 0) / 1024, 1) }} KB
                                    @if (!empty($att['content']))
                                        &middot; <a class="ev-link"
                                            href="data:{{ $att['content_type'] ?? 'application/octet-stream' }};base64,{{ $att['content'] }}"
                                            download="{{ $att['name'] ?? 'attachment' }}">Download</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @else
                <div class="ev-empty">No attachments.</div>
            @endif
        </div>

        <style>
            .email-viewer {
                width: 100%;
            }

            .ev-tabs {
                display: flex;
                align-items: center;
                gap: 0.25rem;
                border-bottom: 1px solid rgba(128, 128, 128, 0.3);
                margin-bottom: 1rem;
                flex-wrap: wrap;
            }

            .ev-tabs button {
                padding: 0.5rem 0.9rem;
                font-size: 0.875rem;
                color: inherit;
                opacity: 0.6;
                background: none;
                border: none;
                border-bottom: 2px solid transparent;
                cursor: pointer;
                margin-bottom: -1px;
            }

            .ev-tabs button:hover {
                opacity: 0.9;
            }

            .ev-tabs button.ev-active {
                opacity: 1;
                font-weight: 600;
                border-bottom-color: #f59e0b;
            }

            .ev-badge {
                display: inline-block;
                min-width: 1.25rem;
                padding: 0 0.35rem;
                border-radius: 9999px;
                background: rgba(128, 128, 128, 0.25);
                font-size: 0.72rem;
                text-align: center;
            }

            .ev-meta {
                margin-left: auto;
                font-size: 0.75rem;
                opacity: 0.5;
                padding-right: 0.25rem;
            }

            .ev-panel {
                border: 1px solid rgba(128, 128, 128, 0.3);
                border-radius: 0.5rem;
                overflow: hidden;
            }

            .ev-white {
                /* Near-white: emails are designed for white backgrounds,
                   but full #fff glares in the dark UI */
                background: #f8fafc;
            }

            .ev-iframe {
                width: 100%;
                height: 600px;
                border: none;
                display: block;
            }

            .ev-pre {
                margin: 0;
                padding: 1.25rem;
                max-height: 600px;
                overflow: auto;
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 0.8125rem;
                line-height: 1.6;
                white-space: pre-wrap;
                word-break: break-word;
            }

            .ev-dark-text {
                color: #1f2937;
            }

            .ev-headers {
                width: 100%;
                border-collapse: collapse;
                font-size: 0.8125rem;
            }

            .ev-headers td {
                padding: 0.5rem 1rem;
                border-bottom: 1px solid rgba(128, 128, 128, 0.15);
                vertical-align: top;
            }

            .ev-headers tr:last-child td {
                border-bottom: none;
            }

            .ev-hname {
                font-weight: 600;
                white-space: nowrap;
                width: 1%;
            }

            .ev-hvalue {
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                word-break: break-all;
            }

            .ev-link {
                color: #f59e0b;
                text-decoration: underline;
            }

            .ev-attach-strip {
                display: flex;
                flex-wrap: wrap;
                gap: 0.6rem;
                margin-top: 0.75rem;
            }

            .ev-attach-card {
                display: flex;
                align-items: center;
                gap: 0.6rem;
                padding: 0.5rem 0.9rem 0.5rem 0.5rem;
                border: 1px solid rgba(128, 128, 128, 0.3);
                border-radius: 0.5rem;
                text-decoration: none;
                color: inherit;
                max-width: 16rem;
            }

            .ev-attach-card:hover {
                border-color: #f59e0b;
            }

            .ev-attach-thumb {
                width: 2.5rem;
                height: 2.5rem;
                object-fit: cover;
                border-radius: 0.375rem;
                background: #f8fafc;
            }

            .ev-attach-icon {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 2.5rem;
                height: 2.5rem;
                border-radius: 0.375rem;
                background: rgba(128, 128, 128, 0.15);
                opacity: 0.8;
            }

            .ev-attach-info {
                display: flex;
                flex-direction: column;
                min-width: 0;
            }

            .ev-attach-name {
                font-size: 0.8125rem;
                font-weight: 600;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .ev-attach-size {
                font-size: 0.72rem;
                opacity: 0.55;
            }

            .ev-empty {
                padding: 2rem;
                text-align: center;
                opacity: 0.5;
                border: 1px dashed rgba(128, 128, 128, 0.4);
                border-radius: 0.5rem;
            }
        </style>
    </div>
