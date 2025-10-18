<?php

namespace Flaphl\Element\Cache\Exception;

use Psr\Cache\CacheException as PsrCacheException;

/**
 * Base cache exception interface that extends PSR-6 CacheException.
 * 
 * All cache-related exceptions should implement this interface to maintain
 * PSR-6 compliance while allowing for Flaphl-specific extensions.
 */
class CacheException extends \Exception implements PsrCacheException
{
    /**
     * Create a new cache exception.
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
     * Create an exception for cache operation failures.
     *
     * @param string $operation The operation that failed
     * @param string $reason Additional reason for failure
     * @param \Throwable|null $previous Previous exception
     * @return static
     */
    public static function forOperation(string $operation, string $reason = '', ?\Throwable $previous = null): static
    {
        $message = sprintf('Cache operation "%s" failed', $operation);
        if ($reason) {
            $message .= ': ' . $reason;
        }
        
        return new static($message, 0, $previous);
    }

    /**
     * Create an exception for adapter-specific errors.
     *
     * @param string $adapterClass The adapter class name
     * @param string $error The specific error
     * @param \Throwable|null $previous Previous exception
     * @return static
     */
    public static function forAdapter(string $adapterClass, string $error, ?\Throwable $previous = null): static
    {
        $message = sprintf('Cache adapter "%s" error: %s', $adapterClass, $error);
        return new static($message, 0, $previous);
    }
}
