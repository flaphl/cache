<?php

namespace Flaphl\Element\Cache\Exception;

/**
 * Exception thrown when a method is called on a cache adapter in an inappropriate way.
 * 
 * This exception is used for method call errors, such as calling methods
 * that are not available in the current context or with invalid parameters.
 */
class BadMethodCallException extends \BadMethodCallException
{
    /**
     * Create a new bad method call exception.
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
     * Create an exception for undefined methods.
     *
     * @param string $method The undefined method name
     * @param string $class The class name
     * @return static
     */
    public static function forUndefinedMethod(string $method, string $class): static
    {
        $message = sprintf(
            'Call to undefined method "%s::%s()".',
            $class,
            $method
        );
        
        return new static($message);
    }

    /**
     * Create an exception for methods called with wrong parameters.
     *
     * @param string $method The method name
     * @param int $expectedCount Expected parameter count
     * @param int $actualCount Actual parameter count
     * @return static
     */
    public static function forWrongParameterCount(string $method, int $expectedCount, int $actualCount): static
    {
        $message = sprintf(
            'Method "%s" expects %d parameters, %d given.',
            $method,
            $expectedCount,
            $actualCount
        );
        
        return new static($message);
    }

    /**
     * Create an exception for methods called in wrong context.
     *
     * @param string $method The method name
     * @param string $context Required context
     * @param string $actualContext Actual context
     * @return static
     */
    public static function forWrongContext(string $method, string $context, string $actualContext): static
    {
        $message = sprintf(
            'Method "%s" can only be called in "%s" context, currently in "%s".',
            $method,
            $context,
            $actualContext
        );
        
        return new static($message);
    }

    /**
     * Create an exception for methods that require specific features.
     *
     * @param string $method The method name
     * @param string $requiredFeature The required feature
     * @return static
     */
    public static function forMissingFeature(string $method, string $requiredFeature): static
    {
        $message = sprintf(
            'Method "%s" requires "%s" feature which is not available.',
            $method,
            $requiredFeature
        );
        
        return new static($message);
    }

    /**
     * Create an exception for read-only operations.
     *
     * @param string $method The method name
     * @return static
     */
    public static function forReadOnlyOperation(string $method): static
    {
        $message = sprintf(
            'Cannot call "%s" on read-only cache adapter.',
            $method
        );
        
        return new static($message);
    }
}
