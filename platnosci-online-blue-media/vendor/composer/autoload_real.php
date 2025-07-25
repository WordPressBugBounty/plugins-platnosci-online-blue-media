<?php

// autoload_real.php @generated by Composer

class ComposerAutoloaderInitcb6b96e899c87ec705e2eabecbd88946
{
    private static $loader;

    public static function loadClassLoader($class)
    {
        if ('Composer\Autoload\ClassLoader' === $class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    /**
     * @return \Composer\Autoload\ClassLoader
     */
    public static function getLoader()
    {
        if (null !== self::$loader) {
            return self::$loader;
        }

        spl_autoload_register(array('ComposerAutoloaderInitcb6b96e899c87ec705e2eabecbd88946', 'loadClassLoader'), true, true);
        self::$loader = $loader = new \Composer\Autoload\ClassLoader(\dirname(__DIR__));
        spl_autoload_unregister(array('ComposerAutoloaderInitcb6b96e899c87ec705e2eabecbd88946', 'loadClassLoader'));

        require __DIR__ . '/autoload_static.php';
        call_user_func(\Composer\Autoload\ComposerStaticInitcb6b96e899c87ec705e2eabecbd88946::getInitializer($loader));

        $loader->register(true);

        return $loader;
    }
}
