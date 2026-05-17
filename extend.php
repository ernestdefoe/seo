<?php

namespace V17Development\FlarumSeo;

use Flarum\Api\Endpoint;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion as FlarumDiscussion;
use Flarum\Extend;
use V17Development\FlarumSeo\Api\Controllers\DeleteSocialMediaImageController;
use V17Development\FlarumSeo\Api\Controllers\ShowSeoMetaByObjectController;
use V17Development\FlarumSeo\Api\Controllers\UploadSocialMediaImageController;
use V17Development\FlarumSeo\Api\Resource\SeoMetaResource;
use V17Development\FlarumSeo\ConfigureLinks;
use V17Development\FlarumSeo\Controller\Robots;
use V17Development\FlarumSeo\Formatter\FormatLinks;
use V17Development\FlarumSeo\Extend\SEO;
use V17Development\FlarumSeo\Listeners\PageListener;
use V17Development\FlarumSeo\Page as SeoPage;
use V17Development\FlarumSeo\SeoMeta\SeoMeta;

/**
 * Flarum 2 wiring for ernestdefoe/seo.
 *
 * Migration from the upstream v17development/flarum-seo (Flarum 1.x):
 *   - Removed:  Extend\ApiSerializer + Extend\ApiController extenders
 *               (those classes don't exist in Flarum 2 core).
 *   - Added:    Extend\ApiResource(...)->fields() callbacks that mount
 *               our SEO fields onto the v2 DiscussionResource and
 *               ForumResource. ApiResource(SeoMetaResource::class)
 *               registers the standalone SEO Meta CRUD surface.
 *   - Replaced: AbstractListController / AbstractShowController /
 *               AbstractDeleteController controllers with
 *               SeoMetaResource (Endpoints) for CRUD, and plain
 *               RequestHandlerInterface controllers for non-CRUD
 *               (image upload/delete + find-or-create by object type).
 *
 * The downstream page rendering pipeline (PageListener Content injector,
 * the per-route Page drivers in src/Page, the event subscribers, and the
 * SeoMeta companion table) was already v2-compatible and is unchanged.
 */

$events = (new Extend\Event())
    ->subscribe(Subscribers\DiscussionSubscriber::class)
    ->subscribe(Subscribers\PostSubscriber::class);

if (class_exists(\Flarum\Tags\Tag::class)) {
    $events->subscribe(Subscribers\TagSubscriber::class);
}

return [
    (new Extend\Frontend('forum'))
        ->content(PageListener::class)
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/Forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/Admin.less'),

    new Extend\Locales(__DIR__ . '/locale'),

    (new Extend\Routes('forum'))
        ->get('/robots.txt', 'ernestdefoe-seo.robots', Robots::class),

    (new Extend\Routes('api'))
        ->post('/seo_social_media_image', 'seo.socialmedia.upload', UploadSocialMediaImageController::class)
        ->delete('/seo_social_media_image', 'seo.socialmedia.delete', DeleteSocialMediaImageController::class)
        // Polymorphic find-or-create lookup — admin opens the SEO drawer
        // for a discussion/tag/page that doesn't yet have a SeoMeta row.
        ->get('/seo_meta/by-object/{object_type}/{id:\d+}', 'seo_meta.by_object', ShowSeoMetaByObjectController::class),

    (new Extend\Formatter())
        ->render(FormatLinks::class)
        ->configure(ConfigureLinks::class),

    /**
     * Per-discussion SeoMeta relationship (was Extend\Model in v1, but in
     * v2 the relation method goes on the model via Extend\Model AND the
     * resource-level wiring [includable, eager-loaded] lands on the
     * DiscussionResource via Extend\ApiResource.
     */
    (new Extend\Model(FlarumDiscussion::class))
        ->relationship('seoMeta', function (AbstractModel $model) {
            return $model->hasOne(SeoMeta::class, 'object_id', 'id')
                ->where('object_type', 'discussions');
        }),

    /**
     * SeoMeta JSON:API resource — replaces the v1 ListSeoMetaController +
     * ShowSeoMetaController + UpdateSeoMetaController + SeoMetaSerializer
     * quartet with a single Resource that declares Endpoints + Schema
     * fields + writable allowlist inline.
     */
    new Extend\ApiResource(SeoMetaResource::class),

    /**
     * Mount the seoMeta relationship onto DiscussionResource so SPA
     * requests for `?include=seoMeta` resolve cleanly. The endpoint-level
     * `eagerLoad('seoMeta')` plug ensures a single JOIN/companion query
     * per request instead of an N+1 (§38.1).
     */
    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(fn () => [
            Schema\Relationship\ToOne::make('seoMeta')
                ->type('seoMeta')
                ->includable(),
        ])
        ->endpoint(Endpoint\Show::class, fn (Endpoint\Show $endpoint) => $endpoint->eagerLoad('seoMeta'))
        ->endpoint(Endpoint\Index::class, fn (Endpoint\Index $endpoint) => $endpoint->eagerLoad('seoMeta')),

    /**
     * Expose `canConfigureSeo` on the forum payload so the admin frontend
     * can gate the "Edit SEO" drawer at the menu level. Was an
     * AttachForumSerializerAttributes invokable + Extend\ApiSerializer in
     * v1.
     */
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canConfigureSeo')
                ->get(fn ($model, $context) =>
                    $context->getActor()->hasPermissionLike('seo.canConfigure')),
        ]),

    (new SEO())
        ->addExtender('index', SeoPage\IndexPage::class)
        ->addExtender('profile', SeoPage\ProfilePage::class)
        ->addExtender('tags', SeoPage\TagPage::class)
        ->addExtender('page_extension', SeoPage\PageExtensionPage::class)
        ->addExtender('discussion', SeoPage\DiscussionPage::class)
        ->addExtender('discussion_best_answer', SeoPage\DiscussionBestAnswerPage::class),

    $events,
];
