<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit0c75e464486d2bc95c25cb19914e5522
{
    public static $prefixLengthsPsr4 = array (
        'T' => 
        array (
            'Twilio\\' => 7,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Twilio\\' => 
        array (
            0 => __DIR__ . '/..' . '/twilio/sdk/src/Twilio',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit0c75e464486d2bc95c25cb19914e5522::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit0c75e464486d2bc95c25cb19914e5522::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit0c75e464486d2bc95c25cb19914e5522::$classMap;

        }, null, ClassLoader::class);
    }
}
