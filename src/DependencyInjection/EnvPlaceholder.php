<?php

declare(strict_types=1);

namespace JOetjen\CooperSymfony\DependencyInjection;

/**
 * A Symfony `%env(...)%` placeholder standing where a CASC `${NAME}`
 * was written, on its way from Cooper's load into the container.
 *
 * An object rather than the string itself so that nothing between
 * Cooper and the container can mistake it for the variable's value: a
 * filter or tag applied to it (`@{port | trim}` over a `${PORT}`) fails
 * loudly instead of rewriting the placeholder's text. Interpolated into
 * a string, it is its text, which Symfony resolves inside the string.
 *
 * @internal
 */
final class EnvPlaceholder implements \Stringable
{
    /**
     * @param string $expression the whole placeholder, `%env(int:PORT)%`
     */
    public function __construct(public readonly string $expression)
    {
    }

    public function __toString(): string
    {
        return $this->expression;
    }
}
