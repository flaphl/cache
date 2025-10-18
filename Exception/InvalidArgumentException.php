<?php

/**
 * This file is part of the Flaphl package.
 * 
 * (c) Jade Phyressi <jade@flaphl.com>
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flaphl\Element\Cache\Exception;

use Psr\Cache\InvalidArgumentException as PsrInvalidArgumentException;

/**
 * Exception thrown when an invalid argument is passed to a cache operation.
 * 
 * This exception maintains PSR-6 compliance while providing enhanced
 * error reporting for cache key validation and parameter checking.
 */
class InvalidArgumentException extends CacheException implements PsrInvalidArgumentException
{
    /**
     * Create a new invalid argument exception.
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
     * Create an exception for invalid cache keys.
     *
     * @param mixed $key The invalid key
     * @param string $reason Additional reason for invalidity
     * @return static
     */
    public static function forInvalidKey(mixed $key, string $reason = ''): static
    {
        $keyType = get_debug_type($key);
        $message = sprintf('Invalid cache key of type "%s"', $keyType);
        
        if (is_string($key)) {
            $message = sprintf('Invalid cache key "%s"', $key);
        }
        
        if ($reason) {
            $message .= ': ' . $reason;
        } else {
            $message .= '. Cache keys must be non-empty strings without reserved characters {}()/\@:';
        }
        
        return new static($message);
    }

    /**
     * Create an exception for invalid TTL values.
     *
     * @param mixed $ttl The invalid TTL value
     * @return static
     */
    public static function forInvalidTtl(mixed $ttl): static
    {
        $ttlType = get_debug_type($ttl);
        $message = sprintf(
            'Invalid TTL value "%s" of type "%s". Expected null, int, or DateInterval.',
            $ttl,
            $ttlType
        );
        
        return new static($message);
    }

    /**
     * Create an exception for invalid tag names.
     *
     * @param mixed $tag The invalid tag
     * @return static
     */
    public static function forInvalidTag(mixed $tag): static
    {
        $tagType = get_debug_type($tag);
        $tagValue = is_string($tag) ? $tag : var_export($tag, true);
        $message = sprintf(
            'Invalid tag "%s" of type "%s". Tags must be non-empty strings.',
            $tagValue,
            $tagType
        );
        
        return new static($message);
    }

    /**
     * Create an exception for empty or null required parameters.
     *
     * @param string $parameter The parameter name
     * @return static
     */
    public static function forEmptyParameter(string $parameter): static
    {
        $message = sprintf('Parameter "%s" cannot be empty or null.', $parameter);
        return new static($message);
    }
}
