<?php

namespace League\FactoryMuffin;

/**
 * Class Facade.
 *
 * This is the main point of entry for using Factory Muffin.
 * This class dynamically proxies static method calls to the underline factory instance.
 *
 * @see League\FactoryMuffin\Factory
 *
 * @method static void setSaveMethod(string $method) Set the method we use when saving objects.
 * @method static void setDeleteMethod(string $method) Set the method we use when deleting objects.
 * @method static object[] seed(int $times, string $model, array $attr = array()) Returns multiple versions of an object.
 * @method static object create(string $model, array $attr = array()) Creates and saves in db an instance of the model.
 * @method static object[] saved() Return an array of saved objects.
 * @method static bool isSaved(object $object) Is the object saved?
 * @method static void deleteSaved() Call the delete method on any saved objects.
 * @method static object instance(string $model, array $attr = array()) Return an instance of the model.
 * @method static array attributesFor(object $object, array $attr = array()) Returns the mock attributes for the model.
 * @method static void define(string $model, array $definition = array()) Define a new model factory.
 * @method static string|object generateAttr(string $kind, object $object = null) Generate the attributes.
 * @method static void loadFactories(string|string[] $paths) Load the specified factories.
 *
 * @package League\FactoryMuffin
 * @author  Zizaco <zizaco@gmail.com>
 * @author  Scott Robertson <scottymeuk@gmail.com>
 * @author  Graham Campbell <graham@mineuk.com>
 * @license <https://github.com/thephpleague/factory-muffin/blob/master/LICENSE> MIT
 */
class Facade
{
    /**
     * The underline factory instance.
     *
     * @var \League\FactoryMuffin\Factory
     */
    private static $factory;

    /**
     * Get the underline factory instance.
     *
     * We'll always cache the instance and reuse it.
     *
     * @return \League\FactoryMuffin\Factory
     */
    private static function factory()
    {
        if (!self::$factory) {
            self::$factory = new Factory();
        }

        return self::$factory;
    }

    /**
     * Handle dynamic, static calls to the object.
     *
     * @codeCoverageIgnore
     *
     * @param string $method
     * @param array  $args
     *
     * @return mixed
     */
    public static function __callStatic($method, $args)
    {
        switch (count($args)) {
            case 0:
                return self::factory()->$method();
            case 1:
                return self::factory()->$method($args[0]);
            case 2:
                return self::factory()->$method($args[0], $args[1]);
            case 3:
                return self::factory()->$method($args[0], $args[1], $args[2]);
            default:
                return call_user_func_array(array(self::factory(), $method), $args);
        }
    }
}
