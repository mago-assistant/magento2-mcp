<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use Magento\Framework\Cache\FrontendInterface;

final class FakeCache implements FrontendInterface
{
    /** @var array<string,string> */
    public array $store = [];
    public int $saves = 0;

    public function test($identifier)
    {
        $this->assertId($identifier);

        return isset($this->store[$identifier]);
    }

    public function load($identifier)
    {
        $this->assertId($identifier);

        return $this->store[$identifier] ?? false;
    }

    public function save($data, $identifier, array $tags = [], $lifeTime = null)
    {
        $this->assertId($identifier);
        $this->store[$identifier] = (string)$data;
        $this->saves++;

        return true;
    }

    public function remove($identifier)
    {
        $this->assertId($identifier);
        unset($this->store[$identifier]);

        return true;
    }

    public function clean($mode = \Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
    {
        $this->store = [];

        return true;
    }

    public function getBackend()
    {
        throw new \LogicException('not used');
    }

    public function getLowLevelFrontend()
    {
        throw new \LogicException('not used');
    }

    private function assertId(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new \InvalidArgumentException('Invalid cache id for Magento: ' . $identifier);
        }
    }
}
