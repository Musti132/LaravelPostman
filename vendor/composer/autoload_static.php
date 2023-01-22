<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitd37f553c5e953810c746565db1756cef
{
    public static $prefixLengthsPsr4 = array (
        'M' => 
        array (
            'Musti\\LaravelPostman\\' => 21,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Musti\\LaravelPostman\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitd37f553c5e953810c746565db1756cef::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitd37f553c5e953810c746565db1756cef::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitd37f553c5e953810c746565db1756cef::$classMap;

        }, null, ClassLoader::class);
    }
}
