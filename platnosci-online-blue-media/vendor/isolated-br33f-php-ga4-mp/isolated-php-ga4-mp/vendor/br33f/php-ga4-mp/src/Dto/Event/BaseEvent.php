<?php

/**
 * User: Damian Zamojski (br33f)
 * Date: 22.06.2021
 * Time: 13:52
 */
namespace Isolated\Blue_Media\Isolated_Php_ga4_mp\Br33f\Ga4\MeasurementProtocol\Dto\Event;

use Isolated\Blue_Media\Isolated_Php_ga4_mp\Br33f\Ga4\MeasurementProtocol\Exception\ValidationException;
class BaseEvent extends AbstractEvent
{
    /**
     * @param string|null $name
     */
    public function setName(?string $name)
    {
        parent::setName($name);
    }
    /**
     * @return bool
     * @throws ValidationException
     */
    public function validate()
    {
        foreach ($this->getParamList() as $parameter) {
            $parameter->validate();
        }
        return \true;
    }
}
