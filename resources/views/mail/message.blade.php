{{--
    The one body template every Planvio notification renders through.

    Notifications hand it a heading, some lines, an optional table of facts and an optional
    call to action; nothing composes its own HTML. That is deliberate — a mail template is
    the least-tested surface in a product, and thirteen hand-written ones would be thirteen
    places for an unescaped title or a broken Outlook button to hide.

    @param string                                 $title
    @param string|null                            $greeting
    @param list<string>                           $intro
    @param list<array{label: string, value: string}> $meta
    @param string|null                            $actionText
    @param string|null                            $actionUrl
    @param list<string>                           $outro
--}}
@extends('mail.layout')

@section('content')
    {{--
        $direction, $near, $far, $font and $headingTracking come from
        PlanvioNotification::planvioMail(), not from the layout: a Blade section is
        evaluated before the layout it extends, so anything the layout computed would not
        be defined here yet.
    --}}
    <table role="presentation" dir="{{ $direction }}" width="100%" cellpadding="0" cellspacing="0" border="0">

        @if ($greeting !== null && $greeting !== '')
            <tr>
                <td class="pv-muted" align="{{ $near }}" style="font-family:{{ $font }}; font-size:14px; line-height:20px; color:#5A6472; padding-bottom:8px;">
                    {{ $greeting }}
                </td>
            </tr>
        @endif

        <tr>
            <td class="pv-heading" align="{{ $near }}" style="font-family:{{ $font }}; font-size:24px; line-height:32px; font-weight:600; letter-spacing:{{ $headingTracking }}; color:#0E1420; padding-bottom:16px;">
                {{ $title }}
            </td>
        </tr>

        @foreach ($intro as $line)
            <tr>
                <td class="pv-text" align="{{ $near }}" style="font-family:{{ $font }}; font-size:15px; line-height:24px; color:#2A3242; padding-bottom:12px;">
                    {{ $line }}
                </td>
            </tr>
        @endforeach

        @if ($meta !== [])
            <tr>
                <td style="padding:8px 0 20px 0;">
                    {{--
                        The outer gutter belongs against the panel edge and the inner one
                        against the gap between the columns, so both are written to the
                        reading direction rather than to `left` and `right`. In Arabic the
                        label column is on the right, and a fixed 16px on its left would put
                        the wide gutter down the middle of the table.
                    --}}
                    <table role="presentation" dir="{{ $direction }}" width="100%" cellpadding="0" cellspacing="0" border="0" class="pv-meta-cell pv-plain" style="background-color:#F7F8FA; border:1px solid #E2E4E8; border-radius:8px;">
                        @foreach ($meta as $row)
                            <tr>
                                <td width="34%" class="pv-muted" align="{{ $near }}" style="width:34%; font-family:{{ $font }}; font-size:13px; line-height:20px; color:#5A6472; padding-top:{{ $loop->first ? '14px' : '6px' }}; padding-bottom:{{ $loop->last ? '14px' : '6px' }}; padding-{{ $near }}:16px; padding-{{ $far }}:8px; vertical-align:top;">
                                    {{ $row['label'] }}
                                </td>
                                <td class="pv-text" align="{{ $near }}" style="font-family:{{ $font }}; font-size:13px; line-height:20px; font-weight:500; color:#0E1420; padding-top:{{ $loop->first ? '14px' : '6px' }}; padding-bottom:{{ $loop->last ? '14px' : '6px' }}; padding-{{ $near }}:8px; padding-{{ $far }}:16px; vertical-align:top;">
                                    {{-- A task key, a date or a duration; isolated so the bidi
                                         algorithm cannot move its punctuation to the far end. --}}
                                    <span dir="auto" style="unicode-bidi:isolate;">{{ $row['value'] }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        @endif

        @if ($actionUrl !== null && $actionText !== null)
            <tr>
                <td class="pv-button" align="{{ $near }}" style="padding:8px 0 24px 0;">
                    <!--[if mso]>
                    <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                                 href="{{ $actionUrl }}" style="height:44px;v-text-anchor:middle;width:240px;"
                                 arcsize="18%" stroke="f" fillcolor="{{ $accent }}">
                        <w:anchorlock/>
                        <center style="color:#FFFFFF;font-family:{{ $direction === 'rtl' ? 'Tahoma' : 'Arial' }},sans-serif;font-size:15px;font-weight:bold;">{{ $actionText }}</center>
                    </v:roundrect>
                    <![endif]-->
                    <!--[if !mso]><!-- -->
                    <a href="{{ $actionUrl }}"
                       style="display:inline-block; background-color:{{ $accent }}; color:#FFFFFF; font-family:{{ $font }}; font-size:15px; font-weight:600; line-height:20px; padding:12px 28px; border-radius:8px; text-decoration:none; mso-hide:all;">{{ $actionText }}</a>
                    <!--<![endif]-->
                </td>
            </tr>
        @endif

        @foreach ($outro as $line)
            <tr>
                <td class="pv-text" align="{{ $near }}" style="font-family:{{ $font }}; font-size:15px; line-height:24px; color:#2A3242; padding-bottom:12px;">
                    {{ $line }}
                </td>
            </tr>
        @endforeach

        @if ($actionUrl !== null && $actionText !== null)
            <tr>
                <td class="pv-rule" style="height:1px; line-height:1px; font-size:1px; background-color:#E2E4E8; padding:0;">&nbsp;</td>
            </tr>
            <tr>
                {{-- Some clients strip the button; the raw address is the fallback, and it is
                     also what a cautious reader checks before clicking anything. --}}
                <td class="pv-muted" align="{{ $near }}" style="font-family:{{ $font }}; font-size:12px; line-height:20px; color:#7A828E; padding-top:16px; word-break:break-all;">
                    {{ __('If the button does not work, paste this address into your browser:') }}
                    <br />
                    {{--
                        A URL is not prose. Inside an Arabic paragraph the bidi algorithm
                        resolves its trailing slash, bracket or query separator to the
                        paragraph direction and prints it at the wrong end, so the address a
                        cautious reader copies is not the address that was sent.
                    --}}
                    <a href="{{ $actionUrl }}" dir="ltr" style="display:inline-block; direction:ltr; unicode-bidi:isolate; color:{{ $accent }}; text-decoration:underline;">{{ $actionUrl }}</a>
                </td>
            </tr>
        @endif

    </table>
@endsection
