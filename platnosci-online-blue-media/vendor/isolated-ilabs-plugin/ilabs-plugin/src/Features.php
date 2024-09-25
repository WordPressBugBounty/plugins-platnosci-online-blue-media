<?php

namespace Isolated\BlueMedia\Ilabs\Ilabs_Plugin;

class Features
{
    /**
     * @var Features_Config_Interface | null
     */
    private ?Features_Config_Interface $config;
    /**
     * @param Features_Config_Interface|null $config
     */
    public function __construct(?Features_Config_Interface $config)
    {
        $this->config = $config;
    }
    public function is_active(string $feature_id) : bool
    {
        if ($this->config !== null) {
            return isset($this->config->get_config()[$feature_id]) && $this->config->get_config()[$feature_id] === 1;
        }
        return \false;
    }
}
