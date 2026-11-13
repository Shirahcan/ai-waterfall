<?php

namespace Shirahcan\AiClient\Tests\Laravel;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Shirahcan\AiClient\AiClientServiceProvider;
use Shirahcan\AiClient\Laravel\Terminal\DatabaseCheckpointStore;

/**
 * The kit's default rewind-point store (1.71.0, shared console C3).
 *
 * ⚠ WHAT THIS GUARDS: a point reads back only for ITS subject; one document can be two subjects (a post and its
 * card) with separate points; the actor pair is kept; only the newest points are kept; a deleted document's points
 * can be dropped; and the table is NOT created unless the product publishes the migration.
 */
class CheckpointStoreTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
    }

    public function test_the_table_is_not_created_unless_the_product_publishes_it(): void
    {
        $this->artisan('migrate')->run();

        $this->assertFalse(Schema::hasTable(DatabaseCheckpointStore::TABLE));
    }

    public function test_points_belong_to_one_subject_and_a_document_can_be_two_subjects(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->actingAs(new GenericUser(['id' => 'u-1', 'name' => 'Bukky']));
        $store = new DatabaseCheckpointStore;

        $post = $store->rewindPoint('growth.post', 'd1', [['uid' => 'h1', 'text' => 'Before']], 's-1', 'u-1');
        $card = $store->rewindPoint('growth.post:card', 'd1', ['title' => 'Card before'], 's-2', 'u-1');

        $this->assertSame([['uid' => 'h1', 'text' => 'Before']], $store->point('growth.post', 'd1', $post));
        $this->assertSame(['title' => 'Card before'], $store->point('growth.post:card', 'd1', $card));
        $this->assertNull($store->point('growth.post', 'd1', $card), 'a card point never restores the post');
        $this->assertNull($store->point('growth.post', 'd2', $post), 'nor another document');

        $row = \DB::table(DatabaseCheckpointStore::TABLE)->where('id', $post)->first();
        $this->assertSame(['u-1', 'Bukky'], [$row->created_by_ref, $row->created_by_name]);

        $this->assertSame(1, $store->forget('growth.post', 'd1'));
        $this->assertNotNull($store->point('growth.post:card', 'd1', $card), 'forgetting the post keeps its card subject');
    }

    public function test_only_the_newest_points_are_kept(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $store = new DatabaseCheckpointStore;

        $first = $store->rewindPoint('site.page', 'p1', ['v' => 0], null, null);
        $this->travel(1)->seconds();
        for ($i = 1; $i <= DatabaseCheckpointStore::KEEP; $i++) {
            $store->rewindPoint('site.page', 'p1', ['v' => $i], null, null);
        }

        $this->assertSame(DatabaseCheckpointStore::KEEP, \DB::table(DatabaseCheckpointStore::TABLE)->where('subject_id', 'p1')->count());
        $this->assertNull($store->point('site.page', 'p1', $first), 'the oldest point went first');
    }
}
