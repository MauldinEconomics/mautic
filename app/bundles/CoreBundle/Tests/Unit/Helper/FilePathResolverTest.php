<?php

/*
 * @copyright   2015 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CoreBundle\Tests\Unit\Helper;

use Mautic\CoreBundle\Exception\FilePathException;
use Mautic\CoreBundle\Helper\FilePathResolver;
use Mautic\CoreBundle\Helper\InputHelper;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FilePathResolverTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var MockObject|Filesystem
     */
    private $filesystemMock;

    /**
     * @var MockObject|UploadedFile
     */
    private $fileMock;

    /**
     * @var InputHelper
     */
    private $inputHelper;

    /**
     * @var FilePathResolver
     */
    private $filePathResolver;

    protected function setUp()
    {
        $this->filesystemMock   = $this->createMock(Filesystem::class);
        $this->fileMock         = $this->createMock(UploadedFile::class);
        $this->inputHelper      = new InputHelper();
        $this->filePathResolver = new FilePathResolver($this->filesystemMock, $this->inputHelper);
    }

    /**
     * @testdox Get correct name if few previous names are taken
     *
     * @covers \Mautic\CoreBundle\Helper\FilePathResolver::getUniqueFileName
     */
    public function testGetUniqueName()
    {
        $uploadDir     = 'my/upload/dir';
        $extension     = 'jpg';
        $dirtyFileName = 'fileName_x./-u'.$extension;

        $this->filesystemMock->expects($this->at(0))
            ->method('exists')
            ->with('my/upload/dir/filename_x.jpg')
            ->willReturn(true);

        $this->filesystemMock->expects($this->at(1))
            ->method('exists')
            ->with('my/upload/dir/filename_x-1.jpg')
            ->willReturn(true);

        $this->filesystemMock->expects($this->at(2))
            ->method('exists')
            ->with('my/upload/dir/filename_x-2.jpg')
            ->willReturn(false);

        $this->fileMock->expects($this->once())
            ->method('getClientOriginalName')
            ->with()
            ->willReturn($dirtyFileName);

        $this->fileMock->expects($this->once())
            ->method('getClientOriginalExtension')
            ->with()
            ->willReturn($extension);

        $name = $this->filePathResolver->getUniqueFileName($uploadDir, $this->fileMock);

        $this->assertSame('filename_x-2.jpg', $name);
    }

    /**
     * @testdox Throws an Exception if name cannot be generated
     *
     * @covers \Mautic\CoreBundle\Helper\FilePathResolver::getUniqueFileName
     */
    public function testCouldNotGetUniqueName()
    {
        $uploadDir     = 'my/upload/dir';
        $extension     = 'jpg';
        $dirtyFileName = 'fileName_x./-u'.$extension;

        $this->filesystemMock->expects($this->exactly(1000))
            ->method('exists')
            ->willReturn(true);

        $this->fileMock->expects($this->once())
            ->method('getClientOriginalName')
            ->with()
            ->willReturn($dirtyFileName);

        $this->fileMock->expects($this->once())
            ->method('getClientOriginalExtension')
            ->with()
            ->willReturn($extension);

        $this->expectException(FilePathException::class);
        $this->expectExceptionMessage('Could not generate path');

        $this->filePathResolver->getUniqueFileName($uploadDir, $this->fileMock);
    }

    /**
     * @testdox No action is taken when directory already exists
     *
     * @covers \Mautic\CoreBundle\Helper\FilePathResolver::createDirectory
     */
    public function testNoActionIfDirectoryExists()
    {
        $directory = 'my/directory';

        $this->filesystemMock->expects($this->once())
            ->method('exists')
            ->with($directory)
            ->willReturn(true);

        $this->filePathResolver->createDirectory($directory);
    }

    /**
     * @testdox Create new directory
     *
     * @covers \Mautic\CoreBundle\Helper\FilePathResolver::createDirectory
     */
    public function testCreateNewDirectory()
    {
        $directory = 'my/directory';

        $this->filesystemMock->expects($this->once())
            ->method('exists')
            ->with($directory)
            ->willReturn(false);

        $this->filesystemMock->expects($this->once())
            ->method('mkdir')
            ->with($directory);

        $this->filePathResolver->createDirectory($directory);
    }

    /**
     * @testdox Directory could not be created
     *
     * @covers \Mautic\CoreBundle\Helper\FilePathResolver::createDirectory
     */
    public function testDirectoryCouldNotBeCreated()
    {
        $directory = 'my/directory';

        $this->filesystemMock->expects($this->once())
            ->method('exists')
            ->with($directory)
            ->willReturn(false);

        $this->filesystemMock->expects($this->once())
            ->method('mkdir')
            ->with($directory)
            ->willThrowException(new IOException(''));

        $this->expectException(FilePathException::class);
        $this->expectExceptionMessage('Could not create directory');

        $this->filePathResolver->createDirectory($directory);
    }

    /**
     * @testdox Successfuly detete file
     *
     * @covers \Mautic\CoreBundle\Helper\FilePathResolver::delete
     */
    public function testDeleteFile()
    {
        $file = 'my/file';

        $this->filesystemMock->expects($this->once())
            ->method('exists')
            ->with($file)
            ->willReturn(true);

        $this->filesystemMock->expects($this->once())
            ->method('remove')
            ->with($file);

        $this->filePathResolver->delete($file);
    }

    /**
     * @testdox File could not be deleted
     *
     * @covers \Mautic\CoreBundle\Helper\FilePathResolver::delete
     */
    public function testCouldNotDeleteFile()
    {
        $file = 'my/file';

        $this->filesystemMock->expects($this->once())
            ->method('exists')
            ->with($file)
            ->willReturn(true);

        $this->filesystemMock->expects($this->once())
            ->method('remove')
            ->with($file)
            ->willThrowException(new IOException(''));

        $this->filePathResolver->delete($file);
    }

    /**
     * @testdox File could not be deleted
     *
     * @covers \Mautic\CoreBundle\Helper\FilePathResolver::delete
     */
    public function testDeleteFileWhichNotExists()
    {
        $file = 'my/file';

        $this->filesystemMock->expects($this->once())
            ->method('exists')
            ->with($file)
            ->willReturn(false);

        $this->filesystemMock->expects($this->never())
            ->method('remove');

        $this->filePathResolver->delete($file);
    }

    public function testMove()
    {
        $originalPath = 'my/file';
        $targetPath   = 'my/new/file';

        $this->filesystemMock->expects($this->once())
            ->method('rename')
            ->with($originalPath, $targetPath);

        $this->filePathResolver->move($originalPath, $targetPath);
    }
}
