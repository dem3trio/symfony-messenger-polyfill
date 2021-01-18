<?php

declare(strict_types=1);

namespace Lendable\Polyfill\Symfony\MessengerBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Bundle\FullStack;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('lendable_polyfill_messenger');

        $this->addMessengerSection($rootNode);
        return $treeBuilder;
    }

    private function addMessengerSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->fixXmlConfig('transport')
            ->fixXmlConfig('bus', 'buses')
            ->validate()
                ->ifTrue(function ($v) { return isset($v['buses']) && \count($v['buses']) > 1 && null === $v['default_bus']; })
                ->thenInvalid('You must specify the "default_bus" if you define more than one bus.')
            ->end()
            ->validate()
                ->ifTrue(static function ($v): bool { return isset($v['buses']) && null !== $v['default_bus'] && !isset($v['buses'][$v['default_bus']]); })
                ->then(static function (array $v): void { throw new InvalidConfigurationException(sprintf('The specified default bus "%s" is not configured. Available buses are "%s".', $v['default_bus'], implode('", "', array_keys($v['buses'])))); })
            ->end()
            ->children()
                ->arrayNode('routing')
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('message_class')
                    ->beforeNormalization()
                        ->always()
                        ->then(function ($config) {
                            if (!\is_array($config)) {
                                return [];
                            }
                             $newConfig = [];
                            foreach ($config as $k => $v) {
                                if (!\is_int($k)) {
                                    $newConfig[$k] = [
                                        'senders' => $v['senders'] ?? (\is_array($v) ? array_values($v) : [$v]),
                                    ];
                                } else {
                                    $newConfig[$v['message-class']]['senders'] = array_map(
                                        function ($a) {
                                            return \is_string($a) ? $a : $a['service'];
                                        },
                                        array_values($v['sender'])
                                    );
                                }
                            }
                             return $newConfig;
                        })
                    ->end()
                    ->prototype('array')
                        ->children()
                            ->arrayNode('senders')
                                ->requiresAtLeastOneElement()
                                ->prototype('scalar')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('serializer')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('default_serializer')
                            ->defaultValue('messenger.transport.native_php_serializer')
                            ->info('Service id to use as the default serializer for the transports.')
                        ->end()
                        ->arrayNode('symfony_serializer')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('format')->defaultValue('json')->info('Serialization format for the messenger.transport.symfony_serializer service (which is not the serializer used by default).')->end()
                                ->arrayNode('context')
                                    ->normalizeKeys(false)
                                    ->useAttributeAsKey('name')
                                    ->defaultValue([])
                                    ->info('Context array for the messenger.transport.symfony_serializer service (which is not the serializer used by default).')
                                    ->prototype('variable')->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('transports')
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->beforeNormalization()
                            ->ifString()
                            ->then(function (string $dsn) {
                                return ['dsn' => $dsn];
                            })
                        ->end()
                        ->fixXmlConfig('option')
                        ->children()
                            ->scalarNode('dsn')->end()
                            ->scalarNode('serializer')->defaultNull()->info('Service id of a custom serializer to use.')->end()
                            ->arrayNode('options')
                                ->normalizeKeys(false)
                                ->defaultValue([])
                                ->prototype('variable')
                                ->end()
                            ->end()
                            ->arrayNode('retry_strategy')
                                ->addDefaultsIfNotSet()
                                ->beforeNormalization()
                                    ->always(function ($v) {
                                        if (isset($v['service']) && (isset($v['max_retries']) || isset($v['delay']) || isset($v['multiplier']) || isset($v['max_delay']))) {
                                            throw new \InvalidArgumentException('The "service" cannot be used along with the other "retry_strategy" options.');
                                        }
                                         return $v;
                                    })
                                ->end()
                                ->children()
                                    ->scalarNode('service')->defaultNull()->info('Service id to override the retry strategy entirely')->end()
                                    ->integerNode('max_retries')->defaultValue(3)->min(0)->end()
                                    ->integerNode('delay')->defaultValue(1000)->min(0)->info('Time in ms to delay (or the initial value when multiplier is used)')->end()
                                    ->floatNode('multiplier')->defaultValue(2)->min(1)->info('If greater than 1, delay will grow exponentially for each retry: this delay = (delay * (multiple ^ retries))')->end()
                                    ->integerNode('max_delay')->defaultValue(0)->min(0)->info('Max time in ms that a retry should ever be delayed (0 = infinite)')->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('failure_transport')
                    ->defaultNull()
                    ->info('Transport name to send failed messages to (after all retries have failed).')
                ->end()
                ->scalarNode('default_bus')->defaultNull()->end()
                ->arrayNode('buses')
                    ->defaultValue(['messenger.bus.default' => ['default_middleware' => true, 'middleware' => []]])
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->enumNode('default_middleware')
                                ->values([true, false, 'allow_no_handlers'])
                                ->defaultTrue()
                            ->end()
                            ->arrayNode('middleware')
                                ->beforeNormalization()
                                    ->ifTrue(function ($v) { return \is_string($v) || (\is_array($v) && !\is_int(key($v))); })
                                    ->then(function ($v) { return [$v]; })
                                ->end()
                                ->defaultValue([])
                                ->arrayPrototype()
                                    ->beforeNormalization()
                                        ->always()
                                        ->then(function ($middleware): array {
                                            if (!\is_array($middleware)) {
                                                return ['id' => $middleware];
                                            }
                                            if (isset($middleware['id'])) {
                                                return $middleware;
                                            }
                                            if (1 < \count($middleware)) {
                                                throw new \InvalidArgumentException(sprintf('Invalid middleware at path "framework.messenger": a map with a single factory id as key and its arguments as value was expected, %s given.', json_encode($middleware)));
                                            }
                                             return [
                                                'id' => key($middleware),
                                                'arguments' => current($middleware),
                                            ];
                                        })
                                    ->end()
                                    ->fixXmlConfig('argument')
                                    ->children()
                                        ->scalarNode('id')->isRequired()->cannotBeEmpty()->end()
                                        ->arrayNode('arguments')
                                            ->normalizeKeys(false)
                                            ->defaultValue([])
                                            ->prototype('variable')
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

}
