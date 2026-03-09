<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VideoTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiClipperWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_trending_workflow_can_be_created_without_video_url(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('ai-clipper.store'), [
            'workflow_mode' => 'auto_trending',
            'topic_hint' => 'AI Indonesia',
            'num_clips' => 3,
        ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('video_tasks', [
            'workflow_mode' => 'auto_trending',
            'topic_hint' => 'AI Indonesia',
            'num_clips' => 3,
            'youtube_url' => 'auto://trending',
            'youtube_publish_status' => 'review_pending',
        ]);
    }

    public function test_manual_mode_requires_url(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('ai-clipper.store'), [
            'workflow_mode' => 'manual_url',
            'num_clips' => 2,
        ]);

        $response->assertSessionHasErrors('url');
        $this->assertEquals(0, VideoTask::count());
    }

    public function test_authenticated_user_can_fetch_n8n_workflow_json_template(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('ai-clipper.workflow-json'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertSee('"nodes"');
        $response->assertSee('Webhook In');
        $response->assertSee('Callback to Laravel');
    }
}
