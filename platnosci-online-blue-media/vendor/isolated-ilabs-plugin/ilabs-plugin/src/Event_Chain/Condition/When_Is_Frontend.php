<?php

namespace Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Event_Chain\Condition;

use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Event_Chain\Abstracts\Abstract_Condition;
use Isolated\BlueMedia\Ilabs\Ilabs_Plugin\Event_Chain\Interfaces\Condition_Interface;
class When_Is_Frontend extends Abstract_Condition implements Condition_Interface
{
    public function assert() : bool
    {
        return !is_admin();
    }
}