<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Formats;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * The installation's password policy, read from `config('planvio.security.password')`.
 *
 * One rule object rather than a rules array copied into six form requests: an administrator
 * who raises the minimum length gets the new floor everywhere at once, including on the
 * installer's first-admin form and on password changes made from the admin panel.
 *
 * The work itself is delegated to Laravel's own {@see Password} rule — it already carries
 * translated messages for every clause and the k-anonymity lookup behind `uncompromised()`.
 * This class decides *which* clauses apply.
 */
final class StrongPassword implements DataAwareRule, ValidationRule, ValidatorAwareRule
{
    /**
     * bcrypt hashes at most 72 bytes and silently ignores everything after them, so two
     * different long passphrases sharing a 72-byte prefix would authenticate each other.
     * Refusing the input is honest; truncating it is not.
     */
    public const MAX_LENGTH = 72;

    private readonly Password $password;

    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param array<string, mixed>|null $overrides policy values replacing the configured ones
     */
    public function __construct(?array $overrides = null)
    {
        $this->password = self::rule($overrides);
    }

    /**
     * The configured policy as a Laravel password rule.
     *
     * Also the callback behind `Password::defaults()`, so `Password::default()` anywhere in
     * the application resolves to exactly this policy.
     *
     * @param array<string, mixed>|null $overrides
     */
    public static function rule(?array $overrides = null): Password
    {
        $policy = $overrides ?? self::policy();

        $rule = Password::min(self::minLength($policy))->max(self::MAX_LENGTH);

        if (self::flag($policy, 'require_mixed_case')) {
            $rule = $rule->mixedCase();
        }

        if (self::flag($policy, 'require_numbers')) {
            $rule = $rule->numbers();
        }

        if (self::flag($policy, 'require_symbols')) {
            $rule = $rule->symbols();
        }

        if (self::flag($policy, 'check_compromised')) {
            $rule = $rule->uncompromised();
        }

        return $rule;
    }

    /**
     * A human-readable summary of the policy, for the hint under a password field.
     */
    public static function description(): string
    {
        $policy = self::policy();

        $clauses = [__('at least :count characters', ['count' => self::minLength($policy)])];

        if (self::flag($policy, 'require_mixed_case')) {
            $clauses[] = __('upper and lower case');
        }

        if (self::flag($policy, 'require_numbers')) {
            $clauses[] = __('a number');
        }

        if (self::flag($policy, 'require_symbols')) {
            $clauses[] = __('a symbol');
        }

        // The comma between the clauses is punctuation, and punctuation is language: an
        // Arabic list is separated by ، rather than by an ASCII comma.
        return __('Use :requirements.', ['requirements' => Formats::list($clauses)]);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Password::passes() skips the attribute unless it is present in the data it was
        // handed. Writing the value back in keeps the rule meaningful when it is applied to
        // a value the validator never saw as input, such as a confirmed nested field.
        $data = $this->data;
        Arr::set($data, $attribute, $value);

        $this->password->setData($data);

        if ($this->password->passes($attribute, $value)) {
            return;
        }

        foreach ((array) $this->password->message() as $message) {
            $fail((string) $message);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setData($data): static
    {
        $this->data = $data;

        return $this;
    }

    public function setValidator(Validator $validator): static
    {
        $this->password->setValidator($validator);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    private static function policy(): array
    {
        $policy = config('planvio.security.password');

        return is_array($policy) ? $policy : [];
    }

    /**
     * @param array<string, mixed> $policy
     */
    private static function minLength(array $policy): int
    {
        $min = $policy['min_length'] ?? 10;

        return max(8, min(self::MAX_LENGTH, is_numeric($min) ? (int) $min : 10));
    }

    /**
     * @param array<string, mixed> $policy
     */
    private static function flag(array $policy, string $key): bool
    {
        return filter_var($policy[$key] ?? false, FILTER_VALIDATE_BOOL);
    }
}
