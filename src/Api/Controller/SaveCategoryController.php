<?php

namespace ErnestDefoe\Giveaways\Api\Controller;

use Carbon\Carbon;
use ErnestDefoe\Giveaways\GiveawayCategory;
use ErnestDefoe\Giveaways\Support\SlugHelper;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST  /api/giveaway-categories         create  (giveaways.manage)
 * PATCH /api/giveaway-categories/{id}     update  (giveaways.manage)
 */
class SaveCategoryController implements RequestHandlerInterface
{
    public function __construct(protected TranslatorInterface $translator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('giveaways.manage');

        $id = Arr::get($request->getAttributes(), 'routeParameters.id');
        $attrs = (array) Arr::get((array) $request->getParsedBody(), 'data.attributes', []);

        $cat = $id ? GiveawayCategory::query()->findOrFail((int) $id) : new GiveawayCategory();

        if (array_key_exists('name', $attrs) || ! $id) {
            $name = trim((string) ($attrs['name'] ?? ''));
            if ($name === '') {
                throw new ValidationException(['name' => $this->translator->trans('ernestdefoe-giveaways.api.category_name_required')]);
            }
            $cat->name = mb_substr($name, 0, 100);
        }
        if (array_key_exists('color', $attrs)) {
            $color = trim((string) $attrs['color']);
            $cat->color = preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) ? $color : '#69c6b9';
        }
        if (array_key_exists('icon', $attrs)) {
            $icon = trim((string) $attrs['icon']);
            $cat->icon = $icon !== '' ? mb_substr($icon, 0, 60) : null;
        }
        if (array_key_exists('position', $attrs)) {
            $cat->position = (int) $attrs['position'];
        }

        if (! $cat->slug || array_key_exists('name', $attrs)) {
            $cat->slug = SlugHelper::unique($cat->name, fn ($s) => $this->slugExists($s, $cat->id), 'category');
        }
        if (! $id) {
            $cat->created_at = Carbon::now();
        }
        $cat->updated_at = Carbon::now();
        SlugHelper::saveWithUniqueSlug(
            fn () => $cat->save(),
            fn () => $cat->slug = SlugHelper::unique($cat->name, fn ($s) => $this->slugExists($s, $cat->id), 'category')
        );

        return new JsonResponse([
            'data' => [
                'id'       => (int) $cat->id,
                'name'     => $cat->name,
                'slug'     => $cat->slug,
                'color'    => $cat->color,
                'icon'     => $cat->icon,
                'position' => (int) $cat->position,
                'count'    => 0,
            ],
        ], $id ? 200 : 201);
    }

    private function slugExists(string $slug, $ignoreId): bool
    {
        return GiveawayCategory::where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }
}
