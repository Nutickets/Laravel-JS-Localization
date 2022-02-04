<?php

namespace Mariuzzo\LaravelJsLocalization\Generators;

use InvalidArgumentException;
use Illuminate\Filesystem\Filesystem as File;
use Illuminate\Support\Str;
use JShrink\Minifier;

/**
 * The LangJsGenerator class.
 *
 * @author  Rubens Mariuzzo <rubens@mariuzzo.com>
 */
class LangJsGenerator
{
    /**
     * The file service.
     *
     * @var File
     */
    protected $file;

    /**
     * The source path of the language files.
     *
     * @var string
     */
    protected $sourcePath;

    /**
     * List of messages should be included in build.
     *
     * @var array
     */
    protected $messagesIncluded = [];

    /**
     * Name of the domain in which all string-translation should be stored under.
     * More about string-translation: https://laravel.com/docs/master/localization#retrieving-translation-strings
     *
     * @var string
     */
    protected $stringsDomain = 'strings';

    /**
     * Construct a new LangJsGenerator instance.
     *
     * @param File   $file       The file service instance.
     * @param string $sourcePath The source path of the language files.
     */
    public function __construct(File $file, $sourcePath, $messagesIncluded = [])
    {
        $this->file = $file;
        $this->sourcePath = $sourcePath;
        $this->messagesIncluded = $messagesIncluded;
    }

    /**
     * Generate a JS lang file from all language files.
     *
     * @param string $target  The target directory.
     * @param array  $options Array of options.
     *
     * @return int
     */
    public function generate($target, $options)
    {
        if ($options['source']) {
            $this->sourcePath = $options['source'];
        }

        $messages = $this->getMessages($options['no-sort']);

        $this->prepareTarget($target);

        return !empty($options['group-locales'])
            ? $this->writeGroupedMessages($target, $messages, $options)
            : $this->writeMessages($target, $messages, $options);
    }

    protected function writeGroupedMessages($target, $messages, $options)
    {
        $groups = collect($messages)->groupBy(fn ($messages, $key) => Str::before($key, '.'), true);
        foreach ($groups as $locale => $messages) {
            $fileExtension = Str::afterLast($target, ".");
            $localeTarget = Str::replace(".{$fileExtension}", "-{$locale}.{$fileExtension}", $target);

            if (! $this->writeMessages($localeTarget, $messages, $options)) {
                return false;
            }
        }

        return true;
    }

    protected function writeMessages($target, $messages, $options)
    {
        if ($options['no-lib']) {
            $template = $this->file->get(__DIR__.'/Templates/messages.js');
        } else if ($options['json']) {
            $template = $this->file->get(__DIR__.'/Templates/messages.json');
        } else if ($options['window-object']) {
            $template = $this->file->get(__DIR__.'/Templates/messages_as_window_object.js');
        } else {
            $template = $this->file->get(__DIR__.'/Templates/langjs_with_messages.js');
            $langjs = $this->file->get(__DIR__.'/../../../../lib/lang.min.js');
            $template = str_replace('\'{ langjs }\';', $langjs, $template);
        }

        $template = str_replace('\'{ messages }\'', json_encode($messages), $template);

        if ($options['compress']) {
            $template = Minifier::minify($template);
        }

        return $this->file->put($target, $template);
    }

    /**
     * Recursively sorts all messages by key.
     *
     * @param array $messages The messages to sort by key.
     */
    protected function sortMessages(&$messages)
    {
        if (is_array($messages)) {
            ksort($messages);

            foreach ($messages as $key => &$value) {
                $this->sortMessages($value);
            }
        }
    }

    /**
     * Return all language messages.
     *
     * @param bool $noSort Whether sorting of the messages should be skipped.
     * @return array
     *
     * @throws \Exception
     */
    protected function getMessages($noSort)
    {
        $messages = [];
        $path = $this->sourcePath;

        if (!$this->file->exists($path)) {
            throw new \Exception("${path} doesn't exists!");
        }

        foreach ($this->file->allFiles($path) as $file) {
            $pathName = $file->getRelativePathName();
            $extension = $this->file->extension($pathName);
            if ($extension != 'php' && $extension != 'json') {
                continue;
            }

            if ($this->isMessagesExcluded($pathName)) {
                continue;
            }

            $key = substr($pathName, 0, -4);
            $key = str_replace('\\', '.', $key);
            $key = str_replace('/', '.', $key);

            if (Str::startsWith($key, 'vendor')) {
                $key = $this->getVendorKey($key);
            }

            $fullPath = $path.DIRECTORY_SEPARATOR.$pathName;
            if ($extension == 'php') {
                $messages[$key] = include $fullPath;
            } else {
                $key = $key.$this->stringsDomain;
                $fileContent = file_get_contents($fullPath);
                $messages[$key] = json_decode($fileContent, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new InvalidArgumentException('Error while decode ' . basename($fullPath) . ': ' . json_last_error_msg());
                }
            }
        }

        if (!$noSort)
        {
            $this->sortMessages($messages);
        }

        return $messages;
    }

    /**
     * Prepare the target directory.
     *
     * @param string $target The target directory.
     */
    protected function prepareTarget($target)
    {
        $dirname = dirname($target);

        if (!$this->file->exists($dirname)) {
            $this->file->makeDirectory($dirname, 0755, true);
        }
    }

    /**
     * If messages should be excluded from build.
     *
     * @param string $filePath
     *
     * @return bool
     */
    protected function isMessagesExcluded($filePath)
    {
        if (empty($this->messagesIncluded)) {
            return false;
        }

        $filePath = str_replace(DIRECTORY_SEPARATOR, '/', $filePath);

        $localeDirSeparatorPosition = strpos($filePath, '/');
        $filePath = substr($filePath, $localeDirSeparatorPosition);
        $filePath = ltrim($filePath, '/');
        $filePath = substr($filePath, 0, -4);

        if (in_array($filePath, $this->messagesIncluded)) {
            return false;
        }

        return true;
    }

    private function getVendorKey($key)
    {
        $keyParts = explode('.', $key, 4);
        unset($keyParts[0]);

        return $keyParts[2] .'.'. $keyParts[1] . '::' . $keyParts[3];
    }
}
