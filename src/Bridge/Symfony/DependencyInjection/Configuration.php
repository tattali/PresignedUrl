<?php

declare(strict_types=1);

namespace Tattali\PresignedUrl\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('presigned_url');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('secret')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('base_url')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('signature')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('algorithm')->defaultValue('sha256')->end()
                        ->integerNode('length')->defaultValue(16)->end()
                        ->scalarNode('expires_param')->defaultValue('X-Expires')->end()
                        ->scalarNode('signature_param')->defaultValue('X-Signature')->end()
                    ->end()
                ->end()
                ->arrayNode('serving')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('default_ttl')->defaultValue(3600)->end()
                        ->integerNode('max_ttl')->defaultValue(86400)->end()
                        ->scalarNode('cache_control')->defaultValue('private, max-age=3600, must-revalidate')->end()
                        ->scalarNode('content_disposition')->defaultValue('inline')->end()
                        ->arrayNode('compression')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultTrue()->end()
                                ->integerNode('min_size')->defaultValue(1024)->end()
                                ->integerNode('level')->defaultValue(6)->end()
                                ->arrayNode('types')
                                    ->scalarPrototype()->end()
                                    ->defaultValue([
                                        'text/plain',
                                        'text/html',
                                        'text/css',
                                        'text/xml',
                                        'text/javascript',
                                        'application/javascript',
                                        'application/json',
                                        'application/xml',
                                        'image/svg+xml',
                                    ])
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('allowed_extensions')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('blocked_extensions')
                            ->scalarPrototype()->end()
                            ->defaultValue(['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar', 'exe', 'sh', 'bat', 'cmd'])
                        ->end()
                        ->integerNode('max_file_size')->defaultValue(0)->end()
                        ->arrayNode('allowed_origins')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('buckets')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('adapter')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('path')->defaultNull()->end()
                            ->scalarNode('service')->defaultNull()->end()
                            ->scalarNode('key')->defaultNull()->end()
                            ->scalarNode('secret')->defaultNull()->end()
                            ->scalarNode('bucket')->defaultNull()->end()
                            ->scalarNode('region')->defaultNull()->end()
                            ->scalarNode('endpoint')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
