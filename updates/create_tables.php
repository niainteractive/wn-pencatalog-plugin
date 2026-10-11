<?php namespace Niainteractive\PenCatalog\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class CreateTables extends Migration
{
    public function up()
    {
        Schema::create('niainteractive_pencatalog_item_types', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->string('name');
            $table->string('slug')->nullable()->index();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('niainteractive_pencatalog_term_groups', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->string('name');
            $table->string('slug')->nullable()->index();
            $table->text('description')->nullable();
            $table->boolean('is_multi')->default(true);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('niainteractive_pencatalog_terms', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('group_id')->unsigned()->nullable()->index();
            $table->string('name');
            $table->string('slug')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('color_hex', 9)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('niainteractive_pencatalog_type_groups', function ($table) {
            $table->engine = 'InnoDB';
            $table->integer('type_id')->unsigned()->index();
            $table->integer('group_id')->unsigned()->index();
        });

        Schema::create('niainteractive_pencatalog_makes', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->string('name');
            $table->string('slug')->nullable()->unique();
            $table->string('website')->nullable();
            $table->integer('country_term_id')->unsigned()->nullable()->index();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('niainteractive_pencatalog_vendors', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->string('name');
            $table->string('slug')->nullable()->unique();
            $table->string('website')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('niainteractive_pencatalog_items', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('type_id')->unsigned()->nullable()->index();
            $table->integer('make_id')->unsigned()->nullable()->index();
            $table->string('model')->nullable();
            $table->string('slug')->nullable()->unique();
            $table->tinyInteger('rating')->unsigned()->nullable();
            $table->decimal('length_closed', 6, 1)->nullable();
            $table->decimal('length_uncapped', 6, 1)->nullable();
            $table->decimal('length_posted', 6, 1)->nullable();
            $table->decimal('weight', 6, 1)->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('niainteractive_pencatalog_item_term', function ($table) {
            $table->engine = 'InnoDB';
            $table->integer('item_id')->unsigned()->index();
            $table->integer('term_id')->unsigned()->index();
            $table->integer('sort_order')->default(0);
        });

        Schema::create('niainteractive_pencatalog_offers', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('item_id')->unsigned()->nullable()->index();
            $table->integer('vendor_id')->unsigned()->nullable()->index();
            $table->string('label')->nullable();
            $table->string('url', 500)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->date('price_date')->nullable();
            $table->string('availability', 20)->default('unknown');
            $table->decimal('shipping', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('niainteractive_pencatalog_item_photos', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('item_id')->unsigned()->nullable()->index();
            $table->integer('file_id')->unsigned()->nullable()->index();
            $table->string('caption')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('niainteractive_pencatalog_item_photos');
        Schema::dropIfExists('niainteractive_pencatalog_offers');
        Schema::dropIfExists('niainteractive_pencatalog_item_term');
        Schema::dropIfExists('niainteractive_pencatalog_items');
        Schema::dropIfExists('niainteractive_pencatalog_vendors');
        Schema::dropIfExists('niainteractive_pencatalog_makes');
        Schema::dropIfExists('niainteractive_pencatalog_type_groups');
        Schema::dropIfExists('niainteractive_pencatalog_terms');
        Schema::dropIfExists('niainteractive_pencatalog_term_groups');
        Schema::dropIfExists('niainteractive_pencatalog_item_types');
    }
}
