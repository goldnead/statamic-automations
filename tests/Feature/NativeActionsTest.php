<?php

/**
 * Native Statamic actions (A6). Each new action node performs its real
 * Statamic operation against the file repositories the test harness uses.
 * Every action is registered (dogfooded) through the public API, so we also
 * assert they surface in the node library under the "Statamic" group.
 */

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Nodes\Actions\AddUserToGroupAction;
use Goldnead\StatamicAutomations\Nodes\Actions\AssignUserRoleAction;
use Goldnead\StatamicAutomations\Nodes\Actions\CreateTermAction;
use Goldnead\StatamicAutomations\Nodes\Actions\DeleteEntryAction;
use Goldnead\StatamicAutomations\Nodes\Actions\PublishEntryAction;
use Goldnead\StatamicAutomations\Nodes\Actions\SetGlobalValueAction;
use Goldnead\StatamicAutomations\Nodes\Actions\UnpublishEntryAction;
use Goldnead\StatamicAutomations\Nodes\Actions\UpdateUserAction;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Role;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;

function seedBlogEntry(bool $published): string
{
    if (! CollectionFacade::findByHandle('blog')) {
        CollectionFacade::make('blog')->title('Blog')->save();
    }

    $entry = Entry::make()
        ->collection('blog')
        ->locale('default')
        ->slug('post-' . uniqid())
        ->data(['title' => 'Post'])
        ->published($published);
    $entry->save();

    return $entry->id();
}

it('publish_entry publishes an entry', function (): void {
    $id = seedBlogEntry(published: false);

    $result = (new PublishEntryAction())->execute(AutomationContext::make(), ['entry_id' => $id]);

    expect($result->isSuccess())->toBeTrue();
    expect(Entry::find($id)->published())->toBeTrue();
});

it('unpublish_entry unpublishes an entry', function (): void {
    $id = seedBlogEntry(published: true);

    $result = (new UnpublishEntryAction())->execute(AutomationContext::make(), ['entry_id' => $id]);

    expect($result->isSuccess())->toBeTrue();
    expect(Entry::find($id)->published())->toBeFalse();
});

it('delete_entry deletes an entry', function (): void {
    $id = seedBlogEntry(published: true);

    $result = (new DeleteEntryAction())->execute(AutomationContext::make(), ['entry_id' => $id]);

    expect($result->isSuccess())->toBeTrue();
    expect(Entry::find($id))->toBeNull();
});

it('delete_entry does not delete in test mode', function (): void {
    $id = seedBlogEntry(published: true);

    $result = (new DeleteEntryAction())->execute(AutomationContext::make([], testMode: true), ['entry_id' => $id]);

    expect($result->isSuccess())->toBeTrue();
    expect($result->output)->toHaveKey('preview');
    expect(Entry::find($id))->not->toBeNull();
});

it('create_term creates a taxonomy term', function (): void {
    // Unique taxonomy handle so the created term never leaks into other tests
    // via the shared file store.
    Taxonomy::make('na_genres')->title('Genres')->save();

    $result = (new CreateTermAction())->execute(AutomationContext::make(), [
        'taxonomy' => 'na_genres',
        'data' => ['title' => 'Jazz'],
    ]);

    expect($result->isSuccess())->toBeTrue();

    $slugs = Term::query()->where('taxonomy', 'na_genres')->get()
        ->map(fn ($t) => $t->slug())->all();
    expect($slugs)->toContain('jazz');
});

it('update_user merges field data', function (): void {
    $user = User::make()->email('jane@example.com')->data(['name' => 'Jane']);
    $user->save();

    $result = (new UpdateUserAction())->execute(AutomationContext::make(), [
        'user_id' => $user->id(),
        'data' => ['name' => 'Janet'],
    ]);

    expect($result->isSuccess())->toBeTrue();
    expect(User::find($user->id())->get('name'))->toBe('Janet');
});

it('assign_user_role adds and removes a role', function (): void {
    $role = Role::make('editor')->title('Editor');
    Role::save($role);

    $user = User::make()->email('roled@example.com');
    $user->save();

    (new AssignUserRoleAction())->execute(AutomationContext::make(), [
        'user_id' => $user->id(), 'role' => 'editor', 'mode' => 'add',
    ]);
    expect(User::find($user->id())->hasRole('editor'))->toBeTrue();

    (new AssignUserRoleAction())->execute(AutomationContext::make(), [
        'user_id' => $user->id(), 'role' => 'editor', 'mode' => 'remove',
    ]);
    expect(User::find($user->id())->hasRole('editor'))->toBeFalse();
});

it('add_user_to_group adds a user to a group', function (): void {
    $group = UserGroup::make('team')->title('Team');
    $group->save();

    $user = User::make()->email('grouped@example.com');
    $user->save();

    $result = (new AddUserToGroupAction())->execute(AutomationContext::make(), [
        'user_id' => $user->id(), 'group' => 'team', 'mode' => 'add',
    ]);

    expect($result->isSuccess())->toBeTrue();
    // Assert the operation was performed (group handle stored on the user).
    // isInGroup() additionally requires the group to resolve from the group
    // repo, which the file harness does not persist across requests.
    expect(User::find($user->id())->get('groups'))->toContain('team');
});

it('set_global_value sets a value on a global set', function (): void {
    tap(GlobalSet::make('social')->title('Social'))->save();

    $result = (new SetGlobalValueAction())->execute(AutomationContext::make(), [
        'global_set' => 'social',
        'key' => 'twitter',
        'value' => '@acme',
    ]);

    expect($result->isSuccess())->toBeTrue();
    expect(GlobalSet::findByHandle('social')->inDefaultSite()->get('twitter'))->toBe('@acme');
});

it('surfaces the native actions in the node library under the Statamic group', function (): void {
    $this->actingAsSuperUser();

    $actions = $this->getJson('/cp/automations/api/nodes')->assertOk()->json('data.actions');
    $handles = collect($actions)->pluck('handle')->all();

    foreach ([
        'publish_entry', 'unpublish_entry', 'delete_entry', 'create_term',
        'update_user', 'assign_user_role', 'add_user_to_group', 'set_global_value',
    ] as $handle) {
        expect($handles)->toContain($handle);
        expect(collect($actions)->firstWhere('handle', $handle)['group'])->toBe('Statamic');
    }
});
