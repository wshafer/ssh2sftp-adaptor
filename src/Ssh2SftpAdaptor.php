<?php

namespace WShafer\Ssh2SftpAdaptor;

use League\Flysystem\Adapter\Polyfill\StreamedCopyTrait;
use League\Flysystem\Adapter\Polyfill\StreamedReadingTrait;
use League\Flysystem\Adapter\Polyfill\StreamedWritingTrait;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use Psr\Log\LoggerInterface;
use WShafer\Ssh2SftpAdaptor\Exception\DestinationDirDoesNotExistException;
use WShafer\Ssh2SftpAdaptor\Exception\InvalidConfigException;

class Ssh2SftpAdaptor implements AdapterInterface
{
    use StreamedReadingTrait;
    use StreamedCopyTrait;
    use StreamedWritingTrait;

    protected array $permissionMap = [
        'file' => [
            'public' => 0644,
            'private' => 0600,
        ],
        'dir' => [
            'public' => 0755,
            'private' => 0700,
        ],
    ];


    protected string $root = '';

    protected bool $createDirIfMissing = true;

    protected ?LoggerInterface $logger = null;

    protected ?Ssh2SftpDriver $driver = null;

    public function __construct(
        array $config,
        ?LoggerInterface $logger = null,
        ?Ssh2SftpDriver $driver = null
    ) {
        $this->setConfig($config);
        $this->logger = $logger;

        if (!$driver) {
            $driver = $this->setDriver($config);
        }

        $this->driver = $driver;
    }

    public function setDriver(array $config): Ssh2SftpDriver
    {
        if (empty($config['username'])) {
            throw new InvalidConfigException(
                "Missing sftp username in config"
            );
        }

        if (empty($config['password'])) {
            throw new InvalidConfigException(
                "Missing sftp password in config"
            );
        }

        if (empty($config['host'])) {
            throw new InvalidConfigException(
                "Missing sftp host in config"
            );
        }

        $port = 22;

        if (!empty($config['port'])) {
            $port = $config['port'];
        }

        return new Ssh2SftpDriver(
            $config['username'],
            $config['password'],
            $config['host'],
            $port
        );
    }

    public function setConfig(array $config): void
    {
        if (empty($config['root'])) {
            return;
        }

        if (!empty($config['skipDirCreation'])) {
            $this->createDirIfMissing = false;
        }

        $this->root = $config['root'];
    }

    /**
     * @param string $path
     * @param mixed $contents
     * @param Config $config
     * @return array|false
     */
    public function write($path, $contents, Config $config)
    {
        $this->ensureFoldersExist($path);

        $location = $this->getRealPath($path);

        if (($size = $this->driver->put($location, $contents)  === false)) {
            return false;
        }

        $type = 'file';
        $result = compact('contents', 'type', 'size', 'path');

        if ($visibility = $config->get('visibility')) {
            $result['visibility'] = $visibility;
            $this->setVisibility($path, $visibility);
        }

        return $result;
    }

    public function setVisibility($path, $visibility): bool
    {
        $location = $this->getRealPath($path);

        $stats = $this->getMetadata($path);

        if (!$stats) {
            return false;
        }

        $type = $stats['type'];

        if ($type != 'dir' && $type != 'file') {
            return false;
        }

        return $this->driver->chmod($location, $this->permissionMap[$type][$visibility]);
    }

    public function ensureFoldersExist($path): bool
    {
        $dirname = dirname($this->getRealPath($path));

        $success = $this->driver->isDir($dirname);

        if ($success) {
            return true;
        }

        if ($this->createDirIfMissing) {
            return $this->driver->mkdir($dirname, $this->permissionMap['dir']['public']);
        }

        throw new DestinationDirDoesNotExistException(
            "Folder " . $dirname . ' does not exist'
        );
    }

    public function update($path, $contents, Config $config)
    {
        $stats = $this->getMetadata($path);

        if (!$stats) {
            return false;
        }

        if(!$this->write($path, $contents, $config)) {
            return false;
        }

        return $stats;
    }

    public function read($path)
    {
        return $this->driver->get($this->getRealPath($path));
    }

    public function delete($path)
    {
        return $this->driver->delete($this->getRealPath($path));
    }

    public function getVisibility($path)
    {
        // TODO: Implement getVisibility() method.
    }

    public function getMimetype($path)
    {
        // TODO: Implement getMimetype() method.
    }

    public function rename($path, $newpath)
    {
        // TODO: Implement rename() method.
    }

    public function listContents($directory = '', $recursive = false)
    {
        // TODO: Implement listContents() method.
    }

    public function createDir($dirname, Config $config)
    {
        $dirname = dirname($this->getRealPath($dirname));

        $visibility = $config->get('visibility');

        $this->driver->mkdir($dirname, $visibility);

    }

    public function has($path)
    {
        // TODO: Implement has() method.
    }

    public function deleteDir($dirname)
    {
        // TODO: Implement deleteDir() method.
    }

    public function getSize($path)
    {
        // TODO: Implement getSize() method.
    }

    public function getTimestamp($path)
    {
        // TODO: Implement getTimestamp() method.
    }

    public function getMetadata($path)
    {
        $stats = $this->driver->stat($this->getRealPath($path));

        if (empty($stats)) {
            return false;
        }

        $normalized = [
            'type' => $this->getFileType($stats['mode']),
            'path' => $this->getRealPath($path),
        ];

        $normalized['timestamp'] = $stats['mtime'];

        if ($normalized['type'] === 'file') {
            $normalized['size'] = $stats['size'];
        }

        return $normalized;
    }

    protected function getFileType($mode): ?string
    {
        switch ($mode) {
            case 0100000:
                return 'file';
            case 0040000:
                return 'dir';
            default:
                return null;
        }
    }

    protected function getRealPath($path): string
    {
        $root = trim($this->root, '/\\');
        $return = '';

        if (!empty($root)) {
            $return .= '/'.$root;
        }

        $return .= '/' . ltrim($path, '/\\');

        return $return;
    }

    protected function log($level, $message, $context = []): void
    {
        if (!$this->logger) {
            return;
        }

        $this->logger->{$level}($message, $context);
    }
}
