<?php

namespace App\Plugins;

/**
 * Registry of native PluginHandler instances, keyed by PluginHandler::key().
 *
 * Registered in PluginServiceProvider and resolved by the plugins index,
 * the shared instance Livewire views, and the generic webhook controller.
 */
class PluginRegistry
{
    /**
     * @var array<string, PluginHandler>
     */
    private array $handlers = [];

    public function register(PluginHandler $handler): void
    {
        $this->handlers[$handler->key()] = $handler;
    }

    public function get(string $key): ?PluginHandler
    {
        return $this->handlers[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->handlers[$key]);
    }

    /**
     * @return array<string, PluginHandler>
     */
    public function all(): array
    {
        return $this->handlers;
    }
}
