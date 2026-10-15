<?php

namespace Tests\Unit;

use App\Website\Transitions\ScheduleDaysTransition;
use Tests\TestCase;

class ScheduleDaysTransitionTest extends TestCase
{
    public function test_explicit_transition_preserves_ids_authored_order_and_divergent_content_without_mutation(): void
    {
        $flat = ['items' => [
            ['id' => 'later', 'time' => '18:00', 'title' => 'Reception - duplicate', 'details' => "A\nB"],
            ['id' => 'earlier', 'time' => '14:30', 'title' => 'Ceremony', 'details' => 'Garden'],
        ]];
        $transition = app(ScheduleDaysTransition::class);
        $result = $transition->convert($flat, 'new-day');
        $this->assertSame(['later', 'earlier'], array_column($result['days'][0]['activities'], 'id'));
        foreach ($flat['items'] as $index => $entry) {
            $activity = $result['days'][0]['activities'][$index];
            $this->assertSame($entry['title'], $activity['title']);
            $this->assertSame($entry['time'], $activity['startTime']);
            $this->assertSame($entry['details'], $activity['description']);
        }
        $divergent = $flat;
        $divergent['items'][0]['title'] = 'Device-specific copy';
        $this->assertSame('Device-specific copy', $transition->convert($divergent, 'other-day')['days'][0]['activities'][0]['title']);
        $this->assertSame('Reception - duplicate', $flat['items'][0]['title']);
        $this->assertSame([], $transition->convert(['items' => []], 'empty')['days'][0]['activities']);
    }
}
