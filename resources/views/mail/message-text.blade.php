{{--
    Plain-text alternative to mail/message.blade.php.

    Not decoration: a message sent as HTML only is scored as more likely to be spam by
    most filters, and text/plain is what a screen reader, a terminal client and a phone
    notification preview actually read. The content is the same data, so the two parts can
    never drift.
--}}
@if ($greeting !== null && $greeting !== ''){{ $greeting }}

@endif
{{ $title }}
{{ str_repeat('=', min(mb_strlen($title), 64)) }}
@foreach ($intro as $line)

{{ $line }}
@endforeach
@if ($meta !== [])
@foreach ($meta as $row)

{{ $row['label'] }}: {{ $row['value'] }}
@endforeach
@endif
@if ($actionUrl !== null && $actionText !== null)

{{ $actionText }}: {{ $actionUrl }}
@endif
@foreach ($outro as $line)

{{ $line }}
@endforeach
@foreach ($footerLines as $line)

{{ $line }}
@endforeach

--
{{ $appName }}
