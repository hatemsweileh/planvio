{{--
    The Planvio mail shell.

    Written for mail clients, not browsers. Three constraints shape everything below and
    none of them are negotiable:

      - **Tables, not flexbox.** Outlook on Windows renders through Word, which has no
        support for float, flex or grid. Nested tables with fixed widths are the only
        layout primitive that survives every client.
      - **Inline styles carry the design.** Gmail strips <style> blocks from forwarded
        mail and several clients drop them outright, so every colour, size and spacing
        value that matters is written on the element itself. The <style> block below only
        adds progressive enhancement: dark mode and a mobile break.
      - **Dark mode is opt-in per client.** `color-scheme` tells clients that respect it
        not to invert the palette themselves; the media query then repaints the surfaces
        for the clients that support it. Outlook.com rewrites classes with a `data-ogsc`
        prefix instead, so those selectors are duplicated. Anything that does neither
        keeps the light palette, which is why the light palette is the inline default.

    The mark is a PNG. Mail clients do not render SVG, and remote images are blocked by
    default in most of them — the workspace name sits beside the mark as live text so the
    header still reads as Planvio with images turned off.
--}}
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
{{--
    $direction, $near, $far, $tracking and $font are resolved once in
    PlanvioNotification::planvioMail() and handed to both templates. The reasoning is
    written out there: a queued notification has no request to read a direction from, a mail
    client has no logical properties to express one with, and a Blade section in the child
    template is evaluated before its layout runs.

    dir goes on html AND on the outer table: Outlook renders through Word, which ignores
    direction inherited into a nested table, and this shell is nested tables all the way
    down.
--}}
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $direction }}">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="color-scheme" content="light dark" />
    <meta name="supported-color-schemes" content="light dark" />
    <title>{{ $title ?? $appName }}</title>
    <style type="text/css">
        :root { color-scheme: light dark; supported-color-schemes: light dark; }

        /* Client resets. */
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        table { border-collapse: collapse !important; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        a { text-decoration: none; }

        /* Stop iOS and Outlook turning dates, numbers and addresses into blue links. */
        .pv-plain a, .pv-plain a:visited { color: inherit !important; text-decoration: none !important; }

        @media only screen and (max-width: 620px) {
            .pv-shell { width: 100% !important; }
            .pv-pad { padding-left: 24px !important; padding-right: 24px !important; }
            .pv-heading { font-size: 22px !important; line-height: 30px !important; }
            .pv-button a { display: block !important; text-align: center !important; }
        }

        @media (prefers-color-scheme: dark) {
            .pv-body, .pv-body-cell { background-color: #0B0F16 !important; }
            .pv-card { background-color: #141A24 !important; border-color: #242C38 !important; }
            .pv-text, .pv-heading { color: #E8EBF0 !important; }
            .pv-muted { color: #9AA4B2 !important; }
            .pv-rule { background-color: #242C38 !important; border-color: #242C38 !important; }
            .pv-meta-cell { background-color: #1B2230 !important; border-color: #242C38 !important; }
            .pv-wordmark { color: #E8EBF0 !important; }
        }

        /* Outlook.com rewrites class names with these prefixes instead of honouring the query. */
        [data-ogsc] .pv-body, [data-ogsc] .pv-body-cell { background-color: #0B0F16 !important; }
        [data-ogsc] .pv-card { background-color: #141A24 !important; border-color: #242C38 !important; }
        [data-ogsc] .pv-text, [data-ogsc] .pv-heading { color: #E8EBF0 !important; }
        [data-ogsc] .pv-muted { color: #9AA4B2 !important; }
        [data-ogsc] .pv-rule { background-color: #242C38 !important; border-color: #242C38 !important; }
        [data-ogsc] .pv-meta-cell { background-color: #1B2230 !important; border-color: #242C38 !important; }
        [data-ogsc] .pv-wordmark { color: #E8EBF0 !important; }
    </style>
    <!--[if mso]>
    <style type="text/css">
        /* Word has no web fonts. Tahoma is the Arabic face every Windows install carries. */
        body, table, td, a, div, p { font-family: {{ $direction === 'rtl' ? "Tahoma, 'Segoe UI'" : 'Arial, Helvetica' }}, sans-serif !important; }
    </style>
    <![endif]-->
</head>
<body class="pv-body" dir="{{ $direction }}" style="margin:0; padding:0; width:100%; background-color:#F0EFEF;">

{{-- Inbox preview text, then enough whitespace that the body copy is not pulled in after it. --}}
<div style="display:none; font-size:1px; color:#F0EFEF; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">
    {{ $preheader ?? '' }}
    <span>&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</span>
</div>

<table role="presentation" class="pv-body" dir="{{ $direction }}" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F0EFEF;">
    <tr>
        <td class="pv-body-cell" align="center" style="background-color:#F0EFEF; padding:32px 12px;">

            <table role="presentation" class="pv-shell" dir="{{ $direction }}" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">

                {{-- Header: mark plus wordmark. --}}
                <tr>
                    {{-- align= has no logical spelling, so the reading edge is chosen in PHP. --}}
                    <td align="{{ $near }}" dir="{{ $direction }}" style="padding:0 8px 20px 8px;">
                        <table role="presentation" dir="{{ $direction }}" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td width="36" style="width:36px; padding-{{ $far }}:12px; vertical-align:middle;">
                                    <img src="{{ $markUrl }}" width="36" height="36" alt=""
                                         style="display:block; width:36px; height:36px; border-radius:8px;" />
                                </td>
                                <td class="pv-wordmark" style="vertical-align:middle; font-family:{{ $font }}; font-size:17px; font-weight:600; letter-spacing:{{ $tracking }}; color:#0E1420;">
                                    {{ $appName }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Card. The accent rule along the top is the only piece of brand colour
                     that survives an image-blocking client. --}}
                <tr>
                    <td class="pv-card" style="background-color:#FFFFFF; border:1px solid #E2E4E8; border-radius:12px; overflow:hidden;">
                        <table role="presentation" dir="{{ $direction }}" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td height="4" style="height:4px; line-height:4px; font-size:4px; background-color:{{ $accent }};">&nbsp;</td>
                            </tr>
                            <tr>
                                <td class="pv-pad" style="padding:32px 40px 36px 40px;">
                                    @yield('content')
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Footer. --}}
                <tr>
                    <td class="pv-pad pv-plain" style="padding:24px 40px 0 40px;">
                        <table role="presentation" dir="{{ $direction }}" width="100%" cellpadding="0" cellspacing="0" border="0">
                            @foreach ($footerLines as $line)
                                <tr>
                                    <td class="pv-muted" style="font-family:{{ $font }}; font-size:12px; line-height:18px; color:#7A828E; padding-bottom:6px;">
                                        {{ $line }}
                                    </td>
                                </tr>
                            @endforeach
                            <tr>
                                <td class="pv-muted" style="font-family:{{ $font }}; font-size:12px; line-height:18px; color:#7A828E; padding-top:6px;">
                                    &copy; {{ date('Y') }} {{ $appName }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
