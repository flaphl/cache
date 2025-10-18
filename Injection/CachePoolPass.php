<?php

/**
 * This file is part of the Flaphl package.
 * 
 * (c) Jade Phyressi <jade@flaphl.com>
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flaphl\Element\Cache\Injection;

use Flaphl\Element\Injection\ContainerBuilder;
use Flaphl\Element\Cache\Adapter\TagAwareAdapter;

/**
 * Compiler pass for registering and configuring cache pools.
 * 
 * This pass processes cache pool definitions and automatically
 * configures adapters, tagging support, and service registrations.
 */
class CachePoolPass
{
    private const CACHE_POOL_TAG = 'cache.pool';
    private const CACHE_ADAPTER_TAG = 'cache.adapter';

    private array $config;

    /**
     * Create a new cache pool pass.
     *
     * @param array $config Configuration options
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'default_adapter' => 'cache.adapter.array',
            'enable_tag_aware' => true,
            'default_namespace' => '',
        ], $config);
    }

    /**
     * Process the container builder.
     *
     * @param ContainerBuilder $container The container builder
     */
    public function process(ContainerBuilder $container): void
    {
        $this->setDefaultParameters($container);
        $this->registerDefaultAdapters($container);
        $this->setupTagAwareServices($container);
    }

    /**
     * Set default cache parameters.
     *
     * @param ContainerBuilder $container The container builder
     */
    private function setDefaultParameters(ContainerBuilder $container): void
    {
        $defaults = [
            'cache.default_namespace' => '',
            'cache.array.max_items' => 1000,
            'cache.array.enable_expiration' => true,
            'cache.filesystem.directory' => sys_get_temp_dir() . '/flaphl_cache',
            'cache.filesystem.default_ttl' => 3600,
            'cache.php_array.file' => sys_get_temp_dir() . '/flaphl_cache.php',
            'cache.php_array.auto_save' => true,
            'cache.php_files.directory' => sys_get_temp_dir() . '/flaphl_cache_files',
            'cache.php_files.default_ttl' => 3600,
            'cache.tag_aware.prefix' => '__tag__',
            'cache.tag_aware.max_tags_per_item' => 100,
        ];

        foreach ($defaults as $key => $value) {
            $container->setParameter($key, $value);
        }
    }

    /**
     * Register default cache adapters.
     *
     * @param ContainerBuilder $container The container builder
     */
    private function registerDefaultAdapters(ContainerBuilder $container): void
    {
        // Array Adapter
        $container->register('cache.adapter.array', 'Flaphl\Element\Cache\Adapter\ArrayAdapter')
            ->addArgument('%cache.default_namespace%')
            ->addArgument('%cache.array.max_items%')
            ->addArgument([
                'max_items' => '%cache.array.max_items%',
                'enable_expiration' => '%cache.array.enable_expiration%',
            ])
            ->addTag(self::CACHE_ADAPTER_TAG, ['alias' => 'array']);

        // Null Adapter
        $container->register('cache.adapter.null', 'Flaphl\Element\Cache\Adapter\NullAdapter')
            ->addArgument('%cache.default_namespace%')
            ->addArgument([])
            ->addTag(self::CACHE_ADAPTER_TAG, ['alias' => 'null']);

        // Filesystem Adapter
        $container->register('cache.adapter.filesystem', 'Flaphl\Element\Cache\Adapter\FilesystemAdapter')
            ->addArgument('%cache.filesystem.directory%')
            ->addArgument('%cache.default_namespace%')
            ->addArgument('%cache.filesystem.default_ttl%')
            ->addArgument([
                'default_ttl' => '%cache.filesystem.default_ttl%',
                'file_mode' => 0666,
                'dir_mode' => 0777,
                'hash_algo' => 'xxh128',
                'enable_gc' => true,
                'gc_probability' => 0.01,
            ])
            ->addTag(self::CACHE_ADAPTER_TAG, ['alias' => 'filesystem']);

        // PHP Array Adapter
        $container->register('cache.adapter.php_array', 'Flaphl\Element\Cache\Adapter\PhpArrayAdapter')
            ->addArgument('%cache.php_array.file%')
            ->addArgument('%cache.default_namespace%')
            ->addArgument([
                'auto_save' => '%cache.php_array.auto_save%',
                'pretty_print' => false,
                'enable_expiration' => true,
                'backup_copies' => 1,
            ])
            ->addTag(self::CACHE_ADAPTER_TAG, ['alias' => 'php_array']);

        // PHP Files Adapter
        $container->register('cache.adapter.php_files', 'Flaphl\Element\Cache\Adapter\PhpFilesAdapter')
            ->addArgument('%cache.php_files.directory%')
            ->addArgument('%cache.default_namespace%')
            ->addArgument('%cache.php_files.default_ttl%')
            ->addArgument([
                'default_ttl' => '%cache.php_files.default_ttl%',
                'file_mode' => 0666,
                'dir_mode' => 0777,
                'hash_algo' => 'xxh128',
                'enable_gc' => true,
                'gc_probability' => 0.01,
                'enable_opcache' => true,
                'pretty_print' => false,
            ])
            ->addTag(self::CACHE_ADAPTER_TAG, ['alias' => 'php_files']);
    }

    /**
     * Set up tag-aware services.
     *
     * @param ContainerBuilder $container The container builder
     */
    private function setupTagAwareServices(ContainerBuilder $container): void
    {
        if (!$this->config['enable_tag_aware']) {
            return;
        }

        // Create tag-aware versions of adapters
        $adapterServices = ['array', 'filesystem', 'php_array', 'php_files'];
        
        foreach ($adapterServices as $adapter) {
            $serviceId = 'cache.adapter.' . $adapter;
            $tagAwareServiceId = $serviceId . '.tag_aware';
            
            $container->register($tagAwareServiceId, TagAwareAdapter::class)
                ->addArgument('@' . $serviceId)
                ->addArgument('@cache.adapter.array') // Default tag index adapter
                ->addArgument([
                    'tag_prefix' => '%cache.tag_aware.prefix%',
                    'max_tags_per_item' => '%cache.tag_aware.max_tags_per_item%',
                    'enable_tag_stats' => true,
                    'auto_prune_orphaned' => false,
                ])
                ->addTag(self::CACHE_ADAPTER_TAG, ['alias' => $adapter . '_tag_aware'])
                ->addTag('cache.tag_aware');
        }
    }
}
