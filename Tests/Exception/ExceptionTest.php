<?php

namespace Flaphl\Element\Cache\Tests\Exception;

use PHPUnit\Framework\TestCase;
use Flaphl\Element\Cache\Exception\CacheException;
use Flaphl\Element\Cache\Exception\InvalidArgumentException;
use Flaphl\Element\Cache\Exception\LogicException;
use Flaphl\Element\Cache\Exception\BadMethodCallException;

/**
 * Tests for cache exception classes.
 *
 * @package Flaphl\Element\Cache\Tests\Exception
 */
class ExceptionTest extends TestCase
{
    public function testCacheExceptionImplementsPsrInterface(): void
    {
        $exception = new CacheException('Test message');
        
        $this->assertInstanceOf(\Psr\Cache\CacheException::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertEquals('Test message', $exception->getMessage());
    }

    public function testInvalidArgumentExceptionImplementsPsrInterface(): void
    {
        $exception = new InvalidArgumentException('Invalid argument');
        
        $this->assertInstanceOf(\Psr\Cache\InvalidArgumentException::class, $exception);
        $this->assertInstanceOf(CacheException::class, $exception);
        $this->assertEquals('Invalid argument', $exception->getMessage());
    }

    public function testCacheExceptionFactoryMethods(): void
    {
        $operation = 'get';
        $key = 'test.key';
        $previous = new \Exception('Previous error');
        
        $exception = CacheException::forOperation($operation, $key, $previous);
        
        $this->assertInstanceOf(CacheException::class, $exception);
        $this->assertStringContainsString($operation, $exception->getMessage());
        $this->assertStringContainsString($key, $exception->getMessage());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testCacheExceptionForAdapter(): void
    {
        $adapter = 'ArrayAdapter';
        $reason = 'Memory limit exceeded';
        
        $exception = CacheException::forAdapter($adapter, $reason);
        
        $this->assertInstanceOf(CacheException::class, $exception);
        $this->assertStringContainsString($adapter, $exception->getMessage());
        $this->assertStringContainsString($reason, $exception->getMessage());
    }

    public function testInvalidArgumentExceptionFactoryMethods(): void
    {
        $key = 'invalid{}key';
        
        $exception = InvalidArgumentException::forInvalidKey($key);
        
        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString($key, $exception->getMessage());
        $this->assertStringContainsString('Invalid cache key', $exception->getMessage());
    }

    public function testInvalidArgumentExceptionForInvalidTtl(): void
    {
        $ttl = -1;
        
        $exception = InvalidArgumentException::forInvalidTtl($ttl);
        
        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('TTL', $exception->getMessage());
        $this->assertStringContainsString((string)$ttl, $exception->getMessage());
    }

    public function testInvalidArgumentExceptionForInvalidTag(): void
    {
        $tag = 'invalid{}tag';
        
        $exception = InvalidArgumentException::forInvalidTag($tag);
        
        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString($tag, $exception->getMessage());
        $this->assertStringContainsString('Invalid tag', $exception->getMessage());
    }

    public function testLogicExceptionForInvalidConfiguration(): void
    {
        $setting = 'max_items';
        $reason = 'Must be greater than zero';
        
        $exception = LogicException::forInvalidConfiguration($setting, $reason);
        
        $this->assertInstanceOf(LogicException::class, $exception);
        $this->assertInstanceOf(CacheException::class, $exception);
        $this->assertStringContainsString($setting, $exception->getMessage());
        $this->assertStringContainsString($reason, $exception->getMessage());
    }

    public function testLogicExceptionForUnsupportedOperation(): void
    {
        $operation = 'complex_query';
        $adapter = 'SimpleAdapter';
        
        $exception = LogicException::forUnsupportedOperation($operation, $adapter);
        
        $this->assertInstanceOf(LogicException::class, $exception);
        $this->assertStringContainsString($operation, $exception->getMessage());
        $this->assertStringContainsString($adapter, $exception->getMessage());
    }

    public function testBadMethodCallException(): void
    {
        $method = 'unsupportedMethod';
        $class = 'TestClass';
        
        $exception = BadMethodCallException::forUndefinedMethod($method, $class);
        
        $this->assertInstanceOf(BadMethodCallException::class, $exception);
        $this->assertStringContainsString($method, $exception->getMessage());
        $this->assertStringContainsString($class, $exception->getMessage());
    }

    public function testExceptionChaining(): void
    {
        $rootCause = new \Exception('Root cause');
        $cacheException = new CacheException('Cache error', 0, $rootCause);
        $invalidArgException = new InvalidArgumentException('Invalid argument', 0, $cacheException);
        
        $this->assertSame($cacheException, $invalidArgException->getPrevious());
        $this->assertSame($rootCause, $cacheException->getPrevious());
        
        // Test traversal
        $current = $invalidArgException;
        $messages = [];
        
        while ($current !== null) {
            $messages[] = $current->getMessage();
            $current = $current->getPrevious();
        }
        
        $this->assertEquals([
            'Invalid argument',
            'Cache error',
            'Root cause'
        ], $messages);
    }

    public function testExceptionCodePropagation(): void
    {
        $code = 1001;
        $exception = new CacheException('Test message', $code);
        
        $this->assertEquals($code, $exception->getCode());
    }

    public function testExceptionContext(): void
    {
        $operation = 'set';
        $key = 'test.key';
        $context = ['ttl' => 3600, 'tags' => ['category']];
        
        $exception = CacheException::forOperation($operation, $key);
        
        // Test that exception message contains relevant context
        $message = $exception->getMessage();
        $this->assertStringContainsString($operation, $message);
        $this->assertStringContainsString($key, $message);
    }
}