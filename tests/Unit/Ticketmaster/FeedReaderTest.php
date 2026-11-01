<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketmaster;

use App\Ticketmaster\FeedReader;
use JsonMachine\Exception\SyntaxErrorException;
use PHPUnit\Framework\TestCase;

/** Le vrai parseur en flux, sur de vrais gzip — petits, mais de la même forme que le flux. */
final class FeedReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/iwt-feed-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testStreamsEventsFromTheGzippedSingleObject(): void
    {
        $path = $this->dir . '/feed.json.gz';
        file_put_contents($path, gzencode('{"events":[{"eventId":"a","eventName":"A"},{"eventId":"b","eventName":"B"}],"meta":{"x":1}}'));

        $ids = [];
        foreach ((new FeedReader())->events($path) as $event) {
            $ids[] = $event['eventId'];
        }

        self::assertSame(['a', 'b'], $ids);
    }

    public function testATruncatedFeedRaisesInsteadOfEndingQuietly(): void
    {
        $full = gzencode('{"events":[' . str_repeat('{"eventId":"a","eventName":"' . str_repeat('x', 500) . '"},', 200) . '{"eventId":"z"}]}');
        $path = $this->dir . '/truncated.json.gz';
        file_put_contents($path, substr($full, 0, (int) (strlen($full) / 2)));

        $this->expectException(SyntaxErrorException::class);
        foreach ((new FeedReader())->events($path) as $event) {
            // on consomme jusqu'à la coupure
        }
    }
}
