<?php

namespace Tests\Unit;

use App\Website\ScheduleContent;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduleContentTest extends TestCase
{
    private function content(): array
    {
        return ['days' => [['id' => 'day', 'date' => null, 'title' => null, 'visible' => true, 'activities' => [[
            'id' => 'activity', 'title' => 'Ceremony', 'startTime' => '15:30', 'endTime' => '16:30',
            'description' => "A\r\nB", 'location' => null, 'mapUrl' => null, 'imageMediaId' => null, 'visible' => true,
        ]]]]];
    }

    public function test_normalization_order_empty_drafts_and_no_mutation(): void
    {
        $input = $this->content();
        $input['days'][0]['activities'][0]['title'] = '';
        $input['days'][] = ['id' => 'empty', 'date' => null, 'title' => null, 'visible' => true, 'activities' => []];
        $result = app(ScheduleContent::class)->validate($input);
        $this->assertNull($result['days'][0]['activities'][0]['title']);
        $this->assertSame("A\nB", $result['days'][0]['activities'][0]['description']);
        $this->assertSame("A\r\nB", $input['days'][0]['activities'][0]['description']);
        $this->assertSame(['day', 'empty'], array_column($result['days'], 'id'));
        $this->assertSame([], app(ScheduleContent::class)->visibleDays($result['days']));
        $this->assertSame(['days' => []], app(ScheduleContent::class)->validate(['days' => []]));
    }

    #[DataProvider('invalidDates')]
    public function test_strict_calendar_date(string $date): void
    {
        $content = $this->content();
        $content['days'][0]['date'] = $date;
        $this->expectException(ValidationException::class);
        app(ScheduleContent::class)->validate($content);
    }

    public static function invalidDates(): array
    {
        return array_map(fn ($date) => [$date], ['2023-02-29', '2024-04-31', '0000-01-01', '2024-13-01', '2024-2-01']);
    }

    #[DataProvider('invalidActivities')]
    public function test_invalid_activity_contract(array $change): void
    {
        $content = $this->content();
        $content['days'][0]['activities'][0] = [...$content['days'][0]['activities'][0], ...$change];
        $this->expectException(ValidationException::class);
        app(ScheduleContent::class)->validate($content);
    }

    public static function invalidActivities(): array
    {
        return array_map(fn ($change) => [$change], [['startTime' => '24:00'], ['startTime' => null], ['endTime' => '15:30'], ['startTime' => '23:00', 'endTime' => '01:00'], ['secret' => 'unexpected'], ['visible' => 1], ['imageMediaId' => 'invalid'], ['title' => str_repeat('a', 256)]]);
    }

    #[DataProvider('mapUrls')]
    public function test_map_hostname_boundary_parity(string $url, bool $valid): void
    {
        $this->assertSame($valid, ScheduleContent::validMapUrl($url));
    }

    public static function mapUrls(): array
    {
        return [
            ['https://www.google.com/maps', true], ['https://maps.google.com/', true], ['https://maps.app.goo.gl/abc', true],
            ['https://www.waze.com/ul', true], ['https://maps.apple.com/', true], ['https://maps.apple.com:443/', true],
            ['http://maps.apple.com/', false], ['https://maps.apple.com.evil.com', false], ['https://user@maps.apple.com/', false],
            ['https://@maps.apple.com/', false], ['https://%6daps.apple.com/', false], ['https://evil.com/?maps.apple.com', false],
            ['https://maps.apple.com:444/', false], ['https://maps.apple.com\\@evil.com', false], ['javascript:alert(1)', false],
        ];
    }

    public function test_limits_uniqueness_unknown_fields_and_flat_content_rejected(): void
    {
        $content = $this->content();
        foreach ([['items' => []], [...$content, 'secret' => true], ['days' => [$content['days'][0], $content['days'][0]]], ['days' => array_fill(0, 21, $content['days'][0])]] as $invalid) {
            try {
                app(ScheduleContent::class)->validate($invalid);
                $this->fail('Malformed Schedule accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }
}
