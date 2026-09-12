<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\PermissionResolver;
use App\Domain\CustomFields\CustomFieldRegistry;
use App\Domain\Products\Actions\DeleteProductAction;
use App\Domain\Products\Actions\SaveProductAction;
use App\Domain\Products\DTOs\ProductData;
use App\Domain\Products\Enums\ProductKind;
use App\Domain\Products\Enums\ProductUnit;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductBundleItem;
use App\Domain\Workflows\WorkflowModules;
use App\Livewire\Products\ProductForm;
use App\Livewire\Products\ProductsIndex;
use App\Models\User;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function catalogueUser(array $permissions = ['products.view', 'products.create', 'products.update', 'products.delete']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function productData(array $overrides = []): ProductData
{
    return ProductData::fromArray([
        'name' => 'Support retainer',
        'kind' => ProductKind::Service->value,
        'unit' => ProductUnit::Month->value,
        'list_price' => '250',
        ...$overrides,
    ]);
}

// -- CRUD ---------------------------------------------------------------------

test('a product saves with everything it was given', function () {
    $owner = catalogueUser();
    $this->actingAs($owner);

    $product = app(SaveProductAction::class)(productData([
        'name' => 'Onsite day',
        'sku' => 'ons-1',
        'kind' => ProductKind::Service->value,
        'unit' => ProductUnit::Day->value,
        'category' => 'Consulting',
        'list_price' => '1200.50',
        'tax_rate' => '15',
        'owner_id' => $owner->id,
    ]));

    expect($product->name)->toBe('Onsite day')
        // Uppercased: a catalogue with "ons-1" and "ONS-1" is a catalogue
        // nobody can order from.
        ->and($product->sku)->toBe('ONS-1')
        ->and($product->kind())->toBe(ProductKind::Service)
        ->and($product->unit())->toBe(ProductUnit::Day)
        ->and($product->listPrice())->toBe(1200.50)
        ->and($product->owner_id)->toBe($owner->id)
        ->and($product->is_active)->toBeTrue();
});

test('a product updates without being duplicated', function () {
    $this->actingAs(catalogueUser());

    $product = app(SaveProductAction::class)(productData());

    app(SaveProductAction::class)(productData(['name' => 'Renamed', 'list_price' => '300']), $product);

    expect(Product::query()->count())->toBe(1)
        ->and($product->fresh()->name)->toBe('Renamed')
        ->and($product->fresh()->listPrice())->toBe(300.0);
});

test('a product is removed, and softly', function () {
    $this->actingAs(catalogueUser());

    $product = app(SaveProductAction::class)(productData());

    app(DeleteProductAction::class)($product);

    expect(Product::query()->count())->toBe(0)
        ->and(Product::query()->withTrashed()->count())->toBe(1);
});

test('a price is kept to the penny, not to a float', function () {
    // 0.1 + 0.2 is not 0.3 in binary floating point, and a quote total that is
    // out by a hundredth is a quote somebody has to explain.
    $this->actingAs(catalogueUser());

    $product = app(SaveProductAction::class)(productData(['list_price' => '0.1']));

    expect((string) $product->fresh()->list_price)->toBe('0.10');
});

test('a service carries no unit cost it cannot have', function () {
    $this->actingAs(catalogueUser());

    $bundle = app(SaveProductAction::class)(productData([
        'kind' => ProductKind::Bundle->value,
        'cost_price' => '99',
        'components' => [['product_id' => Product::factory()->create()->id, 'quantity' => 1]],
    ]));

    // A bundle's cost is the sum of what is in it; a second answer beside the
    // first would drift from it.
    expect($bundle->cost_price)->toBeNull();
});

test('the margin is the difference, or nothing when there is no cost', function () {
    $withCost = Product::factory()->pricedAt(100, 60)->create();
    $without = Product::factory()->pricedAt(100)->create();

    expect($withCost->margin())->toBe(40.0)
        ->and($without->margin())->toBeNull();
});

// -- Bundles -------------------------------------------------------------------

test('a bundle holds what it is made of, in order', function () {
    $this->actingAs(catalogueUser());

    $first = Product::factory()->pricedAt(100)->create();
    $second = Product::factory()->pricedAt(50)->create();

    $bundle = app(SaveProductAction::class)(productData([
        'kind' => ProductKind::Bundle->value,
        'list_price' => '120',
        'components' => [
            ['product_id' => $second->id, 'quantity' => 2],
            ['product_id' => $first->id, 'quantity' => 1],
        ],
    ]));

    $bundle->load('components.product');

    expect($bundle->components)->toHaveCount(2)
        ->and($bundle->components[0]->product_id)->toBe($second->id)
        ->and($bundle->components[0]->quantity())->toBe(2.0)
        // The parts come to 200; the bundle sells for 120, which is the point
        // of a bundle.
        ->and($bundle->componentTotal())->toBe(200.0)
        ->and($bundle->listPrice())->toBe(120.0);
});

test('editing a bundle reconciles its parts rather than piling them up', function () {
    $this->actingAs(catalogueUser());

    $keep = Product::factory()->create();
    $drop = Product::factory()->create();
    $add = Product::factory()->create();

    $bundle = app(SaveProductAction::class)(productData([
        'kind' => ProductKind::Bundle->value,
        'components' => [
            ['product_id' => $keep->id, 'quantity' => 1],
            ['product_id' => $drop->id, 'quantity' => 1],
        ],
    ]));

    app(SaveProductAction::class)(productData([
        'kind' => ProductKind::Bundle->value,
        'components' => [
            ['product_id' => $keep->id, 'quantity' => 3],
            ['product_id' => $add->id, 'quantity' => 1],
        ],
    ]), $bundle);

    $parts = $bundle->fresh()->components;

    expect($parts)->toHaveCount(2)
        ->and($parts->pluck('product_id')->all())->toBe([$keep->id, $add->id])
        ->and($parts->first()->quantity())->toBe(3.0);
});

test('a bundle cannot contain itself', function () {
    // Every price, every cost and every explosion into line items walks the
    // tree; a cycle means each of those recurses until the process dies.
    $this->actingAs(catalogueUser());

    $bundle = app(SaveProductAction::class)(productData([
        'kind' => ProductKind::Bundle->value,
        'components' => [['product_id' => Product::factory()->create()->id, 'quantity' => 1]],
    ]));

    expect(fn () => app(SaveProductAction::class)(productData([
        'kind' => ProductKind::Bundle->value,
        'components' => [['product_id' => $bundle->id, 'quantity' => 1]],
    ]), $bundle))->toThrow(RuntimeException::class);
});

test('a bundle cannot contain itself through another bundle', function () {
    $this->actingAs(catalogueUser());

    $inner = app(SaveProductAction::class)(productData([
        'name' => 'Inner',
        'kind' => ProductKind::Bundle->value,
        'components' => [['product_id' => Product::factory()->create()->id, 'quantity' => 1]],
    ]));

    $outer = app(SaveProductAction::class)(productData([
        'name' => 'Outer',
        'kind' => ProductKind::Bundle->value,
        'components' => [['product_id' => $inner->id, 'quantity' => 1]],
    ]));

    // Outer already contains Inner, so Inner containing Outer closes the loop.
    expect(fn () => app(SaveProductAction::class)(productData([
        'name' => 'Inner',
        'kind' => ProductKind::Bundle->value,
        'components' => [['product_id' => $outer->id, 'quantity' => 1]],
    ]), $inner))->toThrow(RuntimeException::class);

    // And nothing was written: the guard runs inside the transaction.
    expect(ProductBundleItem::query()->where('bundle_id', $inner->id)->pluck('product_id')->all())
        ->not->toContain($outer->id);
});

test('a component listed twice is stored once', function () {
    $this->actingAs(catalogueUser());

    $part = Product::factory()->create();

    $bundle = app(SaveProductAction::class)(productData([
        'kind' => ProductKind::Bundle->value,
        'components' => [
            ['product_id' => $part->id, 'quantity' => 1],
            ['product_id' => $part->id, 'quantity' => 5],
        ],
    ]));

    expect($bundle->components)->toHaveCount(1);
});

test('changing a bundle to a product leaves no parts behind', function () {
    $this->actingAs(catalogueUser());

    $bundle = app(SaveProductAction::class)(productData([
        'kind' => ProductKind::Bundle->value,
        'components' => [['product_id' => Product::factory()->create()->id, 'quantity' => 1]],
    ]));

    app(SaveProductAction::class)(productData(['kind' => ProductKind::Product->value]), $bundle);

    expect(ProductBundleItem::query()->where('bundle_id', $bundle->id)->count())->toBe(0);
});

test('a product inside a bundle cannot be removed', function () {
    // Removing it would quietly change what that bundle contains, and what it
    // is worth.
    $this->actingAs(catalogueUser());

    $part = Product::factory()->create(['name' => 'Widget']);
    $bundle = Product::factory()->bundle()->create(['name' => 'Starter kit']);
    ProductBundleItem::factory()->of($bundle, $part)->create();

    expect(fn () => app(DeleteProductAction::class)($part))
        ->toThrow(RuntimeException::class);

    expect(Product::query()->whereKey($part->id)->exists())->toBeTrue();
});

test('the refusal names the bundle, so somebody knows where to look', function () {
    $this->actingAs(catalogueUser());

    $part = Product::factory()->create();
    $bundle = Product::factory()->bundle()->create(['name' => 'Starter kit']);
    ProductBundleItem::factory()->of($bundle, $part)->create();

    try {
        app(DeleteProductAction::class)($part);
        $this->fail('The delete should have been refused.');
    } catch (RuntimeException $refused) {
        expect($refused->getMessage())->toContain('Starter kit');
    }
});

test('a bundle itself can be removed, and takes its parts list with it', function () {
    $this->actingAs(catalogueUser());

    $part = Product::factory()->create();
    $bundle = Product::factory()->bundle()->create();
    ProductBundleItem::factory()->of($bundle, $part)->create();

    app(DeleteProductAction::class)($bundle);

    // The bundle soft-deletes; the component rows are not history and go.
    expect(Product::query()->whereKey($bundle->id)->exists())->toBeFalse()
        ->and(Product::query()->whereKey($part->id)->exists())->toBeTrue();
});

// -- The screens -----------------------------------------------------------------

test('the catalogue list needs its permission', function () {
    $this->actingAs(User::factory()->create())->get(route('products.index'))->assertForbidden();
    $this->actingAs(catalogueUser(['products.view']))->get(route('products.index'))->assertOk();
});

test('the list shows products and their prices', function () {
    $user = catalogueUser();
    Product::factory()->ownedBy($user)->create(['name' => 'Onsite day', 'list_price' => 1200]);

    Livewire::actingAs($user)
        ->test(ProductsIndex::class)
        ->assertOk()
        ->assertSee('Onsite day')
        ->assertSee('1,200.00');
});

test('the list only shows what the viewer may see', function () {
    // The component owns the query, including visibleTo(), so a row outside the
    // access level never reaches the page.
    $mine = catalogueUser();
    $theirs = User::factory()->create();

    Product::factory()->ownedBy($mine)->create(['name' => 'Mine to sell']);
    Product::factory()->ownedBy($theirs)->create(['name' => 'Theirs to sell']);

    $rows = Livewire::actingAs($mine)
        ->test(ProductsIndex::class)
        ->instance()
        ->dataViewBaseQuery()
        ->pluck('name');

    expect($rows)->toContain('Mine to sell');
});

test('the form saves a product', function () {
    $user = catalogueUser();

    Livewire::actingAs($user)
        ->test(ProductForm::class)
        ->set('name', 'Onsite day')
        ->set('kind', ProductKind::Service->value)
        ->set('unit', ProductUnit::Day->value)
        ->set('listPrice', '1200')
        ->set('ownerId', (string) $user->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Product::query()->sole()->name)->toBe('Onsite day');
});

test('the form refuses a duplicate sku', function () {
    $user = catalogueUser();
    Product::factory()->create(['sku' => 'AB-1']);

    Livewire::actingAs($user)
        ->test(ProductForm::class)
        ->set('name', 'Another')
        ->set('sku', 'AB-1')
        ->set('listPrice', '10')
        ->set('ownerId', (string) $user->id)
        ->call('save')
        ->assertHasErrors('sku');
});

test('a product keeps its own sku when edited', function () {
    // The unique rule has to ignore the row being saved, or nothing could ever
    // be edited twice.
    $user = catalogueUser();
    $product = Product::factory()->ownedBy($user)->create(['sku' => 'AB-1']);

    Livewire::actingAs($user)
        ->test(ProductForm::class, ['product' => $product])
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh()->name)->toBe('Renamed');
});

test('the form refuses a bundle with nothing in it', function () {
    $user = catalogueUser();

    Livewire::actingAs($user)
        ->test(ProductForm::class)
        ->set('name', 'Empty kit')
        ->set('kind', ProductKind::Bundle->value)
        ->set('listPrice', '10')
        ->set('ownerId', (string) $user->id)
        ->set('components', [])
        ->call('save')
        ->assertHasErrors('components');
});

test('a bundle cannot be built out of other bundles', function () {
    // A bundle of bundles is a tree somebody has to hold in their head while
    // reading a quote.
    $user = catalogueUser();
    Product::factory()->bundle()->ownedBy($user)->create(['name' => 'Inner kit']);
    Product::factory()->ownedBy($user)->create(['name' => 'Plain widget']);

    $options = Livewire::actingAs($user)
        ->test(ProductForm::class)
        ->instance()
        ->componentOptions();

    expect($options)->toContain('Plain widget')
        ->and($options)->not->toContain('Inner kit');
});

test('the form reports a loop rather than throwing at the person', function () {
    $user = catalogueUser();

    $inner = Product::factory()->bundle()->ownedBy($user)->create();
    $outer = Product::factory()->bundle()->ownedBy($user)->create();
    ProductBundleItem::factory()->of($outer, $inner)->create();

    $screen = Livewire::actingAs($user)
        ->test(ProductForm::class, ['product' => $inner])
        ->set('components', [['product_id' => (string) $outer->id, 'quantity' => '1']])
        ->call('save');

    expect($screen->get('error'))->toContain('loop');
});

test('the catalogue is a module custom fields can be added to', function () {
    expect(CustomFieldRegistry::has('products'))->toBeTrue()
        ->and(CustomFieldRegistry::modelClass('products'))->toBe(Product::class);
});

test('a workflow can watch the catalogue', function () {
    expect(WorkflowModules::has('products'))->toBeTrue()
        ->and(WorkflowModules::fields('products'))->not->toBeEmpty();
});

test('the permissions are declared in the catalogue', function (string $permission) {
    expect(PermissionCatalogue::has($permission))->toBeTrue();
})->with(['products.view', 'products.create', 'products.update', 'products.delete', 'products.export', 'products.pricing']);

test('every kind and unit reads as something', function () {
    foreach (ProductKind::cases() as $kind) {
        expect($kind->label())->not->toBeEmpty()
            ->and($kind->color())->not->toBeEmpty();
    }

    foreach (ProductUnit::cases() as $unit) {
        expect($unit->label())->not->toBeEmpty();
    }
});

test('a unit reads plurally beside a quantity', function () {
    expect(ProductUnit::Hour->forQuantity(1))->toBe('hour')
        ->and(ProductUnit::Hour->forQuantity(3))->toBe('hours')
        ->and(ProductUnit::Each->forQuantity(2))->toBe('eaches');
});
