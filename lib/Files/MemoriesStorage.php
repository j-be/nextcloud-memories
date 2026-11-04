<?php

declare(strict_types=1);

namespace OCA\Memories\Files;

use OCA\Memories\Db\AlbumsQuery;
use OCA\Memories\Db\FsManager;
use OC\Files\Storage\Local;
use OCP\Server;

final class MemoriesStorage extends Local
{
    private string $uid;

    private string $mountpoint;

    public function __construct(array $parameters)
    {
        $this->uid = (string) ($parameters['uid'] ?? '');
        $this->mountpoint = trim((string) ($parameters['mountpoint'] ?? '/Memories/Albums/'), " \t\n\r\0\x0B/");

        if ('' === $this->uid) {
            throw new \InvalidArgumentException('No user id configured for Memories virtual storage');
        }

        $tmpBase = sys_get_temp_dir() . '/memories-virtual-' . md5($this->uid . ':' . $this->mountpoint);
        $parameters['datadir'] = $tmpBase;

        parent::__construct($parameters);
        $this->refreshVirtualTree();
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
    public function file_put_contents(string $path, mixed $data): int|float|false
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
        if (!is_dir($root) && !@mkdir($root, 0777, true) && !is_dir($root)) {
            return;
        }

        $this->clearDirectory($root);

        try {
            /** @var AlbumsQuery $albumsQuery */
            $albumsQuery = Server::get(AlbumsQuery::class);
            $albums = $albumsQuery->getList($this->uid);
        } catch (\Throwable) {
            return;
        }

        foreach ($albums as $album) {
            $albumName = $this->normalizeVirtualName((string) ($album['name'] ?? 'album'));
            $albumDir = $root . '/' . $albumName;
            $albumBase = $albumName;
            $counter = 1;
            while (is_dir($albumDir) || file_exists($albumDir)) {
                $albumDir = $root . '/' . $albumBase . ' (' . $counter++ . ')';
            }
            @mkdir($albumDir, 0777, true);

            try {
                $photos = $albumsQuery->getAlbumPhotos((int) ($album['album_id'] ?? 0), null, null);
            } catch (\Throwable) {
                $photos = [];
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
                        continue;
                    }

                    $localFile = $file->getStorage()->getLocalFile($file->getInternalPath());
                    if (!is_string($localFile) || !is_file($localFile)) {
                        continue;
                    }

                    $realName = $this->normalizeVirtualName($file->getName());
                    $targetName = $realName;
                    $attempt = 1;
                    while (isset($seenNames[$targetName]) || is_file($albumDir . '/' . $targetName) || is_link($albumDir . '/' . $targetName)) {
                        $targetName = $this->normalizeVirtualName($file->getName()) . ' (' . $attempt++ . ')';
                    }
                    $seenNames[$targetName] = true;

                    @symlink($localFile, $albumDir . '/' . $targetName);
                } catch (\Throwable) {
                    continue;
                }
            }
        }
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

            $path = $dir . '/' . $item;
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
        $name = preg_replace('/[\\\/]+/', '_', $name) ?? 'item';
        $name = preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?? 'item';
        $name = trim($name, " \t\n\r\0\x0B.");

        if ('' === $name || '.' === $name || '..' === $name) {
            return 'item';
        }

        return $name;
    }
}
