<?php

namespace SilverStripe\ContentNotifier\Admin;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\ContentNotifier\Model\ContentNotifierQueue;

class ContentNotifierAdmin extends ModelAdmin
{
    /**
     * @var array|array[]
     */
    private static array $managed_models = [
        ContentNotifierQueue::class => [
            'title' => 'Notifications',
        ],
    ];

    /**
     * @var string
     */
    private static string $menu_title = 'Content Notifications';

    /**
     * @var string
     */
    private static string $url_segment = 'content-notifications';
}
