<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stand-in business record for exercising the shared UI kit without waiting
 * on a real module.
 *
 * @property int $id
 * @property string $name
 * @property string $stage
 * @property int $owner_id
 * @property float $value
 */
class DataViewRecord extends Model
{
    protected $table = 'data_view_records';

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'stage', 'owner_id', 'value', 'closes_on', 'is_starred'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'closes_on' => 'date',
            'is_starred' => 'boolean',
        ];
    }

    public static function createTable(): void
    {
        if (Schema::hasTable('data_view_records')) {
            return;
        }

        Schema::create('data_view_records', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('stage')->default('new');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->decimal('value', 15, 2)->default(0);
            $table->date('closes_on')->nullable();
            $table->boolean('is_starred')->default(false);
            $table->timestamps();

            $table->index('stage');
        });
    }
}
