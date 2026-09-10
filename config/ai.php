<?php

declare(strict_types=1);
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\CustomHttpProvider;
use App\Ai\Providers\OpenAiCompatibleProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | This is the outermost gate. When false, no AI route, job, tool or provider
    | call runs anywhere in the application, regardless of per-workspace settings.
    | Everything else in Planvio keeps working normally.
    |
    */

    'enabled' => env('AI_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Driver definitions consumed by App\Ai\Providers\*. Concrete credentials live
    | in the ai_providers table (api_key is encrypted at rest), never here and
    | never in the frontend bundle.
    |
    */

    'drivers' => [

        'openai' => [
            'class' => OpenAiCompatibleProvider::class,
            'label' => 'OpenAI',
            'base_url' => 'https://api.openai.com/v1',
            'supports_tools' => true,
            'supports_temperature' => true,
            'default_model' => 'gpt-4o-mini',
            'suggested_models' => ['gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4.1-mini'],
        ],

        'anthropic' => [
            'class' => AnthropicProvider::class,
            'label' => 'Anthropic',
            'base_url' => 'https://api.anthropic.com/v1',
            'supports_tools' => true,
            'supports_temperature' => true,
            'default_model' => 'claude-sonnet-4-5',
            'suggested_models' => ['claude-opus-4-5', 'claude-sonnet-4-5', 'claude-haiku-4-5'],
            'api_version' => '2023-06-01',
        ],

        'openai_compatible' => [
            'class' => OpenAiCompatibleProvider::class,
            'label' => 'OpenAI-compatible endpoint',
            'base_url' => null,
            'supports_tools' => true,
            'supports_temperature' => true,
            'default_model' => null,
            // Covers Azure OpenAI, OpenRouter, Groq, Together, Mistral, Ollama,
            // LM Studio, vLLM and anything else exposing /chat/completions.
            'requires_base_url' => true,
        ],

        'custom_http' => [
            'class' => CustomHttpProvider::class,
            'label' => 'Custom HTTP endpoint',
            'base_url' => null,
            'supports_tools' => false,
            'supports_temperature' => false,
            'requires_base_url' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Execution limits
    |--------------------------------------------------------------------------
    |
    | Ceilings, not defaults: a workspace may lower these but never raise them.
    | They exist to keep an agent loop bounded on shared hosting where a runaway
    | run would exhaust the PHP time limit or the provider budget.
    |
    */

    'limits' => [
        'max_tool_calls_per_run' => (int) env('AI_MAX_TOOL_CALLS', 25),
        'max_run_seconds' => (int) env('AI_MAX_RUN_SECONDS', 180),
        'max_same_tool_repeats' => 5,
        'max_errors_per_run' => 3,
        'max_context_tokens' => (int) env('AI_MAX_CONTEXT_TOKENS', 24000),
        'max_tool_result_chars' => 6000,
        'max_runs_per_user_per_hour' => (int) env('AI_MAX_RUNS_PER_HOUR', 60),
        'request_timeout_seconds' => (int) env('AI_TIMEOUT', 60),
        'connect_timeout_seconds' => 10,
        'max_retries' => 2,
        'retry_base_delay_ms' => 800,
    ],

    /*
    |--------------------------------------------------------------------------
    | Approval policy
    |--------------------------------------------------------------------------
    |
    | Any tool whose risk exceeds `auto_execute_max_risk` requires a human
    | approval even in autonomous mode. Tools listed in `always_require_approval`
    | require it unconditionally and cannot be waived by a workspace policy.
    |
    */

    'approvals' => [
        'auto_execute_max_risk' => [
            'assistant' => null,        // assistant mode executes nothing that mutates
            'copilot' => 'read',        // reads run freely, every mutation is confirmed
            'autonomous' => 'medium',   // up to medium risk runs unattended
        ],
        'always_require_approval' => [
            'delete_project',
            'delete_task',
            'remove_workspace_member',
            'archive_project',
        ],
        'approval_ttl_minutes' => 1440,
    ],

    /*
    |--------------------------------------------------------------------------
    | Context assembly
    |--------------------------------------------------------------------------
    |
    | Budgets for the retrieval layer. Planvio never dumps a workspace into a
    | prompt: each provider contributes a bounded slice and the builder truncates
    | oldest-first when the total would exceed max_context_tokens.
    |
    */

    'context' => [
        'max_projects' => 25,
        'max_tasks' => 40,
        'max_comments' => 15,
        'max_activities' => 25,
        'max_members' => 40,
        'max_wiki_excerpts' => 6,
        'max_memories' => 20,
        'excerpt_chars' => 400,
        'conversation_history_messages' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt-injection defence
    |--------------------------------------------------------------------------
    |
    | Every piece of workspace-derived text is wrapped in this element before it
    | reaches the model. The standing rule is repeated in the system prompt.
    |
    */

    'untrusted_wrapper' => 'untrusted-data',

    'injection_guard' => [
        'enabled' => true,
        // Logged for review when spotted inside retrieved content. Detection is a
        // signal for the audit trail, never the primary control: the structural
        // separation of instructions from data is what actually protects us.
        'suspicious_patterns' => [
            'ignore (all )?(previous|prior|above) instructions',
            'disregard (the )?(system|previous) (prompt|instructions)',
            'you are now',
            'new instructions:',
            'system prompt',
            '</?(system|developer|assistant)>',
            'reveal your (prompt|instructions|system)',
            'print your (prompt|instructions|api[ _-]?key)',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging and retention
    |--------------------------------------------------------------------------
    |
    | Prompt bodies are NOT persisted by default. Tool arguments are redacted
    | before storage. Nothing here may ever contain an API key.
    |
    */

    'logging' => [
        'store_prompts' => env('AI_STORE_PROMPTS', false),
        'store_tool_arguments' => true,
        'redact_keys' => [
            'api_key', 'apikey', 'password', 'secret', 'token', 'authorization',
            'access_token', 'refresh_token', 'private_key', 'client_secret',
        ],
        'max_stored_argument_chars' => 2000,
        'max_stored_summary_chars' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Automations
    |--------------------------------------------------------------------------
    |
    | Shared hosting may fire overlapping cron ticks, so every automation takes a
    | database lock before running and releases it on completion.
    |
    */

    'automations' => [
        'enabled' => env('AI_AUTOMATIONS_ENABLED', true),
        'lock_ttl_seconds' => 900,
        'max_per_tick' => 3,
        'failure_backoff_minutes' => [5, 30, 180],
        'disable_after_consecutive_failures' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */

    'queue' => [
        'connection' => env('AI_QUEUE_CONNECTION', 'database'),
        'name' => env('AI_QUEUE', 'ai'),
        'tries' => 2,
        'backoff' => [10, 60],
    ],

];
