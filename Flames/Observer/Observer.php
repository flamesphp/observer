<?php

declare(strict_types=1);

namespace Flames\Observer;

/**
 * Proxy object used by the stream wrapper test suite.
 *
 * Wraps a value and fires a callback whenever the value is written via
 * $proxy->value = x  (intercepted by the property hook).
 *
 * Uses PHP 8.4 property hooks instead of __get/__set so that stack traces
 * show the original assignment line rather than a magic-method frame —
 * the developer never sees that the code was transformed.
 *
 * Matched by TokenTransformer via the pattern:
 *   $proxy = TestObserver::watch($varName, $callback)
 */
final class Observer
{
    private mixed $delegate = null;

    public mixed $value {
        get { return $this->_value; }
        set(mixed $value) {
            if ($this->delegate !== null) ($this->delegate)($value);
            $this->_value = $value;
        }
    }

    private mixed $_value = null;

    public function __construct(mixed $initialValue, callable $delegate)
    {
        $this->delegate = $delegate;
        $this->_value   = $initialValue;
    }

    /** Factory — the ::watch() call is the marker detected by TokenTransformer. */
    public static function watch(mixed $initialValue, callable $delegate): self
    {
        return new self($initialValue, $delegate);
    }
}
