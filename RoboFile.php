<?php

use Sweetchuck\LintReport\Reporter\BaseReporter;
use Sweetchuck\LintReport\Reporter\CheckstyleReporter;
use League\Container\ContainerInterface;
use Robo\Collection\CollectionBuilder;
use Sweetchuck\Robo\Git\GitTaskLoader;
use Sweetchuck\Robo\Phpcs\PhpcsTaskLoader;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Webmozart\PathUtil\Path;

class RoboFile extends \Robo\Tasks
{
    use GitTaskLoader;
    use PhpcsTaskLoader;

    /**
     * @var array
     */
    protected $composerInfo = [];

    /**
     * @var array
     */
    protected $codeceptionInfo = [];

    /**
     * @var string[]
     */
    protected $codeceptionSuiteNames = [];

    /**
     * @var string
     */
    protected $packageVendor = '';

    /**
     * @var string
     */
    protected $packageName = '';

    /**
     * @var string
     */
    protected $binDir = 'vendor/bin';

    protected $gitHook = '';

    /**
     * @var string
     */
    protected $envVarNamePrefix = '';

    /**
     * Allowed values: dev, ci, prod.
     *
     * @var string
     */
    protected $environmentType = '';

    /**
     * Allowed values: local, jenkins, travis.
     *
     * @var string
     */
    protected $environmentName = '';

    /**
     * RoboFile constructor.
     */
    public function __construct()
    {
        putenv('COMPOSER_DISABLE_XDEBUG_WARN=1');
        $this
            ->initComposerInfo()
            ->initEnvVarNamePrefix();
    }

    /**
     * {@inheritdoc}
     */
    public function setContainer(ContainerInterface $container)
    {
        BaseReporter::lintReportConfigureContainer($container);

        return parent::setContainer($container);
    }

    /**
     * Git "pre-commit" hook callback.
     */
    public function githookPreCommit(): CollectionBuilder
    {
        $this->gitHook = 'pre-commit';

        return $this
            ->collectionBuilder()
            ->addTask($this->taskComposerValidate())
            ->addTask($this->getTaskPhpcsLint())
            ->addTask($this->getTaskCodeceptRunSuites());
    }

    /**
     * Run the Robo unit tests.
     */
    public function test(array $suiteNames): CollectionBuilder
    {
        $this->validateArgCodeceptionSuiteNames($suiteNames);

        return $this->getTaskCodeceptRunSuites($suiteNames);
    }

    /**
     * Run code style checkers.
     */
    public function lint(): CollectionBuilder
    {
        return $this
            ->collectionBuilder()
            ->addTask($this->taskComposerValidate())
            ->addTask($this->getTaskPhpcsLint());
    }

    protected function errorOutput(): ?OutputInterface
    {
        $output = $this->output();

        return ($output instanceof ConsoleOutputInterface) ? $output->getErrorOutput() : $output;
    }

    /**
     * @return $this
     */
    protected function initEnvVarNamePrefix()
    {
        $this->envVarNamePrefix = strtoupper(str_replace('-', '_', $this->packageName));

        return $this;
    }

    protected function getEnvVarName(string $name): string
    {
        return "{$this->envVarNamePrefix}_" . strtoupper($name);
    }

    protected function getEnvironmentType(): string
    {
        if ($this->environmentType) {
            return $this->environmentType;
        }

        return getenv($this->getEnvVarName('environment_type')) ?: 'dev';
    }

    protected function getEnvironmentName(): string
    {
        if ($this->environmentName) {
            return $this->environmentName;
        }

        return getenv($this->getEnvVarName('environment_name')) ?: 'local';
    }

    protected function getPhpExecutable(): string
    {
        return getenv($this->getEnvVarName('php_executable')) ?: PHP_BINARY;
    }

    protected function getPhpdbgExecutable(): string
    {
        return getenv($this->getEnvVarName('phpdbg_executable')) ?: Path::join(PHP_BINDIR, 'phpdbg');
    }

    /**
     * @return $this
     */
    protected function initComposerInfo()
    {
        if ($this->composerInfo || !is_readable('composer.json')) {
            return $this;
        }

        $this->composerInfo = json_decode(file_get_contents('composer.json'), true);
        list($this->packageVendor, $this->packageName) = explode('/', $this->composerInfo['name']);

        if (!empty($this->composerInfo['config']['bin-dir'])) {
            $this->binDir = $this->composerInfo['config']['bin-dir'];
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function initCodeceptionInfo()
    {
        if ($this->codeceptionInfo) {
            return $this;
        }

        if (is_readable('codeception.yml')) {
            $this->codeceptionInfo = Yaml::parse(file_get_contents('codeception.yml'));
        } else {
            $this->codeceptionInfo = [
                'paths' => [
                    'tests' => 'tests',
                    'log' => 'tests/_output',
                ],
            ];
        }

        return $this;
    }

    protected function getTaskCodeceptRunSuites(array $suiteNames = []): CollectionBuilder
    {
        if (!$suiteNames) {
            $suiteNames = ['all'];
        }

        $cb = $this->collectionBuilder();
        foreach ($suiteNames as $suiteName) {
            $cb->addTask($this->getTaskCodeceptRunSuite($suiteName));
        }

        return $cb;
    }

    protected function getTaskCodeceptRunSuite(string $suite): CollectionBuilder
    {
        $this->initCodeceptionInfo();
        $environmentType = $this->getEnvironmentType();

        $withCoverageHtml = in_array($environmentType, ['dev', 'git-hook']);
        $withCoverageXml = in_array($environmentType, ['ci']);

        $withUnitReportHtml = in_array($environmentType, ['dev', 'git-hook']);
        $withUnitReportXml = in_array($environmentType, ['ci']);

        $logDir = $this->getLogDir();

        $cmdArgs = [];
        if ($this->isPhpDbgAvailable()) {
            $cmdPattern = '%s -qrr';
            $cmdArgs[] = escapeshellcmd($this->getPhpdbgExecutable());
        } else {
            $cmdPattern = '%s';
            $cmdArgs[] = escapeshellcmd($this->getPhpExecutable());
        }

        $cmdPattern .= ' %s';
        $cmdArgs[] = escapeshellcmd("{$this->binDir}/codecept");

        $cmdPattern .= ' --ansi';
        $cmdPattern .= ' --verbose';

        $tasks = [];
        if ($withCoverageHtml) {
            $cmdPattern .= ' --coverage-html=%s';
            $cmdArgs[] = escapeshellarg("human/coverage/$suite/html");

            $tasks['prepareCoverageDir'] = $this
                ->taskFilesystemStack()
                ->mkdir("$logDir/human/coverage/$suite");
        }

        if ($withCoverageXml) {
            $cmdPattern .= ' --coverage-xml=%s';
            $cmdArgs[] = escapeshellarg("machine/coverage/$suite/coverage.xml");
        }

        if ($withCoverageHtml || $withCoverageXml) {
            $cmdPattern .= ' --coverage=%s';
            $cmdArgs[] = escapeshellarg("machine/coverage/$suite/coverage.serialized");

            $tasks['prepareCoverageDir'] = $this
                ->taskFilesystemStack()
                ->mkdir("$logDir/machine/coverage/$suite");
        }

        if ($withUnitReportHtml) {
            $cmdPattern .= ' --html=%s';
            $cmdArgs[] = escapeshellarg("human/junit/junit.$suite.html");

            $tasks['prepareJUnitDir'] = $this
                ->taskFilesystemStack()
                ->mkdir("$logDir/human/junit");
        }

        if ($withUnitReportXml) {
            $cmdPattern .= ' --xml=%s';
            $cmdArgs[] = escapeshellarg("machine/junit/junit.$suite.xml");

            $tasks['prepareJUnitDir'] = $this
                ->taskFilesystemStack()
                ->mkdir("$logDir/machine/junit");
        }

        $cmdPattern .= ' run';
        if ($suite !== 'all') {
            $cmdPattern .= ' %s';
            $cmdArgs[] = escapeshellarg($suite);
        }

        if ($environmentType === 'ci') {
            // Jenkins has to use a post-build action to mark the build "unstable".
            $cmdPattern .= ' || [[ "${?}" == "1" ]]';
        }

        $command = vsprintf($cmdPattern, $cmdArgs);

        return $this
            ->collectionBuilder()
            ->addTaskList($tasks)
            ->addCode(function () use ($command) {
                $this->output()->writeln(strtr(
                    '<question>[{name}]</question> runs <info>{command}</info>',
                    [
                        '{name}' => 'Codeception',
                        '{command}' => $command,
                    ]
                ));
                $process = new Process($command, null, null, null, null);
                $exitCode = $process->run(function ($type, $data) {
                    switch ($type) {
                        case Process::OUT:
                            $this->output()->write($data);
                            break;

                        case Process::ERR:
                            $this->errorOutput()->write($data);
                            break;
                    }
                });

                return $exitCode;
            });
    }

    /**
     * @return \Sweetchuck\Robo\Phpcs\Task\PhpcsLintFiles|\Robo\Collection\CollectionBuilder
     */
    protected function getTaskPhpcsLint()
    {
        $environmentType = $this->getEnvironmentType();
        $environmentName = $this->getEnvironmentName();

        $options = [
            'failOn' => 'warning',
            'lintReporters' => [
                'lintVerboseReporter' => null,
            ],
        ];

        if ($environmentType === 'ci') {
            $logDir = $this->getLogDir();

            if ($environmentName === 'jenkins') {
                $options['failOn'] = 'never';
            }

            $options['lintReporters']['lintCheckstyleReporter'] = (new CheckstyleReporter())
                ->setDestination("$logDir/machine/checkstyle/phpcs.psr2.xml");
        }

        if ($this->gitHook !== 'pre-commit') {
            return $this->taskPhpcsLintFiles($options);
        }

        $files = [
            'src/',
            'src-dev/Composer/',
            'tests/_support/',
            'tests/acceptance/',
            'tests/unit/',
            'RoboFile.php',
        ];

        $options['ignore'] = [
            '*.yml',
        ];

        return $this
            ->collectionBuilder()
            ->addTask($this
                ->taskGitReadStagedFiles()
                ->setCommandOnly(true)
                ->setPaths($files))
            ->addTask($this
                ->taskPhpcsLintInput($options)
                ->deferTaskConfiguration('setFiles', 'files'));
    }

    protected function isPhpExtensionAvailable(string $extension): bool
    {
        $command = sprintf('%s -m', escapeshellcmd($this->getPhpExecutable()));

        $process = new Process($command);
        $exitCode = $process->run();
        if ($exitCode !== 0) {
            throw new \RuntimeException('@todo');
        }

        return in_array($extension, explode("\n", $process->getOutput()));
    }

    protected function isPhpDbgAvailable(): bool
    {
        $command = sprintf('%s -qrr', escapeshellcmd($this->getPhpdbgExecutable()));

        return (new Process($command))->run() === 0;
    }

    protected function getLogDir(): string
    {
        $this->initCodeceptionInfo();

        return !empty($this->codeceptionInfo['paths']['log']) ?
            $this->codeceptionInfo['paths']['log']
            : 'tests/_output';
    }

    protected function getCodeceptionSuiteNames(): array
    {
        if (!$this->codeceptionSuiteNames) {
            $this->initCodeceptionInfo();

            /** @var \Symfony\Component\Finder\Finder $suiteFiles */
            $suiteFiles = Finder::create()
                ->in($this->codeceptionInfo['paths']['tests'])
                ->files()
                ->name('*.suite.yml')
                ->depth(0);

            foreach ($suiteFiles as $suiteFile) {
                $this->codeceptionSuiteNames[] = $suiteFile->getBasename('.suite.yml');
            }
        }

        return $this->codeceptionSuiteNames;
    }

    protected function validateArgCodeceptionSuiteNames(array $suiteNames): void
    {
        if (!$suiteNames) {
            return;
        }

        $invalidSuiteNames = array_diff($suiteNames, $this->getCodeceptionSuiteNames());
        if ($invalidSuiteNames) {
            throw new \InvalidArgumentException(
                'The following Codeception suite names are invalid: ' . implode(', ', $invalidSuiteNames),
                1
            );
        }
    }
}
