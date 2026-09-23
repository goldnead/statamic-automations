<?php

namespace Goldnead\StatamicAutomations\Integrations\Courses\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * What the twelve course triggers share.
 *
 * The courses addon's events carry ids, not models: `userId`, `courseId`,
 * `courseSlug`, sometimes a lesson slug. A mail needs an address and a name, so
 * the learner is looked up here, once, when the run starts, and lands under
 * `user`. `user.email` is one of the default subject paths, which makes
 * "only once per person" work on every course trigger without configuring a
 * subject key.
 *
 * Every lookup is allowed to fail. A user deleted between the event and the
 * run, a course entry in a collection that moved: the run still starts, with
 * the ids it was given and empty fields where the lookup found nothing.
 */
abstract class CourseTrigger implements AutomationTrigger
{
    /** Whether this trigger's event names a lesson, which adds the lesson filter. */
    protected static bool $hasLesson = false;

    /** Whether the run is about the learner, who must then be found. */
    protected static bool $needsLearner = true;

    /** @var array<string, array{id: string|null, email: string|null, name: string|null}> */
    private array $users = [];

    public static function group(): string
    {
        return 'Courses';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        $schema = [
            [
                'handle' => 'course',
                'label' => 'Course',
                'type' => 'select',
                'options_source' => 'courses.courses',
                'required' => false,
                'help' => 'Leave empty for every course.',
            ],
        ];

        if (static::$hasLesson) {
            $schema[] = [
                'handle' => 'lesson',
                'label' => 'Lesson',
                'type' => 'text',
                'required' => false,
                'help' => 'The lesson slug. Leave empty for every lesson.',
            ];
        }

        return $schema;
    }

    public function matches(object|array $event, array $config): bool
    {
        // A learner who cannot be found has no address, so the run would have
        // no subject: "only once per person" would not hold and a mail would
        // have nobody to go to. Skipped, and said so, rather than started
        // half-empty. The team triggers are about the member's address and
        // switch this off.
        if (static::$needsLearner && ($this->userOf($this->userIdOf($event))['email'] ?? null) === null) {
            Log::warning('Automations: course event for a learner who cannot be found; skipped.', [
                'trigger' => static::handle(),
                'user_id' => $this->userIdOf($event),
                'course' => $this->courseIds($event)['slug'],
            ]);

            return false;
        }

        $course = $this->configured($config, 'course');

        if ($course !== null) {
            $ids = $this->courseIds($event);

            if (! in_array($course, array_filter([$ids['id'], $ids['slug']], 'is_string'), true)) {
                return false;
            }
        }

        $lesson = static::$hasLesson ? $this->configured($config, 'lesson') : null;

        return $lesson === null || $this->lessonSlug($event) === $lesson;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make($this->context($event));
    }

    /**
     * The run context for this trigger's event.
     *
     * @return array<string, mixed>
     */
    abstract protected function context(object|array $event): array;

    /**
     * @return array<string, string>
     */
    protected static function userOutputSchema(): array
    {
        return ['id' => 'string', 'email' => 'string', 'name' => 'string'];
    }

    /**
     * @return array<string, string>
     */
    protected static function courseOutputSchema(): array
    {
        return ['id' => 'string', 'slug' => 'string', 'title' => 'string'];
    }

    /**
     * @return array<string, string>
     */
    protected static function lessonOutputSchema(): array
    {
        return ['slug' => 'string', 'section' => 'string'];
    }

    /**
     * The learner, looked up by the id the event carries.
     *
     * @return array{id: string|null, email: string|null, name: string|null}
     */
    protected function userOf(?string $id): array
    {
        // Asked twice per event (matches, then buildContext); looked up once.
        if ($id !== null && isset($this->users[$id])) {
            return $this->users[$id];
        }

        $email = null;
        $name = null;

        if ($id !== null && $id !== '') {
            try {
                $user = User::find($id);

                if ($user !== null) {
                    $email = $user->email() ?: null;
                    // `name()` is on Statamic's user class, not on its
                    // contract; a custom user repository may not have it.
                    $name = method_exists($user, 'name') ? ($user->name() ?: null) : null;
                }
            } catch (\Throwable) {
                // Documented above: a missing user is an empty field, not a
                // failed run.
            }
        }

        $found = ['id' => $id, 'email' => $email, 'name' => $name];

        // Only the last one: the trigger instance lives as long as the worker,
        // and an address changed an hour ago must not come back from here.
        $this->users = $id === null ? [] : [$id => $found];

        return $found;
    }

    /**
     * @return array{id: string|null, slug: string|null, title: string|null}
     */
    protected function courseOf(object|array $event): array
    {
        $ids = $this->courseIds($event);
        $title = null;

        if ($ids['id'] !== null) {
            try {
                $entry = Entry::find($ids['id']);
                $title = $entry !== null && method_exists($entry, 'value') ? $entry->value('title') : null;
            } catch (\Throwable) {
                $title = null;
            }
        }

        return [
            'id' => $ids['id'],
            'slug' => $ids['slug'],
            'title' => is_string($title) && $title !== '' ? $title : null,
        ];
    }

    /**
     * Course id and slug, from the event or from the lesson state it carries.
     *
     * @return array{id: string|null, slug: string|null}
     */
    protected function courseIds(object|array $event): array
    {
        $state = $this->read($event, 'state');

        return [
            'id' => $this->stringOf($this->read($event, 'courseId') ?? $this->read($state, 'course_entry_id')),
            'slug' => $this->stringOf($this->read($event, 'courseSlug') ?? $this->read($state, 'course_slug')),
        ];
    }

    protected function userIdOf(object|array $event): ?string
    {
        return $this->stringOf($this->read($event, 'userId') ?? $this->read($this->read($event, 'state'), 'user_id'));
    }

    protected function lessonSlug(object|array $event): ?string
    {
        return $this->stringOf($this->read($event, 'lessonSlug') ?? $this->read($this->read($event, 'state'), 'lesson_slug'));
    }

    /**
     * @return array{slug: string|null, section: string|null}
     */
    protected function lessonOf(object|array $event): array
    {
        return [
            'slug' => $this->lessonSlug($event),
            'section' => $this->stringOf($this->read($this->read($event, 'state'), 'section_title')),
        ];
    }

    /**
     * One property off an object or an array, or null. These classes load on
     * sites without the courses addon, so a missing shape is a normal case.
     */
    protected function read(mixed $source, string $key): mixed
    {
        if (is_array($source)) {
            return $source[$key] ?? null;
        }

        return is_object($source) ? ($source->{$key} ?? null) : null;
    }

    protected function stringOf(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function intOf(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function configured(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
