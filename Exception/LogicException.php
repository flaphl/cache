<?php

namespace Flaphl\Element\Cache\Exception;

/**
 * Exception thrown when cache operations are called in an invalid sequence
 * or when the cache adapter is in an invalid state.
 * 
 * This exception is used for logical errors in cache usage patterns,
 * such as calling operations on closed adapters or invalid state transitions.
 */
class LogicException extends CacheException
{
    /**
     * Create a new logic exception.
     *
     * @param string $message The exception message
     * @param int $code The exception code
     * @param \Throwable|null $previous Previous exception for chaining
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create an exception for operations on closed adapters.
     *
     * @param string $operation The attempted operation
     * @return static
     */
    public static function forClosedAdapter(string $operation): static
    {
        $message = sprintf(
            'Cannot perform "%s" operation on a closed cache adapter.',
            $operation
        );
        
        return new static($message);
    }

    /**
     * Create an exception for invalid adapter states.
     *
     * @param string $expectedState The expected adapter state
     * @param string $actualState The actual adapter state
     * @return static
     */
    public static function forInvalidState(string $expectedState, string $actualState): static
    {
        $message = sprintf(
            'Cache adapter is in invalid state "%s", expected "%s".',
            $actualState,
            $expectedState
        );
        
        return new static($message);
    }

    /**
     * Create an exception for unsupported operations.
     *
     * @param string $operation The unsupported operation
     * @param string $adapterClass The adapter class name
     * @return static
     */
    public static function forUnsupportedOperation(string $operation, string $adapterClass): static
    {
        $message = sprintf(
            'Operation "%s" is not supported by adapter "%s".',
            $operation,
            $adapterClass
        );
        
        return new static($message);
    }

    /**
     * Create an exception for invalid configuration.
     *
     * @param string $configKey The configuration key
     * @param string $reason The reason for invalidity
     * @return static
     */
    public static function forInvalidConfiguration(string $configKey, string $reason): static
    {
        $message = sprintf(
            'Invalid configuration for "%s": %s',
            $configKey,
            $reason
        );
        
        return new static($message);
    }

    /**
     * Create an exception for circular dependencies.
     *
     * @param array $dependencyChain The circular dependency chain
     * @return static
     */
    public static function forCircularDependency(array $dependencyChain): static
    {
        $chain = implode(' -> ', $dependencyChain);
        $message = sprintf(
            'Circular dependency detected in cache configuration: %s',
            $chain
        );
        
        return new static($message);
    }
}
