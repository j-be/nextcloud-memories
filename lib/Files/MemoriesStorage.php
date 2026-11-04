<?php

declare(strict_types=1);

namespace OCA\Memories\Files;

use OC\Files\Storage\Local;
use OCA\Memories\Db\AlbumsQuery;
use OCA\Memories\Db\FsManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

final class MemoriesStorage extends Local
{
    private string $uid;

    private string $mountpoint;

    private LoggerInterface $logger;

    public function __construct(array $parameters)
    {
        $this->uid = (string) ($parameters['uid'] ?? '');
        $this->mountpoint = trim((string) ($parameters['mountpoint'] ?? '/Memories/Albums/'), " \t\n\r\0\x0B/");
        $this->logger = Server::get(LoggerInterface::class);

        if ('' === $this->uid) {
            throw new \InvalidArgumentException('No user id configured for Memories virtual storage');
        }

        $tmpBase = sys_get_temp_dir().'/memories-virtual-'.md5($this->uid.':'.$this->mountpoint);
        $parameters['datadir'] = $tmpBase;

        parent::__construct($parameters);
        $this->refreshVirtualTree();

        try {
            $this->getScanner()->scan('');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to scan virtual albums storage', [
                'app' => 'memories',
                'uid' => $this->uid,
                'mountpoint' => $this->mountpoint,
                'directory' => $this->datadir,
                'exception' => $e,
            ]);
        }
    }

    #[\Override]
    public function mkdir(string $path): bool
    {
        return false;
    }

    #[\Override]
    public function rmdir(string $path): bool
    {
        return false;
    }

    #[\Override]
    public function file_put_contents(string $path, mixed $data): false|float|int
    {
        return false;
    }

    #[\Override]
    public function unlink(string $path): bool
    {
        return false;
    }

    #[\Override]
    public function rename(string $source, string $target): bool
    {
        return false;
    }

    #[\Override]
    public function copy(string $source, string $target): bool
    {
        return false;
    }

    #[\Override]
    public function touch(string $path, ?int $mtime = null): bool
    {
        return false;
    }

    private function refreshVirtualTree(): void
    {
        $root = $this->datadir;
        if (!is_dir($root) && !@mkdir($root, 0o777, true) && !is_dir($root)) {
            $this->logger->error('Failed to create virtual albums storage directory', [
                'app' => 'memories',
                'uid' => $this->uid,
                'mountpoint' => $this->mountpoint,
                'directory' => $root,
            ]);

            return;
        }

        $this->clearDirectory($root);

        try {
            /** @var AlbumsQuery $albumsQuery */
            $albumsQuery = Server::get(AlbumsQuery::class);
            $albums = $albumsQuery->getList($this->uid);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load virtual albums', [
                'app' => 'memories',
                'uid' => $this->uid,
                'mountpoint' => $this->mountpoint,
                'exception' => $e,
            ]);

            return;
        }

        $linkedFiles = 0;
        foreach ($albums as $album) {
            $albumName = $this->normalizeVirtualName((string) ($album['name'] ?? 'album'));
            $albumDir = $root.'/'.$albumName;
            $albumBase = $albumName;
            $counter = 1;
            while (is_dir($albumDir) || file_exists($albumDir)) {
                $albumDir = $root.'/'.$albumBase.' ('.$counter++.')';
            }
            if (!@mkdir($albumDir, 0o777, true) && !is_dir($albumDir)) {
                $this->logger->error('Failed to create virtual album directory', [
                    'app' => 'memories',
                    'uid' => $this->uid,
                    'album' => $albumName,
                    'directory' => $albumDir,
                ]);

                continue;
            }

            try {
                $photos = $albumsQuery->getAlbumPhotos((int) ($album['album_id'] ?? 0), null, null);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to load photos for virtual album', [
                    'app' => 'memories',
                    'uid' => $this->uid,
                    'album' => $albumName,
                    'album_id' => $album['album_id'] ?? null,
                    'exception' => $e,
                ]);

                continue;
            }

            $seenNames = [];
            foreach ($photos as $photo) {
                $fileId = (int) ($photo['file_id'] ?? $photo['fileid'] ?? 0);
                if (0 === $fileId) {
                    continue;
                }

                try {
                    /** @var FsManager $fsManager */
                    $fsManager = Server::get(FsManager::class);
                    $file = $fsManager->getUserFileOrNull($fileId);
                    if (null === $file) {
                        $this->logger->debug('Skipping unavailable file in virtual album', [
                            'app' => 'memories',
                            'uid' => $this->uid,
                            'file_id' => $fileId,
                        ]);

                        continue;
                    }

                    $localFile = $file->getStorage()->getLocalFile($file->getInternalPath());
                    if (!\is_string($localFile) || !is_file($localFile)) {
                        $this->logger->debug('Skipping virtual album file without a local source', [
                            'app' => 'memories',
                            'uid' => $this->uid,
                            'file_id' => $fileId,
                        ]);

                        continue;
                    }

                    $realName = $this->normalizeVirtualName($file->getName());
                    $targetName = $realName;
                    $attempt = 1;
                    while (isset($seenNames[$targetName]) || is_file($albumDir.'/'.$targetName) || is_link($albumDir.'/'.$targetName)) {
                        $targetName = $this->normalizeVirtualName($file->getName()).' ('.$attempt++.')';
                    }
                    $seenNames[$targetName] = true;

                    if (!@symlink($localFile, $albumDir.'/'.$targetName)) {
                        $this->logger->warning('Failed to link file in virtual album', [
                            'app' => 'memories',
                            'uid' => $this->uid,
                            'album' => $albumName,
                            'file_id' => $fileId,
                            'target' => $targetName,
                        ]);

                        continue;
                    }

                    ++$linkedFiles;
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to add file to virtual album', [
                        'app' => 'memories',
                        'uid' => $this->uid,
                        'album' => $albumName,
                        'file_id' => $fileId,
                        'exception' => $e,
                    ]);

                    continue;
                }
            }
        }

        $this->logger->info('Refreshed virtual albums storage', [
            'app' => 'memories',
            'uid' => $this->uid,
            'mountpoint' => $this->mountpoint,
            'albums' => \count($albums),
            'linked_files' => $linkedFiles,
        ]);
    }

    private function clearDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path) && !is_link($path)) {
                $this->clearDirectory($path);
                @rmdir($path);

                continue;
            }

            @unlink($path);
        }
    }

    private function normalizeVirtualName(string $name): string
    {
        $name = preg_replace('~[\\\/]+~', '_', $name) ?? 'item';
        $name = preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?? 'item';
        $name = trim($name, " \t\n\r\0\x0B.");

        if ('' === $name || '.' === $name || '..' === $name) {
            return 'item';
        }

        return $name;
    }
}
