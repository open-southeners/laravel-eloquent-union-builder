<?php

namespace OpenSoutheners\LaravelEloquentUnionBuilder\Tests;

use OpenSoutheners\LaravelEloquentUnionBuilder\Tests\Fixtures\Post;
use OpenSoutheners\LaravelEloquentUnionBuilder\Tests\Fixtures\Tag;
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
        $searchResults = UnionBuilder::search('hello', [Tag::class, Post::class])->get();

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
}