<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Files\MemoriesMountProvider;
use OCA\Memories\Files\MemoriesStorage;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Tests\TestCase;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorageFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * @internal
 *
 * @coversNothing
 */
final class VirtualAlbumsConfigTest extends TestCase
{
    public function testVirtualAlbumSettingsHaveSafeDefaults(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(static fn (string $key, mixed $default = null) => $default);
        $config->method('getSystemValueBool')->willReturnCallback(static fn (string $key, bool $default = false) => $default);
        $config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = '') => $default);

        $systemConfig = new SystemConfig(
            $config,
            $this->createMock(IUserConfig::class),
            $this->createMock(IRequest::class),
            $this->createMock(IAppManager::class),
            $this->createMock(IAppConfig::class),
            $this->createMock(IUserSession::class),
            $this->createMock(\OCP\Encryption\IManager::class),
        );

        self::assertFalse($systemConfig->virtualAlbumsEnabled());
        self::assertSame('/Memories/Albums/', $systemConfig->getVirtualAlbumsMountpoint());
    }

    public function testVirtualAlbumSettingsReadConfiguredValues(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(
            static fn (string $key, mixed $default = null) => match ($key) {
                'memories.virtual_albums.enabled' => true,
                'memories.virtual_albums.mountpoint' => '/Shared/Albums/',
                default => $default,
            },
        );

        $systemConfig = new SystemConfig(
            $config,
            $this->createMock(IUserConfig::class),
            $this->createMock(IRequest::class),
            $this->createMock(IAppManager::class),
            $this->createMock(IAppConfig::class),
            $this->createMock(IUserSession::class),
            $this->createMock(\OCP\Encryption\IManager::class),
        );

        self::assertTrue($systemConfig->virtualAlbumsEnabled());
        self::assertSame('/Shared/Albums/', $systemConfig->getVirtualAlbumsMountpoint());
    }

    public function testMountProviderCreatesUserScopedAlbumsMount(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $systemConfig = $this->createMock(SystemConfig::class);
        $systemConfig->method('virtualAlbumsEnabled')->willReturn(true);
        $systemConfig->method('getVirtualAlbumsMountpoint')->willReturn('/Memories/Albums/');

        $folder = $this->createMock(Folder::class);
        $folder->method('get')->willThrowException(new NotFoundException('missing'));

        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->expects(self::once())
            ->method('getUserFolder')
            ->with('alice')
            ->willReturn($folder)
        ;

        $provider = new MemoriesMountProvider($systemConfig, $rootFolder, $this->createMock(LoggerInterface::class));
        $mounts = $provider->getMountsForUser($user, $this->createMock(IStorageFactory::class));

        self::assertCount(1, $mounts);
        self::assertSame('/alice/files/Memories/Albums/', $mounts[0]->getMountPoint());
    }

    public function testMountProviderIgnoresItsOwnExistingMountDuringCollisionCheck(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $systemConfig = $this->createMock(SystemConfig::class);
        $systemConfig->method('virtualAlbumsEnabled')->willReturn(true);
        $systemConfig->method('getVirtualAlbumsMountpoint')->willReturn('/Memories/Albums/');

        $mountPoint = $this->createMock(IMountPoint::class);
        $mountPoint->method('getMountProvider')->willReturn(MemoriesMountProvider::class);
        $node = $this->createMock(Node::class);
        $node->method('getMountPoint')->willReturn($mountPoint);

        $folder = $this->createMock(Folder::class);
        $folder->method('get')->with('Memories/Albums')->willReturn($node);

        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->with('alice')->willReturn($folder);

        $provider = new MemoriesMountProvider($systemConfig, $rootFolder, $this->createMock(LoggerInterface::class));
        $mounts = $provider->getMountsForUser($user, $this->createMock(IStorageFactory::class));

        self::assertCount(1, $mounts);
        self::assertSame('/alice/files/Memories/Albums/', $mounts[0]->getMountPoint());
    }

    public function testVirtualStorageListsDirectoriesAndRejectsTraversal(): void
    {
        $storage = (new \ReflectionClass(MemoriesStorage::class))->newInstanceWithoutConstructor();
        $files = new \ReflectionProperty(MemoriesStorage::class, 'fileIdsByPath');
        $files->setValue($storage, ['Holiday/photo.jpg' => 42]);
        $directories = new \ReflectionProperty(MemoriesStorage::class, 'directories');
        $directories->setValue($storage, ['' => ['Holiday' => true], 'Holiday' => ['photo.jpg' => true]]);
        $albumIds = new \ReflectionProperty(MemoriesStorage::class, 'albumIdsByPath');
        $albumIds->setValue($storage, ['Holiday' => 1]);
        $albumVersions = new \ReflectionProperty(MemoriesStorage::class, 'albumVersions');
        $albumVersions->setValue($storage, ['Holiday' => ['count' => 1, 'update_id' => 1, 'mtime' => 123]]);
        $loadedAlbums = new \ReflectionProperty(MemoriesStorage::class, 'loadedAlbums');
        $loadedAlbums->setValue($storage, ['Holiday' => true]);
        $albumsLoaded = new \ReflectionProperty(MemoriesStorage::class, 'albumsLoaded');
        $albumsLoaded->setValue($storage, true);

        $rootHandle = $storage->opendir('');
        $albumHandle = $storage->opendir('Holiday');

        self::assertSame('Holiday', readdir($rootHandle));
        self::assertSame('photo.jpg', readdir($albumHandle));
        closedir($rootHandle);
        closedir($albumHandle);
        self::assertSame('dir', $storage->filetype('Holiday'));
        self::assertSame('file', $storage->filetype('Holiday/photo.jpg'));
        self::assertFalse($storage->isUpdatable('Holiday/photo.jpg'));
        self::assertFalse($storage->isDeletable('Holiday/photo.jpg'));
        self::assertFalse($storage->file_exists('../photo.jpg'));
        self::assertFalse($storage->opendir('../'));
    }
}
