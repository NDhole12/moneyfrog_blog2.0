<?php

use MoneyfrogCore\Cli\Importer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImporterTest extends TestCase
{
    private const NEW = 'https://new.example/wp-content/uploads/';

    /** @return array<string,string> */
    private function map(): array
    {
        return [
            'https://blog.moneyfrog.in/wp-content/uploads/2019/01/x.jpg' => self::NEW . '2019/01/x.jpg',
            'https://blog.moneyfrog.in/wp-content/uploads/2025/09/Post-–-Sep.jpg' => self::NEW . '2025/09/Post-%E2%80%93-Sep.jpg',
        ];
    }

    public function test_rewrite_image_urls_handles_src_and_srcset(): void
    {
        $html = '<p><img src="http://blog.moneyfrog.in/wp-content/uploads/2019/01/x-1024x434.jpg" alt="a" class="wp-image-7 aligncenter"'
            . ' srcset="https://blog.moneyfrog.in/wp-content/uploads/2019/01/x-300x127.jpg 300w, https://blog.moneyfrog.in/wp-content/uploads/2019/01/x.jpg 1080w" sizes="(max-width: 1024px) 100vw"></p>';
        $out = Importer::rewriteImageUrls($html, $this->map());

        $this->assertStringContainsString('src="' . self::NEW . '2019/01/x.jpg"', $out);
        $this->assertStringContainsString('srcset="' . self::NEW . '2019/01/x.jpg 300w, ' . self::NEW . '2019/01/x.jpg 1080w"', $out);
        $this->assertStringNotContainsString('blog.moneyfrog.in', $out);
        $this->assertStringNotContainsString('wp-image-7', $out, 'old attachment id class must not survive');
        $this->assertStringContainsString('aligncenter', $out);
    }

    public function test_rewrite_handles_encoded_and_unicode_filenames(): void
    {
        $html = '<img src="https://blog.moneyfrog.in/wp-content/uploads/2025/09/Post-%E2%80%93-Sep-1024x1024.jpg" alt="">';
        $this->assertStringContainsString(
            'src="' . self::NEW . '2025/09/Post-%E2%80%93-Sep.jpg"',
            Importer::rewriteImageUrls($html, $this->map())
        );
    }

    public function test_unmappable_srcset_is_dropped_but_src_kept(): void
    {
        $html = '<img src="https://blog.moneyfrog.in/wp-content/uploads/2019/01/x.jpg" srcset="https://blog.moneyfrog.in/wp-content/uploads/2019/01/x.jpg 1w, https://elsewhere.test/y.jpg 2w" sizes="100vw">';
        $out = Importer::rewriteImageUrls($html, $this->map());
        $this->assertStringContainsString('src="' . self::NEW . '2019/01/x.jpg"', $out);
        $this->assertStringNotContainsString('srcset', $out);
        $this->assertStringNotContainsString('sizes', $out);
    }

    public function test_missing_image_is_removed_and_reported(): void
    {
        $html = '<p>Before</p><figure class="wp-block-image"><a href="x"><img src="https://blog.moneyfrog.in/wp-content/uploads/2019/01/gone.jpg" alt=""></a></figure>'
            . '<p><img src="https://hotlink.test/pic.png" alt="">After</p><p><img src="data:image/gif;base64,R0lGOD" alt=""></p>';
        $res = Importer::rewriteContent($html, $this->map());

        $this->assertStringNotContainsString('gone.jpg', $res['html']);
        $this->assertStringNotContainsString('hotlink.test', $res['html']);
        $this->assertStringNotContainsString('<figure', $res['html']);
        $this->assertStringContainsString('After', $res['html']);
        $this->assertStringContainsString('data:image/gif', $res['html']);
        $this->assertSame(
            ['https://blog.moneyfrog.in/wp-content/uploads/2019/01/gone.jpg', 'https://hotlink.test/pic.png'],
            $res['failedImages']
        );
    }

    public function test_old_post_links_become_relative(): void
    {
        $html = '<a href="https://blog.moneyfrog.in/market-outlook-june-2022/">a</a> <a href="http://blog.moneyfrog.in/x/?utm_source=a#b">b</a>'
            . ' <a href="https://moneyfrog.in/contact-us">c</a> <a href="https://blog.moneyfrog.in/wp-content/uploads/2019/01/x.jpg">d</a>';
        $out = Importer::rewriteContent($html, $this->map())['html'];
        $this->assertStringContainsString('href="/market-outlook-june-2022/"', $out);
        $this->assertStringContainsString('href="/x/?utm_source=a#b"', $out);
        $this->assertStringContainsString('href="https://moneyfrog.in/contact-us"', $out);
        $this->assertStringContainsString('href="' . self::NEW . '2019/01/x.jpg"', $out, 'links to uploaded files follow the file');
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_upload_paths_are_rejected(string $path): void
    {
        $this->assertNull(Importer::safeUploadPath($path));
    }

    /** @return array<string,array{string}> */
    public static function unsafePaths(): array
    {
        return [
            'parent' => ['../wp-config.php'],
            'nested parent' => ['2019/../../x.jpg'],
            'absolute' => ['/etc/passwd'],
            'backslash' => ['2019\01\x.jpg'],
            'drive' => ['C:/x.jpg'],
            'nul' => ["2019/x\0.jpg"],
            'empty' => [''],
        ];
    }

    public function test_safe_upload_path_accepts_normal_paths(): void
    {
        $this->assertSame('2019/01/x.jpg', Importer::safeUploadPath('2019/01/x.jpg'));
        $this->assertSame('2025/09/Post-–-Sep.jpg', Importer::safeUploadPath('2025/09/Post-–-Sep.jpg'));
    }

    public function test_content_hash_is_stable_and_sensitive(): void
    {
        $a = Importer::contentHash(['title' => 'A', 'tags' => ['x']]);
        $this->assertSame($a, Importer::contentHash(['tags' => ['x'], 'title' => 'A']));
        $this->assertNotSame($a, Importer::contentHash(['title' => 'B', 'tags' => ['x']]));
    }
}
