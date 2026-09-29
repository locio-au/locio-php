<?php

declare(strict_types=1);

namespace Locio\Laravel;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\ValidationRule;
use Locio\Address;
use Locio\Client;
use Locio\Exception\LocioException;

/**
 * Passes an address that resolves to a real one, and fails one that does not.
 *
 *     $request->validate(['address' => ['required', $rule = new ResolvableAddress()]]);
 *     $order->address_id = $rule->address?->id;
 *
 * One unit per value checked. When the service cannot answer (a timeout, a
 * rate limit, a spent quota) the address passes and $error says why, because
 * an outage of ours should not stop somebody checking out. Pass strict: true
 * to fail instead.
 */
final class ResolvableAddress implements ValidationRule
{
    /** What the address resolved to, once validated. */
    public ?Address $address = null;

    /** Why the service could not answer, when it could not. */
    public ?LocioException $error = null;

    public function __construct(
        private readonly bool $strict = false,
        private readonly ?Client $client = null,
        private readonly string $message = 'The :attribute is not an address we can find.',
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $this->address = null;
        $this->error = null;

        if (!is_string($value) || trim($value) === '') {
            $fail($this->message);

            return;
        }

        try {
            $client = $this->client ?? Container::getInstance()->make(Client::class);
            $resolution = $client->resolve($value);
        } catch (LocioException $e) {
            $this->error = $e;
            if ($this->strict) {
                $fail('The :attribute could not be checked just now. Please try again.');
            }

            return;
        }

        if (!$resolution->matched || $resolution->address === null) {
            $fail($this->message);

            return;
        }
        $this->address = $resolution->address;
    }
}
