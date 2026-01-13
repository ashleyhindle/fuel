<?php

declare(strict_types=1);

namespace App\Agents;

use App\Agents\Drivers\AgentDriverInterface;
use App\Agents\Drivers\AmpDriver;
use App\Agents\Drivers\ClaudeDriver;
use App\Agents\Drivers\CodexDriver;
use App\Agents\Drivers\CursorDriver;
use App\Agents\Drivers\OpenCodeDriver;
use RuntimeException;

/**
 * Registry for agent driver classes.
 *
 * Manages registration and retrieval of agent drivers. Auto-registers
 * all built-in drivers on instantiation.
 */
class AgentDriverRegistry
{
    /**
     * @var array<string, AgentDriverInterface>
     */
    private array $drivers = [];

    public function __construct()
    {
        $this->registerBuiltInDrivers();
    }

    /**
     * Auto-register all 5 built-in drivers.
     */
    private function registerBuiltInDrivers(): void
    {
        $this->register(new ClaudeDriver);
        $this->register(new CursorDriver);
        $this->register(new OpenCodeDriver);
        $this->register(new AmpDriver);
        $this->register(new CodexDriver);
    }

    /**
     * Register a driver instance.
     *
     * @param  AgentDriverInterface  $driver  The driver to register
     */
    public function register(AgentDriverInterface $driver): void
    {
        $this->drivers[$driver->getName()] = $driver;
    }

    /**
     * Get a driver by its name.
     *
     * @param  string  $name  The driver name (e.g., 'claude', 'cursor-agent')
     * @return AgentDriverInterface The driver instance
     *
     * @throws RuntimeException If the driver is not registered
     */
    public function get(string $name): AgentDriverInterface
    {
        if (! isset($this->drivers[$name])) {
            throw new RuntimeException(sprintf("Unknown driver: '%s'", $name));
        }

        return $this->drivers[$name];
    }

    /**
     * Check if a driver is registered by name.
     *
     * @param  string  $name  The driver name
     * @return bool True if registered, false otherwise
     */
    public function has(string $name): bool
    {
        return isset($this->drivers[$name]);
    }

    /**
     * Get all registered drivers.
     *
     * @return array<string, AgentDriverInterface> Associative array of driver name => driver instance
     */
    public function all(): array
    {
        return $this->drivers;
    }

    /**
     * Get a driver for an agent name (e.g., 'claude-opus', 'cursor-composer').
     *
     * Attempts to match the agent name to a driver by:
     * 1. Direct match by driver name
     * 2. Matching by command binary if provided
     * 3. Pattern matching in agent name (contains 'claude', 'cursor', 'opencode', 'amp', 'codex')
     *
     * @param  string  $agentName  The agent name from config (e.g., 'claude-opus')
     * @param  string|null  $command  Optional command binary to help with matching
     * @return AgentDriverInterface The matched driver
     *
     * @throws RuntimeException If no matching driver is found
     */
    public function getForAgentName(string $agentName, ?string $command = null): AgentDriverInterface
    {
        // First try direct match by driver name
        if ($this->has($agentName)) {
            return $this->get($agentName);
        }

        // Try matching by command binary if provided
        if ($command !== null) {
            foreach ($this->drivers as $driver) {
                if ($driver->getCommand() === $command) {
                    return $driver;
                }
            }
        }

        // Try pattern matching in agent name
        $patterns = [
            'claude' => 'claude',
            'cursor' => 'cursor-agent',
            'opencode' => 'opencode',
            'amp' => 'amp',
            'codex' => 'codex',
        ];

        foreach ($patterns as $pattern => $driverName) {
            if (str_contains(strtolower($agentName), $pattern) && $this->has($driverName)) {
                return $this->get($driverName);
            }
        }

        throw new RuntimeException(sprintf("No driver found for agent name: '%s'", $agentName));
    }
}
