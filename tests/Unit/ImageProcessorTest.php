<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Image\ImageProcessor;
use PHPUnit\Framework\TestCase;

final class ImageProcessorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/iwt-img-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testAJpegIsResizedAndReencodedAsWebp(): void
    {
        $source = $this->dir . '/big.jpg';
        $img = imagecreatetruecolor(3200, 1600);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        imagejpeg($img, $source);

        $ok = (new ImageProcessor())->toWebp($source, $this->dir . '/out.webp', 1600);

        self::assertTrue($ok);
        [$w, $h, $type] = getimagesize($this->dir . '/out.webp');
        self::assertSame([1600, 800, IMAGETYPE_WEBP], [$w, $h, $type]);
    }

    public function testASmallImageIsNotUpscaled(): void
    {
        $source = $this->dir . '/small.png';
        imagepng(imagecreatetruecolor(100, 50), $source);

        (new ImageProcessor())->toWebp($source, $this->dir . '/out.webp', 1600);

        self::assertSame([100, 50], array_slice(getimagesize($this->dir . '/out.webp'), 0, 2));
    }

    public function testANonImageIsRefused(): void
    {
        $source = $this->dir . '/fake.jpg';
        file_put_contents($source, '<?php echo 1;');

        self::assertFalse((new ImageProcessor())->toWebp($source, $this->dir . '/out.webp', 1600));
        self::assertFileDoesNotExist($this->dir . '/out.webp');
    }

    public function testMimeAllowList(): void
    {
        $p = new ImageProcessor();
        self::assertTrue($p->accepts('image/jpeg'));
        self::assertTrue($p->accepts('image/webp'));
        self::assertFalse($p->accepts('image/svg+xml'));
        self::assertFalse($p->accepts(null));
    }
}
