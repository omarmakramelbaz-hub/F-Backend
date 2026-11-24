<?php
namespace Tests\Feature;

use App\Support\MediaThumb;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class MediaThumbDetailTest extends TestCase
{
    private function media(string $disk = 'products', string $file = 'fish photo.jpg'): Media
    {
        return (new Media())->forceFill(['id' => 42, 'disk' => $disk, 'file_name' => $file]);
    }

    public function test_product_page_copy_is_a_1200_px_webp_alongside_the_list_sizes(): void
    {
        $this->assertSame([600, 400, 1000, 1200], MediaThumb::sizes());
        $this->assertStringEndsWith('/storage/thumbs/1200/products/42/fish%20photo.webp', MediaThumb::url($this->media(), MediaThumb::DETAIL_BOX));
        $this->assertStringEndsWith('/storage/thumbs/600/products/42/fish%20photo.webp', MediaThumb::url($this->media(), 600));
    }

    public function test_other_sizes_disks_and_files_get_no_copy(): void
    {
        $this->assertNull(MediaThumb::url($this->media(), 1300));
        $this->assertNull(MediaThumb::url($this->media('public'), MediaThumb::DETAIL_BOX));
        $this->assertNull(MediaThumb::url($this->media('products', 'menu.pdf'), MediaThumb::DETAIL_BOX));
    }

    public function test_copy_maker_refuses_sizes_outside_the_list(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        (new \App\Http\Controllers\MediaThumbController())->show('1300', 'products', '42', 'x');
    }
}
