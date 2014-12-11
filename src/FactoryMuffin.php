<?php

/**
 * This file is part of Factory Muffin.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace League\FactoryMuffin;

use Closure;
use Exception;
use League\FactoryMuffin\Exceptions\DeleteFailedException;
use League\FactoryMuffin\Exceptions\DeleteMethodNotFoundException;
use League\FactoryMuffin\Exceptions\DeletingFailedException;
use League\FactoryMuffin\Exceptions\DirectoryNotFoundException;
use League\FactoryMuffin\Exceptions\ModelNotFoundException;
use League\FactoryMuffin\Exceptions\NoDefinedFactoryException;
use League\FactoryMuffin\Exceptions\SaveFailedException;
use League\FactoryMuffin\Exceptions\SaveMethodNotFoundException;
use League\FactoryMuffin\Generators\GeneratorFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * This is the factory muffin class.
 *
 * @author  Scott Robertson <scottymeuk@gmail.com>
 * @author  Graham Campbell <graham@mineuk.com>
 * @license <https://github.com/thephpleague/factory-muffin/blob/master/LICENSE> MIT
 */
class FactoryMuffin
{
    /**
     * The generator factory instance.
     *
     * @var \League\FactoryMuffin\Generators\GeneratorFactory|null
     */
    private $generatorFactory;

    /**
     * The array of model definitions.
     *
     * @var \League\FactoryMuffin\Definition[]
     */
    private $definitions = [];

    /**
     * The array of objects we have created and are pending save.
     *
     * @var array
     */
    private $pending = [];

    /**
     * The array of objects we have created and have saved.
     *
     * @var array
     */
    private $saved = [];

    /**
     * This is the method used when saving objects.
     *
     * @var string
     */
    protected $saveMethod = 'save';

    /**
     * This is the method used when deleting objects.
     *
     * @var string
     */
    protected $deleteMethod = 'delete';

    /**
     * Set the method we use when saving objects.
     *
     * @param string $method The save method name.
     *
     * @return $this
     */
    public function setSaveMethod($method)
    {
        $this->saveMethod = $method;

        return $this;
    }

    /**
     * Set the method we use when deleting objects.
     *
     * @param string $method The delete method name.
     *
     * @return $this
     */
    public function setDeleteMethod($method)
    {
        $this->deleteMethod = $method;

        return $this;
    }

    /**
     * Returns multiple versions of an object.
     *
     * These objects are generated by the create function, so are saved to the
     * database.
     *
     * @param int    $times The number of models to create.
     * @param string $model The full model name.
     * @param array  $attr  The model attributes.
     *
     * @return object[]
     */
    public function seed($times, $model, array $attr = [])
    {
        $seeds = [];
        while ($times > 0) {
            $seeds[] = $this->create($model, $attr);
            $times--;
        }

        return $seeds;
    }

    /**
     * Creates and saves in db an instance of the model.
     *
     * This object will be generated with mock attributes.
     *
     * @param string $model The full model name.
     * @param array  $attr  The model attributes.
     *
     * @return object
     */
    public function create($model, array $attr = [])
    {
        $object = $this->make($model, $attr, true);

        $this->persist($object);

        if ($this->triggerCallback($object, $model)) {
            $this->persist($object);
        }

        return $object;
    }

    /**
     * Save the object to the database.
     *
     * @param object $object The model instance.
     *
     * @throws \League\FactoryMuffin\Exceptions\SaveFailedException
     *
     * @return void
     */
    protected function persist($object)
    {
        if (!$this->save($object)) {
            if (isset($object->validationErrors) && $object->validationErrors) {
                throw new SaveFailedException(get_class($object), $object->validationErrors);
            }

            throw new SaveFailedException(get_class($object));
        }

        if (!$this->isSaved($object)) {
            Arr::move($this->pending, $this->saved, $object);
        }
    }

    /**
     * Trigger the callback if we have one.
     *
     * @param object $object The model instance.
     * @param string $model  The full model name.
     *
     * @return bool
     */
    protected function triggerCallback($object, $model)
    {
        if ($callback = $this->getDefinition($model)->getCallback()) {
            $saved = $this->isPendingOrSaved($object);

            return $callback($object, $saved) !== false;
        }

        return false;
    }

    /**
     * Make an instance of the model.
     *
     * @param string $model The full model name.
     * @param array  $attr  The model attributes.
     * @param bool   $save  Are we saving, or just creating an instance?
     *
     * @return object
     */
    protected function make($model, array $attr, $save)
    {
        $definition = $this->getDefinition($model);
        $object = $this->makeClass($definition->getClass());

        // Make the object as saved so that other generators persist correctly
        if ($save) {
            Arr::add($this->pending, $object);
        }

        // Get the group specific attribute definitions
        if ($definition->getGroup()) {
            $attr = array_merge($attr, $this->getDefinition($model)->getDefinitions());
        }

        // Generate and save each attribute for the model
        $this->generate($object, $attr);

        return $object;
    }

    /**
     * Make an instance of the class.
     *
     * @param string $class The class name.
     *
     * @throws \League\FactoryMuffin\Exceptions\ModelNotFoundException
     *
     * @return object
     */
    protected function makeClass($class)
    {
        if (!class_exists($class)) {
            throw new ModelNotFoundException($class);
        }

        return new $class();
    }

    /**
     * Set an attribute on a model instance.
     *
     * @param object $object The model instance.
     * @param string $name   The attribute name.
     * @param mixed  $value  The attribute value.
     *
     * @return void
     */
    protected function setAttribute($object, $name, $value)
    {
        $object->$name = $value;
    }

    /**
     * Save our object to the db, and keep track of it.
     *
     * @param object $object The model instance.
     *
     * @throws \League\FactoryMuffin\Exceptions\SaveMethodNotFoundException
     *
     * @return mixed
     */
    protected function save($object)
    {
        if (method_exists($object, $method = $this->saveMethod)) {
            return $object->$method();
        }

        throw new SaveMethodNotFoundException($object, $method);
    }

    /**
     * Return an array of objects to be saved.
     *
     * @return object[]
     */
    public function pending()
    {
        return $this->pending;
    }

    /**
     * Is the object going to be saved?
     *
     * @param object $object The model instance.
     *
     * @return bool
     */
    public function isPending($object)
    {
        return Arr::has($this->pending, $object);
    }

    /**
     * Return an array of saved objects.
     *
     * @return object[]
     */
    public function saved()
    {
        return $this->saved;
    }

    /**
     * Is the object saved?
     *
     * @param object $object The model instance.
     *
     * @return bool
     */
    public function isSaved($object)
    {
        return Arr::has($this->saved, $object);
    }

    /**
     * Is the object saved or will be saved?
     *
     * @param object $object The model instance.
     *
     * @return bool
     */
    public function isPendingOrSaved($object)
    {
        return ($this->isSaved($object) || $this->isPending($object));
    }

    /**
     * Call the delete method on any saved objects.
     *
     * @throws \League\FactoryMuffin\Exceptions\DeletingFailedException
     *
     * return $this
     */
    public function deleteSaved()
    {
        $exceptions = [];

        while ($object = array_pop($this->saved)) {
            try {
                if (!$this->delete($object)) {
                    throw new DeleteFailedException(get_class($object));
                }
            } catch (Exception $e) {
                $exceptions[] = $e;
            }
        }

        // If we ran into problem, throw the exception now
        if ($exceptions) {
            throw new DeletingFailedException($exceptions);
        }

        return $this;
    }

    /**
     * Delete our object from the db.
     *
     * @param object $object The model instance.
     *
     * @throws \League\FactoryMuffin\Exceptions\DeleteMethodNotFoundException
     *
     * @return mixed
     */
    protected function delete($object)
    {
        if (method_exists($object, $method = $this->deleteMethod)) {
            return $object->$method();
        }

        throw new DeleteMethodNotFoundException($object, $method);
    }

    /**
     * Return an instance of the model.
     *
     * This does not save it in the database. Use create for that.
     *
     * @param string $model The full model name.
     * @param array  $attr  The model attributes.
     *
     * @return object
     */
    public function instance($model, array $attr = [])
    {
        $object = $this->make($model, $attr, false);

        $this->triggerCallback($object, $model);

        return $object;
    }

    /**
     * Returns the mock attributes for the model.
     *
     * @param object $object The model instance.
     * @param array  $attr   The model attributes.
     *
     * @return void
     */
    protected function generate($object, array $attr = [])
    {
        $definitions = $this->getDefinition(get_class($object))->getDefinitions();
        $attributes = array_merge($definitions, $attr);

        // Generate and save each attribute
        foreach ($attributes as $key => $kind) {
            $generated = $this->getGeneratorFactory()->generate($kind, $object);
            $this->setAttribute($object, $key, $generated);
        }
    }

    /**
     * Get a model definition.
     *
     * @param string $model The full model name.
     *
     * @throws \League\FactoryMuffin\Exceptions\NoDefinedFactoryException
     *
     * @return \League\FactoryMuffin\Definition
     */
    protected function getDefinition($model)
    {
        if (isset($this->definitions[$model])) {
            return $this->definitions[$model];
        }

        throw new NoDefinedFactoryException($model);
    }

    /**
     * Define a new model factory.
     *
     * @param string        $model       The full model name.
     * @param array         $definitions The attribute definitions.
     * @param \Closure|null $callback    The closure callback.
     *
     * @return $this
     */
    public function define($model, array $definitions = [], Closure $callback = null)
    {
        $this->definitions[$model] = new Definition($model, $definitions, $callback);

        return $this;
    }

    /**
     * Load the specified factories.
     *
     * This method expects either a single path to a directory containing php
     * files, or an array of directory paths, and will include_once every file.
     * These files should contain definitions for your models.
     *
     * @param string|string[] $paths The directory path(s) to load.
     *
     * @throws \League\FactoryMuffin\Exceptions\DirectoryNotFoundException
     *
     * @return $this
     */
    public function loadFactories($paths)
    {
        foreach ((array) $paths as $path) {
            if (!is_dir($path)) {
                throw new DirectoryNotFoundException($path);
            }

            $this->loadDirectory($path);
        }

        return $this;
    }

    /**
     * Load all the files in a directory.
     *
     * @param string $path The directory path to load.
     *
     * @return void
     */
    private function loadDirectory($path)
    {
        $directory = new RecursiveDirectoryIterator($path);
        $iterator = new RecursiveIteratorIterator($directory);
        $files = new RegexIterator($iterator, '/^.+\.php$/i');

        foreach ($files as $file) {
            $this->requireFile($file->getPathName());
        }
    }

    /**
     * Require a file with this instance available as "$fm".
     *
     * @param string $file The file path to load.
     *
     * @return void
     */
    private function requireFile($file)
    {
        $fm = $this;

        require $file;
    }

    /**
     * Get the generator factory instance.
     *
     * @return \League\FactoryMuffin\Generators\GeneratorFactory
     */
    public function getGeneratorFactory()
    {
        if (!$this->generatorFactory) {
            $this->generatorFactory = new GeneratorFactory($this);
        }

        return $this->generatorFactory;
    }
}
