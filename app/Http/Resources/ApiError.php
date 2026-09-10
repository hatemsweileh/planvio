<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Exceptions\DomainException;
use DomainException as BaseDomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * The one shape every failed API response has.
 *
 *     {
 *       "error": {
 *         "status": 422,
 *         "code": "validation_failed",
 *         "message": "The title field is required.",
 *         "fields": { "title": ["The title field is required."] }
 *       }
 *     }
 *
 * `status` repeats the HTTP status so a client that has lost the response headers — a log
 * line, a queued retry, a fetch wrapper that only kept the body — can still tell what
 * happened. `code` is the stable machine value and is the only field worth branching on:
 * `message` is written for a person and is translated, so it changes with the caller's
 * locale. `fields` appears only when the failure is per-field.
 *
 * ## Nothing here may leak
 *
 * A thrown exception's message can carry a DSN, a file path, a query with its parameters
 * bound in, or a URL with credentials in it. None of that belongs in a response, so an
 * unmapped throwable becomes a flat sentence and the real message goes to the log where it
 * belongs. Even the class name is withheld unless `app.debug` is on (CLAUDE.md rule 4).
 */
final class ApiError
{
    /**
     * @param array<string, list<string>> $fields
     */
    public static function response(
        int $status,
        string $code,
        string $message,
        array $fields = [],
    ): JsonResponse {
        $error = [
            'status' => $status,
            'code' => $code,
            'message' => $message,
        ];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return response()->json(['error' => $error], $status);
    }

    /**
     * Map a throwable onto the envelope, keeping the headers that carry meaning.
     *
     * A 429 without `Retry-After` tells a client to guess, and a client that guesses retries
     * too soon and extends its own lockout. Symfony's HTTP exceptions carry those headers —
     * `Retry-After` and the `X-RateLimit-*` trio from the throttle middleware — and rebuilding
     * the response as JSON must not drop them.
     */
    public static function from(Throwable $e): JsonResponse
    {
        $response = self::map($e);

        if ($e instanceof HttpExceptionInterface) {
            $response->withHeaders($e->getHeaders());
        }

        return $response;
    }

    /**
     * The status, code and sentence for a throwable.
     *
     * The order is specific-to-general: every branch above the last one describes a refusal
     * the API makes on purpose, and the last one describes a fault.
     */
    private static function map(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            /** @var array<string, list<string>> $fields */
            $fields = $e->errors();

            return self::response(422, 'validation_failed', self::firstMessage($fields, $e->getMessage()), $fields);
        }

        if ($e instanceof BaseDomainException) {
            /*
             | A refused request, not a fault: an Action was asked for something the model
             | cannot represent — a due date before the start date, a dependency that would
             | close a loop, a board column from another project.
             |
             | The catch is on PHP's `\DomainException` rather than only on Planvio's own
             | subclass, because the Actions layer uses both: the exceptions in
             | `App\Exceptions` extend {@see DomainException} and carry a `context()` array,
             | while the ones that live next to the action they are thrown from
             | (`App\Actions\Tasks\TaskStatusNotInProject` and its neighbours) extend the SPL
             | class directly. Every one of them is constructed with a translated sentence
             | written for the person who triggered it, so every one of them is safe to
             | return — and a caller should not have to learn which half of the hierarchy an
             | Action happened to use.
             */
            return $e instanceof DomainException
                ? self::withContext(422, 'rule_violated', $e->userMessage(), $e->context())
                : self::response(422, 'rule_violated', $e->getMessage());
        }

        if ($e instanceof AuthenticationException) {
            return self::response(401, 'unauthenticated', __('This endpoint requires a valid API token.'));
        }

        // Laravel's handler turns an AuthorizationException into an AccessDeniedHttpException
        // before a render callback ever sees it, so the previous-exception chain is where the
        // original actually is. Both are checked: the framework's own wording
        // ("This action is unauthorized.") is Planvio's to replace, and the status is 403
        // either way.
        if ($e instanceof AuthorizationException || $e->getPrevious() instanceof AuthorizationException) {
            return self::response(403, 'forbidden', __('You are not allowed to do that.'));
        }

        if ($e instanceof ModelNotFoundException) {
            return self::response(404, 'not_found', __('No such record.'));
        }

        if ($e instanceof TooManyRequestsHttpException) {
            return self::response(429, 'rate_limited', __('Too many requests. Slow down and try again shortly.'));
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return self::response(405, 'method_not_allowed', __('That method is not supported on this endpoint.'));
        }

        if ($e instanceof NotFoundHttpException) {
            return self::response(404, 'not_found', self::sentence($e, __('No such endpoint or record.')));
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return self::response($status, self::codeFor($status), self::sentence($e, self::defaultMessage($status)));
        }

        return self::response(500, 'server_error', config('app.debug') === true
            ? $e::class
            : __('Something went wrong. The failure has been logged.'));
    }

    /**
     * A domain refusal, with the machine-readable detail the Action attached.
     *
     * @param array<string, scalar|array<array-key, mixed>|null> $context
     */
    private static function withContext(int $status, string $code, string $message, array $context): JsonResponse
    {
        $error = [
            'status' => $status,
            'code' => $code,
            'message' => $message,
        ];

        if ($context !== []) {
            $error['context'] = $context;
        }

        return response()->json(['error' => $error], $status);
    }

    /**
     * An HTTP exception's own message when it has one — `abort(403, 'You do not have access
     * to this workspace.')` writes a better sentence than a per-status default — and the
     * default otherwise, since a bare `abort()` leaves the message empty.
     *
     * Only ever called for {@see HttpExceptionInterface}, whose message is written by this
     * application at the abort site. A framework or vendor throwable never reaches it.
     */
    private static function sentence(Throwable $e, string $fallback): string
    {
        $message = trim($e->getMessage());

        return $message === '' ? $fallback : $message;
    }

    /**
     * @param array<string, list<string>> $fields
     */
    private static function firstMessage(array $fields, string $fallback): string
    {
        foreach ($fields as $messages) {
            foreach ($messages as $message) {
                return $message;
            }
        }

        return $fallback;
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            413 => 'payload_too_large',
            415 => 'unsupported_media_type',
            422 => 'validation_failed',
            429 => 'rate_limited',
            503 => 'unavailable',
            default => $status >= 500 ? 'server_error' : 'request_failed',
        };
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => __('The request could not be understood.'),
            401 => __('This endpoint requires a valid API token.'),
            403 => __('You are not allowed to do that.'),
            404 => __('No such endpoint or record.'),
            409 => __('That conflicts with the current state of the record.'),
            413 => __('The request body is too large.'),
            422 => __('The request could not be processed.'),
            429 => __('Too many requests. Slow down and try again shortly.'),
            503 => __('Planvio is temporarily unavailable.'),
            default => __('The request failed.'),
        };
    }
}
