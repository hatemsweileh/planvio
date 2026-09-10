<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Release identity
    |--------------------------------------------------------------------------
    |
    | `version` is the application release. `db_version` is bumped whenever a
    | release requires migrations to run; the upgrade screen compares the value
    | stored in the settings table against this one to decide what to offer.
    |
    */

    'version' => '1.0.0',
    'db_version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | Defaults only. Anything an administrator changes is stored in the settings
    | table and read through App\Support\Branding, which falls back to these.
    |
    */

    'brand' => [
        'name' => env('APP_NAME', 'Planvio'),
        'tagline' => 'Plan the work. Let AI run it.',
        'logo' => env('APP_LOGO', '/img/brand/planvio-logo-h.svg'),
        'logo_inverse' => '/img/brand/planvio-logo-h-inverse.svg',
        'mark' => '/img/brand/planvio-mark.svg',
        'mark_ink' => '/img/brand/planvio-mark-ink.svg',
        'favicon' => env('APP_FAVICON', '/favicon.svg'),
        'primary_color' => env('APP_PRIMARY_COLOR', '#3F66B0'),
        'support_url' => env('APP_SUPPORT_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Installation
    |--------------------------------------------------------------------------
    |
    | The installer refuses to run once the lock file exists. The lock is checked
    | server-side on every installer request; hiding the route is not sufficient.
    |
    */

    'install' => [
        'lock_file' => storage_path('app/planvio-installed.lock'),
        'min_php' => '8.3.0',
        'required_extensions' => [
            'pdo', 'pdo_mysql', 'mbstring', 'openssl', 'json', 'fileinfo',
            'xml', 'ctype', 'tokenizer', 'curl', 'bcmath', 'gd',
        ],
        'optional_extensions' => ['intl', 'zip', 'exif', 'sodium'],
        'writable_paths' => [
            'storage/app',
            'storage/framework',
            'storage/logs',
            'bootstrap/cache',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | Attachments live on the private disk and are streamed through an
    | authorising controller. The extension allow-list is authoritative: a file
    | is rejected unless BOTH its extension and its sniffed MIME type match.
    |
    */

    'uploads' => [
        'max_size_kb' => (int) env('PLANVIO_MAX_UPLOAD_KB', 20480),
        'avatar_max_size_kb' => 2048,
        'disk' => 'private',
        'allowed_extensions' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tiff', 'heic',
            'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'md',
            'xls', 'xlsx', 'ods', 'csv', 'tsv',
            'ppt', 'pptx', 'odp',
            'zip', 'gz', 'tar', 'rar', '7z',
            'mp3', 'wav', 'ogg', 'm4a',
            'mp4', 'webm', 'mov', 'avi', 'mkv',
            'json', 'xml', 'ics',
        ],
        'allowed_mimes' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
            'image/bmp', 'image/tiff', 'image/heic',
            'application/pdf', 'application/msword', 'application/rtf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.oasis.opendocument.text',
            'text/plain', 'text/markdown', 'text/csv', 'text/tab-separated-values',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.presentation',
            'application/zip', 'application/gzip', 'application/x-tar',
            'application/vnd.rar', 'application/x-7z-compressed',
            'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp4',
            'video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo',
            'video/x-matroska',
            'application/json', 'application/xml', 'text/xml', 'text/calendar',
        ],
        // Never accepted regardless of the lists above. Belt and braces against
        // a server misconfigured to execute files out of the storage directory.
        'blocked_extensions' => [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
            'pht', 'inc', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'dll',
            'so', 'bat', 'cmd', 'com', 'scr', 'msi', 'jar', 'htaccess', 'htpasswd',
        ],
        // SVG uploads are sanitised, never trusted: they can carry script.
        'sanitise_svg' => true,

        /*
        |----------------------------------------------------------------------
        | Malware scanning
        |----------------------------------------------------------------------
        |
        | Planvio cannot ship a virus scanner — signatures need daily updates and
        | a daemon, neither of which belongs in a release ZIP — but it ships the
        | seam. `driver` selects an implementation of
        | App\Services\Uploads\ScansUploads:
        |
        |   null       App\Services\Uploads\NullScanner. Every file passes. The
        |              extension, MIME and SVG controls above still run.
        |   'clamav'   App\Services\Uploads\ClamAvScanner. Streams the file to a
        |              running `clamd` over its INSTREAM protocol.
        |
        | `socket` wins when it is set: a unix socket is the faster and safer way
        | to reach a clamd on the same host, because nothing on the network can
        | speak to it. `host`/`port` are the fallback for a clamd that is
        | listening on TCP (`TCPSocket 3310` in clamd.conf).
        |
        | `timeout` covers connecting, sending and reading the verdict. It is
        | generous because clamd queues concurrent scans, and a scan that times
        | out REFUSES the upload rather than allowing it: a scanner that has been
        | configured and cannot be reached is a failure, not an absence.
        |
        | `max_bytes` mirrors clamd's own `StreamMaxLength` (25 MB by default).
        | A file over it is refused rather than sent, because clamd answers an
        | oversized stream by closing the connection — which is indistinguishable
        | from the daemon dying, and would refuse the upload with the wrong
        | reason. Raise both together or leave both alone.
        |
        */
        'scanner' => [
            'driver' => env('PLANVIO_UPLOAD_SCANNER'),
            'host' => env('PLANVIO_CLAMAV_HOST', '127.0.0.1'),
            'port' => (int) env('PLANVIO_CLAMAV_PORT', 3310),
            'socket' => env('PLANVIO_CLAMAV_SOCKET'),
            'timeout' => (int) env('PLANVIO_CLAMAV_TIMEOUT', 30),
            'max_bytes' => (int) env('PLANVIO_CLAMAV_MAX_BYTES', 26214400),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */

    'security' => [
        'password' => [
            'min_length' => (int) env('PLANVIO_PASSWORD_MIN', 10),
            'require_mixed_case' => true,
            'require_numbers' => true,
            'require_symbols' => false,
            'check_compromised' => env('PLANVIO_CHECK_PWNED', false),
        ],
        'login' => [
            'max_attempts' => 5,
            'decay_minutes' => 5,
            'lockout_minutes' => 15,
        ],
        'two_factor' => [
            'enabled' => true,
            'window' => 1,
            'recovery_code_count' => 8,
            // Workspace roles for which 2FA is mandatory before any other action.
            'required_for_roles' => [],
            'required_for_platform_admins' => env('PLANVIO_2FA_REQUIRED_ADMINS', false),
        ],
        'session' => [
            'idle_timeout_minutes' => (int) env('PLANVIO_IDLE_TIMEOUT', 0),
        ],

        /*
        |----------------------------------------------------------------------
        | Content-Security-Policy
        |----------------------------------------------------------------------
        |
        | Sent by App\Http\Middleware\ContentSecurityPolicy on every response the
        | `web` group produces, and — under the `panel` block below — on every
        | response the administration panel produces. It is a blast-radius
        | control, not an XSS fix: the policy Planvio can honestly ship keeps
        | `'unsafe-inline'` and `'unsafe-eval'` in `script-src`, because every
        | Alpine `x-on:` handler in the product is an inline expression the
        | browser evaluates at runtime.
        | What it does buy is real — no script from another origin, no form post
        | to another origin, no `<base>` rewrite, no plugin or applet, and no
        | framing by a site you do not control. See docs/SECURITY.md §5.1.
        |
        | `report_only` sends the policy as Content-Security-Policy-Report-Only,
        | which browsers evaluate and log without enforcing. It is the safe way to
        | try a tightened `extra_directives` on a live installation.
        |
        | `extra_directives` is merged over the defaults, so a directive named
        | here replaces the shipped one and any other name is added. An empty
        | string removes a directive entirely. This is where an administrator who
        | has audited their own install tightens the policy — for example, after
        | confirming nothing on the page needs it:
        |
        |     'extra_directives' => [
        |         'script-src' => "'self' 'unsafe-eval'",   // drop unsafe-inline
        |         'frame-ancestors' => "'none'",
        |         'report-uri' => 'https://example.report-uri.com/r/d/csp/enforce',
        |     ],
        |
        | The attachment download controller sets its own, far stricter policy
        | (`default-src 'none'; sandbox`). The middleware never overwrites a
        | policy a response already carries, so that one keeps winning — on the
        | panel as well as on the product.
        |
        | `panel` is the same three settings for `/admin`. A Filament panel builds
        | its own middleware stack instead of running through the `web` group, so
        | the policy has to be named there separately; it is given its own block
        | because the panel and the product are audited by different people at
        | different times, and tightening one should not silently move the other.
        |
        | Every key in it is null as shipped, and null means "whatever the
        | application does" — so PLANVIO_CSP=false or PLANVIO_CSP_REPORT_ONLY=true
        | covers both surfaces without anyone having to know that `/admin` is
        | assembled differently. Set a key to override just the panel:
        |
        |     'panel' => [
        |         'enabled' => true,
        |         'report_only' => true,        // try a change on /admin only
        |         'extra_directives' => [],     // the shipped set, none of the
        |     ],                                // application's overrides
        |
        | The directives themselves are shared. Filament is Alpine and Livewire
        | too, and it serves its own JavaScript and CSS from /js/filament and
        | /css/filament on this origin, which `'self'` already covers.
        |
        */
        'csp' => [
            'enabled' => filter_var(env('PLANVIO_CSP', true), FILTER_VALIDATE_BOOL),
            'report_only' => filter_var(env('PLANVIO_CSP_REPORT_ONLY', false), FILTER_VALIDATE_BOOL),
            'extra_directives' => [],

            'panel' => [
                'enabled' => env('PLANVIO_ADMIN_CSP'),
                'report_only' => env('PLANVIO_ADMIN_CSP_REPORT_ONLY'),
                'extra_directives' => null,
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Rate limits
        |----------------------------------------------------------------------
        |
        | Requests per minute. Registered as named limiters in AppServiceProvider
        | and applied by `throttle:planvio-*`; the two limits with no route of
        | their own — search and starting an AI run from the product UI, both of
        | which arrive on Livewire's single update endpoint — are charged by
        | App\Support\RateLimits from inside the component.
        |
        | Every bucket is keyed on the user id, so one person's runaway tab
        | cannot spend a colleague's allowance, and an office behind one NAT
        | address is not one bucket. Signed-out traffic has no id and is keyed on
        | the address instead.
        |
        | The reasoning behind each number:
        |
        |   web    300  Five requests a second, sustained, for one signed-in
        |               person. A busy board with an AI run in flight polls a few
        |               times a second at its fastest and settles to one every
        |               few seconds; drag-reordering a column fires one request
        |               per drop. Nothing a human does approaches this, which is
        |               the point: the limiter exists to stop a stuck poller or a
        |               scripted loop from becoming a load test on shared hosting,
        |               not to pace real use.
        |
        |   guest  120  The signed-out surface is sign-in, registration and
        |               password reset — three forms, each already throttled far
        |               harder on the submitted email plus the address
        |               (`security.login`). This bucket only stops a flood of
        |               page loads, so it can afford to be well above what a
        |               shared office address plausibly generates.
        |
        |   search  60  One a second. Search is a `LIKE '%term%'` scan across
        |               several tables with no index that can serve it, so it is
        |               the most expensive read in the product. The command
        |               palette debounces to roughly two a second while somebody
        |               is typing, and a person types in bursts of a few seconds,
        |               so a minute of genuine searching lands well inside this.
        |
        |   ai_runs 10  Each run is a queued job that will call a paid provider
        |               and may loop for minutes. `ai.limits` already caps runs
        |               per user per hour and per workspace per day — those are
        |               the budget. This is the burst: it stops a double-clicked
        |               send or a retry loop from spending an hour's allowance in
        |               ten seconds, before the budget has a chance to notice.
        |
        |   exports 10  Every export streams the full row set for a workspace
        |               straight out of the database. Ten a minute is more than
        |               anybody clicks and far less than a script can ask for.
        |
        |   reports 20  A status report aggregates a project's tasks, milestones,
        |               time and budget in one render. It is a page somebody
        |               prints, refreshes and sends, so the ceiling is higher than
        |               exports — it is still a page, not a download.
        |
        */
        'rate_limits' => [
            'web' => (int) env('PLANVIO_RATE_WEB', 300),
            'guest' => (int) env('PLANVIO_RATE_GUEST', 120),
            'search' => (int) env('PLANVIO_RATE_SEARCH', 60),
            'ai_runs' => (int) env('PLANVIO_RATE_AI_RUNS', 10),
            'exports' => (int) env('PLANVIO_RATE_EXPORTS', 10),
            'reports' => (int) env('PLANVIO_RATE_REPORTS', 20),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults applied to newly created workspaces and projects
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'workspace' => [
            'timezone' => env('APP_TIMEZONE', 'UTC'),
            'locale' => env('APP_LOCALE', 'en'),
            'currency' => env('PLANVIO_CURRENCY', 'USD'),
            'date_format' => env('PLANVIO_DATE_FORMAT', 'Y-m-d'),
            'week_starts_on' => 1,
        ],
        'task_statuses' => [
            ['name' => 'Backlog', 'category' => 'backlog', 'color' => 'gray'],
            ['name' => 'To Do', 'category' => 'todo', 'color' => 'blue', 'is_default' => true],
            ['name' => 'In Progress', 'category' => 'in_progress', 'color' => 'brand'],
            ['name' => 'Review', 'category' => 'review', 'color' => 'purple'],
            ['name' => 'Blocked', 'category' => 'blocked', 'color' => 'red'],
            ['name' => 'Completed', 'category' => 'done', 'color' => 'green', 'is_completed' => true],
            ['name' => 'Cancelled', 'category' => 'cancelled', 'color' => 'gray', 'is_completed' => true],
        ],
        'project_statuses' => [
            ['name' => 'Planning', 'category' => 'todo', 'color' => 'blue', 'is_default' => true],
            ['name' => 'Active', 'category' => 'in_progress', 'color' => 'brand'],
            ['name' => 'On Hold', 'category' => 'blocked', 'color' => 'amber'],
            ['name' => 'Completed', 'category' => 'done', 'color' => 'green'],
            ['name' => 'Cancelled', 'category' => 'cancelled', 'color' => 'gray'],
        ],
        'tags' => [
            ['name' => 'Urgent', 'color' => 'red'],
            ['name' => 'Client', 'color' => 'purple'],
            ['name' => 'Internal', 'color' => 'gray'],
            ['name' => 'Design', 'color' => 'pink'],
            ['name' => 'Marketing', 'color' => 'orange'],
            ['name' => 'Finance', 'color' => 'teal'],
            ['name' => 'Operations', 'color' => 'blue'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reminders and scheduled maintenance
    |--------------------------------------------------------------------------
    */

    /*
    | Reminders are dispatched hourly and gated on each workspace's *local* `digest_hour`,
    | so a workspace hears from Planvio once, in its own morning, whatever timezone the
    | server keeps.
    |
    | `overdue_days` lists how far past due a notice goes out. Anything more frequent
    | teaches people to mute the category, which silences the useful reminders with it.
    */
    'reminders' => [
        'due_soon_days' => [3, 1],
        'send_overdue' => true,
        'overdue_days' => [1, 7],
        'digest_hour' => 8,
    ],

    // Days of history to keep, per record type. 0 means keep forever — the honest default
    // for the activity feed, which is the closest thing a project has to a memory.
    'retention' => [
        'activity_days' => (int) env('PLANVIO_ACTIVITY_RETENTION_DAYS', 0),
        'audit_days' => (int) env('PLANVIO_AUDIT_RETENTION_DAYS', 730),
        'notification_days' => 120,
        'webhook_delivery_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound webhooks
    |--------------------------------------------------------------------------
    |
    | Every delivery is signed: `X-Planvio-Signature` carries `t=<unix>,v1=<hmac>` where
    | the HMAC-SHA256 covers "<timestamp>.<raw body>" with the endpoint's secret. Signing
    | the timestamp along with the body is what makes a captured request unusable later —
    | a receiver rejects anything older than `tolerance_seconds`, and the timestamp cannot
    | be rewritten without invalidating the signature.
    |
    | An endpoint that keeps failing is switched off after `disable_after_failures`
    | consecutive failures rather than retried forever: a dead URL on an hourly project is
    | otherwise an unbounded queue of jobs nobody is watching.
    |
    */

    'webhooks' => [
        'tries' => (int) env('PLANVIO_WEBHOOK_TRIES', 4),
        // Seconds between attempts. The last value repeats if there are more tries.
        'backoff' => [60, 300, 900],
        'timeout' => (int) env('PLANVIO_WEBHOOK_TIMEOUT', 10),
        'connect_timeout' => 5,
        'tolerance_seconds' => 300,
        'disable_after_failures' => (int) env('PLANVIO_WEBHOOK_DISABLE_AFTER', 15),
        // Responses are stored for debugging, so they are truncated: an endpoint that
        // answers with a megabyte of HTML must not be able to fill the database.
        'max_response_bytes' => 2048,
        'headers' => [
            'signature' => 'X-Planvio-Signature',
            'timestamp' => 'X-Planvio-Timestamp',
            'event' => 'X-Planvio-Event',
            'delivery' => 'X-Planvio-Delivery',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination and query guards
    |--------------------------------------------------------------------------
    |
    | Planvio targets shared hosting: every list is paginated and every board
    | column is capped so a large project can never load thousands of rows.
    |
    */

    'pagination' => [
        'list' => 50,
        'board_column' => 25,
        'search' => 20,
        'activity' => 30,
        'api' => 50,
        'api_max' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance mode
    |--------------------------------------------------------------------------
    |
    | Database backed, so an administrator can toggle it from the UI without CLI
    | access. Platform admins keep access while it is engaged.
    |
    */

    'maintenance' => [
        'setting_key' => 'maintenance.enabled',
        'message_key' => 'maintenance.message',
    ],

];
