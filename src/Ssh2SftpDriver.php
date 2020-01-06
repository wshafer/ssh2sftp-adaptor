<?php

namespace WShafer\Ssh2SftpAdaptor;

use League\Flysystem\Adapter\Polyfill\StreamedCopyTrait;
use League\Flysystem\Adapter\Polyfill\StreamedReadingTrait;
use League\Flysystem\Adapter\Polyfill\StreamedWritingTrait;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use Psr\Log\LoggerInterface;
use WShafer\Ssh2SftpAdaptor\Exception\AuthenticationException;
use WShafer\Ssh2SftpAdaptor\Exception\NoConnectionException;

class Ssh2SftpDriver
{
    protected string $username = 'anonymous';

    protected string $password = '';

    protected string $host = 'localhost';

    protected int $port = 22;

    protected $connection = null;

    protected ?LoggerInterface $logger = null;

    public function __construct(
        string $username,
        string $password,
        string $host,
        ?int $port = 22,
        ?LoggerInterface $logger = null
    ) {
        $this->username = $username;
        $this->password = $password;
        $this->host = $host;
        $this->port = $port;
        $this->logger = $logger;
    }

    /**
     * @param string $path
     * @param mixed $contents
     * @return false|int
     */
    public function put(string $path, $contents)
    {
        $path = $this->getPrefix() . '/' . trim($path, '/\\');

        return file_put_contents($path, $contents);
    }

    /**
     * @param $path
     * @return false|string
     */
    public function get(string $path)
    {
        $path = $this->getPrefix() . '/' . trim($path, '/\\');
        return file_get_contents($path);
    }

    public function delete(string $path)
    {
        return ssh2_sftp_unlink($this->getConnection(), $path);
    }

    public function mkdir(string $dirname, int $permissions = 0755): bool
    {
        return ssh2_sftp_mkdir(
            $this->getConnection(),
            $dirname,
            $permissions,
            true
        );
    }

    public function isDir(string $dirname): bool
    {
        $dirname = $this->getPrefix() . '/' . trim($dirname, '/\\');
        return is_dir($dirname);
    }

    public function chmod(string $path, int $permissions = 0644)
    {
        return ssh2_sftp_chmod(
            $this->getConnection(),
            $path,
            $permissions
        );
    }

    /**
     * @param string $path
     * @return array|false
     */
    public function stat(string $path)
    {
        return ssh2_sftp_stat(
            $this->getConnection(),
            $path
        );
    }

    protected function getConnection()
    {
        if ($this->connection && $this->checkConnection($this->connection)) {
            return $this->connection;
        }

        $ssh2 = ssh2_connect(
            $this->host,
            $this->port,
            [],
            [
                'debug' => [$this, 'debugCallback'],
                'disconnect' => [$this, 'disconnectCallback']
            ]
        );

        if (!$ssh2) {
            throw new NoConnectionException(
                'Unable to establish SSH connection to: '
                . $this->host . ':' . $this->port
            );
        }

        if (!ssh2_auth_password($ssh2, $this->username, $this->password)) {
            throw new AuthenticationException(
                'Invalid username or password for: '
                . $this->host . ':' . $this->port
            );
        }

        $this->connection = ssh2_sftp($ssh2);

        if (!$this->connection) {
            throw new NoConnectionException(
                'Unable to start SFTP for: '
                . $this->host . ':' . $this->port
            );
        }

        return $this->connection;
    }

    public function getPrefix(): string
    {
        $connection = $this->getConnection();
        return 'ssh2.sftp://' . intval($connection);
    }

    /**
     * @param $connection
     * @return array|false
     */
    protected function checkConnection($connection)
    {
        $root = '/';
        return ssh2_sftp_stat($connection, $root);
    }

    public function debugCallback($message, $language, $alwaysDisplay): void
    {
        $this->log(
            'debug',
            '[ssh2-debug] : ' . $message,
            [
                'language' => $language,
                'alwaysDisplay' => $alwaysDisplay
            ]
        );
    }

    public function disconnectCallback($reason, $message, $language): void
    {
        $this->log(
            'debug',
            'SSH2 communication lost.  Message: '
                . $message,
            [
                'reason' => $reason,
                'language' => $language
            ]
        );

        $this->connection = null;
    }

    protected function log($level, $message, $context = []): void
    {
        if (!$this->logger) {
            return;
        }

        $this->logger->{$level}($message, $context);
    }
}
