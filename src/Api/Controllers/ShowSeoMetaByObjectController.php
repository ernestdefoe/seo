<?php

namespace V17Development\FlarumSeo\Api\Controllers;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Events\Dispatcher;
use Flarum\Foundation\DispatchEventsTrait;
use Flarum\Foundation\ValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use V17Development\FlarumSeo\SeoMeta\SeoMeta;

/**
 * Show-or-create a SeoMeta row by polymorphic (object_type, object_id)
 * pair — corresponds to the v1 route `/seo_meta/{object_type}-{id}`.
 *
 * The admin "Edit SEO" panel hits this endpoint when the actor opens
 * the SEO drawer for a discussion / tag / page that doesn't have a row
 * yet. Find-or-create semantics keep the UX seamless — no separate
 * "create blank SEO entry" step before editing.
 *
 * Returns the same JSON:API shape as SeoMetaResource Show endpoint so
 * the admin frontend can treat the response uniformly.
 *
 * Authorization: admin-only via `seo.canConfigure` permission, mirroring
 * the v1 controller's check. Validation: object_type is allowlisted to
 * prevent arbitrary table-name injection downstream.
 */
class ShowSeoMetaByObjectController implements RequestHandlerInterface
{
    use DispatchEventsTrait;

    public const ALLOWED_OBJECT_TYPES = [
        'discussions',
        'users',
        'tags',
        'pages',
    ];

    public function __construct(Dispatcher $events)
    {
        $this->events = $events;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('seo.canConfigure');

        // Route params land in queryParams via RouteHandlerFactory.toController.
        $params = $request->getQueryParams();
        $objectType = (string) ($params['object_type'] ?? '');
        $id = $params['id'] ?? null;

        if (! in_array($objectType, self::ALLOWED_OBJECT_TYPES, true)) {
            throw new ValidationException([
                'object_type' => 'object_type must be one of: ' . implode(', ', self::ALLOWED_OBJECT_TYPES),
            ]);
        }
        if ($id === null || ! is_numeric($id)) {
            throw new ValidationException(['id' => 'id must be numeric']);
        }

        $seoMeta = SeoMeta::findByObjectTypeOrCreate($objectType, (int) $id);

        $this->dispatchEventsFor($seoMeta, $actor);

        // Manually serialize to the same shape as SeoMetaResource so the
        // admin frontend's store can hydrate the result uniformly.
        return new JsonResponse([
            'data' => [
                'type'       => 'seoMeta',
                'id'         => (string) $seoMeta->id,
                'attributes' => $this->serializeAttributes($seoMeta),
            ],
        ]);
    }

    protected function serializeAttributes(SeoMeta $m): array
    {
        return [
            'objectType'           => $m->object_type,
            'objectId'             => (int) $m->object_id,
            'autoUpdateData'       => (bool) $m->auto_update_data,
            'title'                => $m->title,
            'description'          => $m->description,
            'keywords'             => $m->keywords,
            'robotsNoindex'        => (bool) $m->robots_noindex,
            'robotsNofollow'       => (bool) $m->robots_nofollow,
            'robotsNoarchive'      => (bool) $m->robots_noarchive,
            'robotsNoimageindex'   => (bool) $m->robots_noimageindex,
            'robotsNosnippet'      => (bool) $m->robots_nosnippet,
            'twitterTitle'         => $m->twitter_title,
            'twitterDescription'   => $m->twitter_description,
            'twitterImage'         => $m->twitter_image,
            'twitterImageSource'   => $m->twitter_image_source ?? 'auto',
            'openGraphTitle'       => $m->open_graph_title,
            'openGraphDescription' => $m->open_graph_description,
            'openGraphImage'       => $m->open_graph_image,
            'openGraphImageSource' => $m->open_graph_image_source ?? 'auto',
            'estimatedReadingTime' => (int) $m->estimated_reading_time,
            'createdAt'            => $m->created_at?->toIso8601String(),
            'updatedAt'            => $m->updated_at?->toIso8601String(),
        ];
    }
}
