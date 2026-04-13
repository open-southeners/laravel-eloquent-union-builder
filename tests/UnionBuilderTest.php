<?php

namespace OpenSoutheners\LaravelEloquentUnionBuilder\Tests;

use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Scout\Builder as ScoutBuilder;
use OpenSoutheners\LaravelEloquentUnionBuilder\Tests\Fixtures\Post;
use OpenSoutheners\LaravelEloquentUnionBuilder\Tests\Fixtures\Tag;
use OpenSoutheners\LaravelEloquentUnionBuilder\Tests\Fixtures\User;
use OpenSoutheners\LaravelEloquentUnionBuilder\UnionBuilder;

class UnionBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Tag::insert([
            [
                'name' => 'Hello world',
                'slug' => 'hello-world',
            ],
            [
                'name' => 'I am a tag',
                'slug' => 'i-am-a-tag',
            ],
        ]);

        Tag::makeAllSearchable();

        Post::insert([
            [
                'title' => 'Hello world',
                'slug' => 'hello-world',
                'content' => 'Lorem ipsum dolor...',
            ],
            [
                'title' => 'I am a developer',
                'slug' => 'i-am-a-developer',
                'content' => 'Software developer from Spain',
            ],
            [
                'title' => 'Travel cheap',
                'slug' => 'travel-cheap',
                'content' => 'How to travel cheap',
            ],
        ]);

        Post::makeAllSearchable();
    }

    public function testUnionBuilderConstructReturnsSingleQueryResultsWithMultipleModels()
    {
        $tagQuery = Tag::query()->where('slug', 'hello-world');
        $postQuery = Post::query()->where('slug', 'hello-world');

        $queryResults = (new UnionBuilder([$tagQuery, $postQuery]))->get();

        $this->assertCount(2, $queryResults);

        $postsResults = $queryResults->filter(function ($result) {
            return $result instanceof Post;
        });

        $this->assertCount(1, $postsResults);

        $tagsResults = $queryResults->filter(function ($result) {
            return $result instanceof Tag;
        });

        $this->assertCount(1, $tagsResults);
    }

    public function testUnionBuilderFromModelsReturnsSingleQueryResultsWithMultipleModels()
    {
        $queryResults = UnionBuilder::from([Tag::class, Post::class])->where('slug', 'hello-world')->get();

        $this->assertCount(2, $queryResults);

        $postsResults = $queryResults->filter(function ($result) {
            return $result instanceof Post;
        });

        $this->assertCount(1, $postsResults);

        $tagsResults = $queryResults->filter(function ($result) {
            return $result instanceof Tag;
        });

        $this->assertCount(1, $tagsResults);
    }

    public function testUnionBuilderSearchReturnsResultsOfDifferentModels()
    {
        $searchResults = UnionBuilder::search('hello', [Tag::class, Post::class, User::class])->get();

        $this->assertCount(2, $searchResults);

        $postsResults = $searchResults->filter(function ($result) {
            return $result instanceof Post;
        });

        $this->assertCount(1, $postsResults);

        $tagsResults = $searchResults->filter(function ($result) {
            return $result instanceof Tag;
        });

        $this->assertCount(1, $tagsResults);
    }

    public function testUnionBuilderSearchThrowsExceptionWhenNonSearchableModelFoundInArgs()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Model '".Country::class."' is invalid.");

        UnionBuilder::search('hello', [Tag::class, Post::class, Country::class])->get();
    }

    public function testUnionBuilderCallingOnlyWhereOnPostReturnsFilteredPostsOnly()
    {
        $queryResults = UnionBuilder::from([Tag::class, Post::class])
            ->callingOnly(Post::class, function (Builder $query) {
                $query->where('slug', 'hello-world');
            })->get();

        $this->assertCount(3, $queryResults);

        $postsResults = $queryResults->filter(function ($result) {
            return $result instanceof Post;
        });

        $this->assertCount(1, $postsResults);

        $tagsResults = $queryResults->filter(function ($result) {
            return $result instanceof Tag;
        });

        $this->assertCount(2, $tagsResults);
    }

    public function testUnionBuilderThrowsExceptionWhenNoQueryBuilderInstancesWereSent()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No queries found for models query union.');

        (new UnionBuilder())->get();
    }

    public function testUnionBuilderCountReturnsCorrectTotalAcrossModels()
    {
        $count = UnionBuilder::from([Tag::class, Post::class])->count();

        $this->assertSame(5, $count);
    }

    public function testUnionBuilderCountWithWhereClauseReturnsFilteredCount()
    {
        $count = UnionBuilder::from([Tag::class, Post::class])->where('slug', 'hello-world')->count();

        $this->assertSame(2, $count);
    }

    public function testUnionBuilderOrderByUnionReturnsResultsOrderedByColumn()
    {
        $queryResults = UnionBuilder::from([Tag::class, Post::class])->orderByUnion('slug', 'asc')->get();

        $this->assertCount(5, $queryResults);
        $this->assertSame('hello-world', $queryResults->first()->slug);
        $this->assertSame('travel-cheap', $queryResults->last()->slug);
    }

    public function testUnionBuilderPaginateReturnsLengthAwarePaginator()
    {
        $result = UnionBuilder::from([Tag::class, Post::class])->paginate(3);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(5, $result->total());
        $this->assertCount(3, $result->items());

        foreach ($result->items() as $item) {
            $this->assertTrue($item instanceof Post || $item instanceof Tag);
        }
    }

    public function testUnionBuilderSimplePaginateReturnsPaginator()
    {
        $result = UnionBuilder::from([Tag::class, Post::class])->simplePaginate(3);

        $this->assertInstanceOf(Paginator::class, $result);
        $this->assertCount(3, $result->items());
    }

    public function testUnionBuilderToSqlReturnsNonEmptyStringWithUnionKeyword()
    {
        $sql = UnionBuilder::from([Tag::class, Post::class])->toSql();

        $this->assertNotEmpty($sql);
        $this->assertStringContainsStringIgnoringCase('union', $sql);
    }

    public function testUnionBuilderSearchWithPerModelCallbacksReturnsCorrectResults()
    {
        $searchResults = UnionBuilder::search('hello', [
            Post::class => fn (ScoutBuilder $builder) => $builder,
            Tag::class,
        ])->get();

        $this->assertCount(2, $searchResults);

        $postsResults = $searchResults->filter(function ($result) {
            return $result instanceof Post;
        });

        $this->assertCount(1, $postsResults);

        $tagsResults = $searchResults->filter(function ($result) {
            return $result instanceof Tag;
        });

        $this->assertCount(1, $tagsResults);
    }

    public function testUnionBuilderGetReturnsProperlyHydratedModelsWithHiddenAttributesRespected()
    {
        $queryResults = UnionBuilder::from([Post::class])->where('slug', 'hello-world')->get();

        $post = $queryResults->first(fn ($result) => $result instanceof Post);

        $this->assertNotNull($post);
        $this->assertFalse(array_key_exists('slug', $post->toArray()));
    }
}
