<?php

namespace OpenSoutheners\LaravelEloquentUnionBuilder\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Tag extends Model
{
    use Searchable;

    /**
     * The attributes that should be visible in serialization.
     *
     * @var string[]
     */
    protected $visible = ['name', 'slug'];

    /**
     * The attributes that aren't mass assignable.
     *
     * @var string[]
     */
    protected $guarded = [];

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
        ];
    }
}
