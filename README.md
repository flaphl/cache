# Flaphl Cache Element

**PSR-6 cache implementation pulled from Flaphl elements.**

## Installation

```bash
composer require flaphl/cache
```

## Features

- **PSR-6 Cache compliance** - Full compatibility with PSR Cache interfaces
- **Multiple adapters** - Array, Filesystem, PHP Array, PHP Files, and Null adapters
- **Tag-aware caching** - Cache invalidation by tags
- **Advanced expiration** - TTL, DateTime, and DateInterval support
- **Concurrent access control** - File and memory-based locking mechanisms
- **Comprehensive testing** - Extensive test suite with integration tests

## Basic Usage

```php
use Flaphl\Element\Cache\Adapter\ArrayAdapter;

// Create cache adapter
$cache = new ArrayAdapter('my_namespace');

// Store an item
$item = $cache->getItem('user.123');
$item->set(['name' => 'John Doe', 'email' => 'john@example.com']);
$item->expiresAfter(3600); // 1 hour
$cache->save($item);

// Retrieve an item
$item = $cache->getItem('user.123');
if ($item->isHit()) {
    $userData = $item->get();
}
```

## Tag-Aware Caching

```php
use Flaphl\Element\Cache\Adapter\TagAwareAdapter;

$tagAwareCache = new TagAwareAdapter($cache, $tagIndexCache);

// Save item with tags
$item = $tagAwareCache->getItem('product.456');
$item->set(['name' => 'Widget', 'price' => 29.99]);
$item->tag(['products', 'category:widgets']);
$tagAwareCache->save($item);

// Invalidate by tags
$tagAwareCache->invalidateTags(['products']);
```

## Available Adapters

- **ArrayAdapter** - In-memory storage with optional size limits
- **FilesystemAdapter** - File-based persistent storage
- **PhpArrayAdapter** - Single PHP file array storage
- **PhpFilesAdapter** - Multiple PHP files with opcache optimization
- **NullAdapter** - No-op adapter for testing/debugging
- **TagAwareAdapter** - Decorator adding tagging support

## License

This package is licensed under the MIT License. See the LICENSE file for details.
