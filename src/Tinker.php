<?php

declare(strict_types=1);
/**
 * This file is part of friendsofhyperf/components.
 *
 * @link     https://github.com/friendsofhyperf/components
 * @document https://github.com/friendsofhyperf/components/blob/main/README.md
 * @contact  huangdijia@gmail.com
 */

namespace FriendsOfHyperf\WebTinker;

use FriendsOfHyperf\Tinker\ClassAliasAutoloader;
use FriendsOfHyperf\WebTinker\OutputModifiers\OutputModifier;
use Hyperf\Collection\Collection;
use Hyperf\Database\Model\Model;
use Psy\Configuration;
use Psy\Shell;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\BufferedOutput;

use function Hyperf\Collection\collect;
use function Hyperf\Config\config;

class Tinker
{
    protected Shell $shell;

    protected BufferedOutput $output;

    public function __construct(protected OutputModifier $outputModifier)
    {
        $this->output = new BufferedOutput();
        $this->shell = $this->createShell($this->output);
    }

    public function execute(string $phpCode): string
    {
        $phpCode = $this->removeComments($phpCode);

        $this->shell->addCode($phpCode);

        $closure = new ExecutionClosure($this->shell);

        $closure->execute();

        $output = $this->cleanOutput($this->output->fetch());

        return $this->outputModifier->modify($output);
    }

    public function removeComments(string $code): string
    {
        $tokens = collect(token_get_all("<?php\n" . $code . '?>'));

        return $tokens->reduce(function ($carry, $token) {
            if (is_string($token)) {
                return $carry . $token;
            }

            $text = $this->ignoreCommentsAndPhpTags($token);

            return $carry . $text;
        }, '');
    }

    protected function createShell(BufferedOutput $output): Shell
    {
        $config = new Configuration([
            'updateCheck' => 'never',
            'configFile' => config('web-tinker.config_file') !== null ? BASE_PATH . '/' . config('web-tinker.config_file') : null,
        ]);

        $config->setHistoryFile(defined('PHP_WINDOWS_VERSION_BUILD') ? 'null' : '/dev/null');

        $config->getPresenter()->addCasters([
            Collection::class => 'FriendsOfHyperf\Tinker\TinkerCaster::castCollection',
            Model::class => 'FriendsOfHyperf\Tinker\TinkerCaster::castModel',
            Application::class => 'FriendsOfHyperf\Tinker\TinkerCaster::castApplication',
        ]);

        $shell = new Shell($config);

        $shell->setOutput($output);

        $composerClassMap = BASE_PATH . '/vendor/composer/autoload_classmap.php';

        if (file_exists($composerClassMap)) {
            ClassAliasAutoloader::register($shell, $composerClassMap, config('tinker.alias', []), config('tinker.dont_alias', []));
        }

        return $shell;
    }

    protected function ignoreCommentsAndPhpTags(array $token)
    {
        [$id, $text] = $token;

        if ($id === T_COMMENT) {
            return '';
        }
        if ($id === T_DOC_COMMENT) {
            return '';
        }
        if ($id === T_OPEN_TAG) {
            return '';
        }
        if ($id === T_CLOSE_TAG) {
            return '';
        }

        return $text;
    }

    protected function cleanOutput(string $output): string
    {
        // Remove the first line (the command)
        $output = preg_replace('/(?s)(<aside.*?<\/aside>)|Exit:  Ctrl\+D/ms', '$2', $output);

        // Remove the last line (the return value)
        $output = preg_replace('/(?s)(<whisper.*?<\/whisper>)|INFO  Ctrl\+D\./ms', '$2', $output);

        // Remove ANSI color codes
        $output = preg_replace('/\e\[[0-9;]*m/', '', $output);

        return trim($output);
    }
}
