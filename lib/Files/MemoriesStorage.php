<?php

declare(strict_types=1);

namespace OCA\Memories\Files;

use Icewind\Streams\IteratorDirectory;
use OC\Files\Storage\Common;
use OCA\Memories\Db\AlbumsQuery;
use OCA\Memories\Db\FsManager;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
use OCP\Server;
use Psr\Log\LoggerInterface;

final class MemoriesStorage extends Common
{
    private string $uid;

    private string $mountpoint;

    private LoggerInterface $logger;

    /** @var array<string, int> */
    private array $fileIdsByPath = [];

    /** @var array<string, array<string, true>> */
    private array $directories = ['' => []];

    /** @var array<string, int> */
    private array $albumIdsByPath = [];

    /** @var array<string, array{count: int, update_id: int, mtime: int}> */
    private array $albumVersions = [];

    /** @var array<string, true> */
    private array $loadedAlbums = [];

    private bool $albumsLoaded = false;

    public function __construct(array $parameters)
    {
        parent::__construct($parameters);

        $this->uid = (string) ($parameters['uid'] ?? '');
        $this->mountpoint = trim((string) ($parameters['mountpoint'] ?? '/Memories/Albums/'), " \t\n\r\0\x0B/");
        $this->logger = Server::get(LoggerInterface::class);

        if ('' === $this->uid) {
            throw new \InvalidArgumentException('No user id configured for Memories virtual storage');
        }

        try {
            $this->getScanner()->scan('');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to scan virtual albums storage root', [
                'app' => 'memories',
                'uid' => $this->uid,
                'mountpoint' => $this->mountpoint,
                'exception' => $e,
            ]);
        }
    }

    #[\Override]
    public function getId(): string
    {
        return 'memories::'.$this->uid.':'.md5($this->mountpoint);
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
    public function opendir(string $path)
    {
        $path = $this->normalizePath($path);
        if (null === $path || !$this->loadDirectory($path)) {
            return false;
        }

        /** @psalm-suppress UndefinedClass Nextcloud provides Icewind Streams through its 3rdparty autoloader. */
        return IteratorDirectory::wrap(array_keys($this->directories[$path]));
    }

    #[\Override]
    public function stat(string $path): array|false
    {
        $path = $this->normalizePath($path);
        if (null === $path) {
            return false;
        }

        $file = $this->getVirtualFile($path);
        if (null !== $file) {
            $stat = $file->getStorage()->stat($file->getInternalPath());
            if (false !== $stat) {
                return $stat;
            }

            return [
                'size' => $file->getSize(),
                'mtime' => $file->getMTime(),
                'mode' => 0o100000 | 0o444,
            ];
        }

        if (!isset($this->directories[$path])) {
            return false;
        }

        $mtime = $this->directoryMtime($path);

        return [
            'size' => 0,
            'mtime' => $mtime,
            'mode' => 0o040000 | 0o555,
        ];
    }

    #[\Override]
    public function filetype(string $path): false|string
    {
        $path = $this->normalizePath($path);
        if (null === $path) {
            return false;
        }

        if (!$this->loadDirectory('')) {
            return false;
        }
        if (isset($this->directories[$path])) {
            return 'dir';
        }

        $parent = \dirname($path);
        $parent = '.' === $parent ? '' : $parent;
        if ('' === $parent) {
            return false;
        }

        return $this->loadDirectory($parent) && isset($this->fileIdsByPath[$path]) ? 'file' : false;
    }

    #[\Override]
    public function file_exists(string $path): bool
    {
        return false !== $this->filetype($path);
    }

    #[\Override]
    public function fopen(string $path, string $mode)
    {
        if (!\in_array($mode, ['r', 'rb'], true)) {
            return false;
        }

        $file = $this->getVirtualFile($path);

        return $file?->fopen($mode) ?? false;
    }

    #[\Override]
    public function isReadable(string $path): bool
    {
        $path = $this->normalizePath($path);
        if (null === $path) {
            return false;
        }

        $file = $this->getVirtualFile($path);
        if (isset($this->directories[$path])) {
            return true;
        }

        return $file?->isReadable() ?? false;
    }

    #[\Override]
    public function isUpdatable(string $path): bool
    {
        return false;
    }

    #[\Override]
    public function isCreatable(string $path): bool
    {
        return false;
    }

    #[\Override]
    public function isDeletable(string $path): bool
    {
        return false;
    }

    #[\Override]
    public function isSharable(string $path): bool
    {
        return $this->isReadable($path);
    }

    #[\Override]
    public function getPermissions(string $path): int
    {
        $path = $this->normalizePath($path);
        if (null === $path) {
            return 0;
        }

        $file = $this->getVirtualFile($path);
        if (null !== $file) {
            return $file->getPermissions() & (Constants::PERMISSION_READ | Constants::PERMISSION_SHARE);
        }

        return isset($this->directories[$path])
            ? Constants::PERMISSION_READ | Constants::PERMISSION_SHARE
            : 0;
    }

    #[\Override]
    public function filemtime(string $path): false|int
    {
        $path = $this->normalizePath($path);
        if (null === $path) {
            return false;
        }

        $file = $this->getVirtualFile($path);
        if (null !== $file) {
            return $file->getMTime();
        }

        return isset($this->directories[$path]) ? $this->directoryMtime($path) : false;
    }

    #[\Override]
    public function getMimeType(string $path): false|string
    {
        $path = $this->normalizePath($path);
        if (null === $path) {
            return false;
        }

        $file = $this->getVirtualFile($path);
        if (isset($this->directories[$path])) {
            return 'httpd/unix-directory';
        }

        return $file?->getMimeType() ?? false;
    }

    #[\Override]
    public function getETag(string $path): false|string
    {
        $path = $this->normalizePath($path);
        if (null === $path) {
            return false;
        }

        $file = $this->getVirtualFile($path);
        if (null !== $file) {
            return $file->getETag();
        }

        if (!isset($this->directories[$path])) {
            return false;
        }

        if ('' === $path) {
            $entries = [];
            foreach ($this->albumIdsByPath as $albumPath => $albumId) {
                $entries[$albumPath] = [$albumId, $this->albumVersions[$albumPath]];
            }

            return hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR));
        }

        return hash('sha256', json_encode([
            $this->albumIdsByPath[$path] ?? null,
            $this->albumVersions[$path] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    #[\Override]
    public function hash(string $type, string $path, bool $raw = false): false|string
    {
        $file = $this->getVirtualFile($path);

        return $file?->hash($type, $raw) ?? false;
    }

    #[\Override]
    public function getMetaData(string $path): ?array
    {
        $path = $this->normalizePath($path);
        if (null === $path) {
            return null;
        }

        $file = $this->getVirtualFile($path);
        if (null !== $file) {
            return $this->fileMetadata($path, $file);
        }

        if (!isset($this->directories[$path])) {
            return null;
        }

        return [
            'mimetype' => 'httpd/unix-directory',
            'mtime' => $this->directoryMtime($path),
            'size' => -1,
            'etag' => $this->getETag($path),
            'storage_mtime' => $this->directoryMtime($path),
            'permissions' => $this->getPermissions($path),
            'name' => '' === $path ? '' : basename($path),
        ];
    }

    #[\Override]
    public function getDirectoryContent(string $directory): \Traversable
    {
        $directory = $this->normalizePath($directory);
        if (null === $directory || !$this->loadDirectory($directory)) {
            return;
        }

        foreach (array_keys($this->directories[$directory]) as $name) {
            $path = '' === $directory ? $name : $directory.'/'.$name;
            $fileId = $this->fileIdsByPath[$path] ?? null;
            $file = null === $fileId ? null : $this->getVirtualFileById($fileId);
            $metadata = null !== $file
                ? $this->fileMetadata($path, $file)
                : $this->getMetaData($path);
            if (null !== $metadata) {
                yield $metadata;
            }
        }
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

    #[\Override]
    public function copyFromStorage(IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath, bool $preserveMtime = false): bool
    {
        return false;
    }

    #[\Override]
    public function moveFromStorage(IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath): bool
    {
        return false;
    }

    private function getVirtualFile(string $path): ?File
    {
        $path = $this->normalizePath($path);
        if (null === $path) {
            return null;
        }

        $parent = \dirname($path);
        $parent = '.' === $parent ? '' : $parent;
        if (!$this->loadDirectory($parent)) {
            return null;
        }

        $fileId = $this->fileIdsByPath[$path] ?? null;

        return null === $fileId ? null : $this->getVirtualFileById($fileId);
    }

    private function getVirtualFileById(int $fileId): ?File
    {
        /** @var FsManager $fsManager */
        $fsManager = Server::get(FsManager::class);

        return $fsManager->getUserFileOrNull($fileId);
    }

    private function normalizePath(string $path): ?string
    {
        $path = trim($path, '/');
        if ('' === $path) {
            return '';
        }

        foreach (explode('/', $path) as $part) {
            if ('' === $part || '.' === $part || '..' === $part) {
                return null;
            }
        }

        return $path;
    }

    private function directoryMtime(string $path): int
    {
        if ('' === $path) {
            $mtime = 0;
            foreach ($this->albumVersions as $version) {
                $mtime = max($mtime, $version['mtime']);
            }

            return $mtime;
        }

        return $this->albumVersions[$path]['mtime'] ?? 0;
    }

    private function loadDirectory(string $path): bool
    {
        $this->loadAlbums();
        if (!isset($this->directories[$path])) {
            return false;
        }

        if ('' !== $path && isset($this->albumIdsByPath[$path]) && !isset($this->loadedAlbums[$path])) {
            return $this->loadAlbum($path);
        }

        return true;
    }

    private function loadAlbums(): void
    {
        if ($this->albumsLoaded) {
            return;
        }

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

        foreach ($albums as $album) {
            $albumBase = $this->normalizeVirtualName((string) ($album['name'] ?? 'album'));
            $albumName = $this->uniqueName($albumBase, $this->directories['']);
            $albumPath = $albumName;
            $this->directories[''][$albumName] = true;
            $this->directories[$albumPath] = [];
            $this->albumIdsByPath[$albumPath] = (int) ($album['album_id'] ?? 0);
            $this->albumVersions[$albumPath] = [
                'count' => (int) ($album['count'] ?? 0),
                'update_id' => (int) ($album['update_id'] ?? 0),
                'mtime' => (int) ($album['created'] ?? 0),
            ];
        }

        $this->albumsLoaded = true;
        $this->logger->info('Refreshed virtual albums storage', [
            'app' => 'memories',
            'uid' => $this->uid,
            'mountpoint' => $this->mountpoint,
            'albums' => \count($albums),
        ]);
    }

    private function loadAlbum(string $albumPath): bool
    {
        $albumId = $this->albumIdsByPath[$albumPath] ?? null;
        if (null === $albumId) {
            return false;
        }

        try {
            /** @var AlbumsQuery $albumsQuery */
            $albumsQuery = Server::get(AlbumsQuery::class);
            $photos = $albumsQuery->getAlbumPhotos($albumId, null, null);

            /** @var FsManager $fsManager */
            $fsManager = Server::get(FsManager::class);

            foreach ($photos as $photo) {
                $fileId = (int) ($photo['file_id'] ?? $photo['fileid'] ?? 0);
                if (0 === $fileId) {
                    continue;
                }

                try {
                    $file = $fsManager->getUserFileOrNull($fileId);
                    if (null === $file) {
                        continue;
                    }

                    $fileName = $this->uniqueName(
                        $this->normalizeVirtualName($file->getName()),
                        $this->directories[$albumPath],
                    );
                    $virtualPath = $albumPath.'/'.$fileName;
                    $this->directories[$albumPath][$fileName] = true;
                    $this->fileIdsByPath[$virtualPath] = $fileId;
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to add file to virtual album', [
                        'app' => 'memories',
                        'uid' => $this->uid,
                        'album' => $albumPath,
                        'file_id' => $fileId,
                        'exception' => $e,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load photos for virtual album', [
                'app' => 'memories',
                'uid' => $this->uid,
                'album' => $albumPath,
                'album_id' => $albumId,
                'exception' => $e,
            ]);

            return false;
        }

        $this->loadedAlbums[$albumPath] = true;

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function fileMetadata(string $path, File $file): array
    {
        $mtime = $file->getMTime();

        return [
            'mimetype' => $file->getMimeType(),
            'mtime' => $mtime,
            'size' => $file->getSize(),
            'etag' => $file->getETag(),
            'storage_mtime' => $mtime,
            'permissions' => $file->getPermissions() & (Constants::PERMISSION_READ | Constants::PERMISSION_SHARE),
            'name' => basename($path),
        ];
    }

    /**
     * @param array<string, true> $existingNames
     */
    private function uniqueName(string $name, array $existingNames): string
    {
        $candidate = $name;
        $counter = 1;
        while (isset($existingNames[$candidate])) {
            $candidate = $name.' ('.$counter++.')';
        }

        return $candidate;
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
