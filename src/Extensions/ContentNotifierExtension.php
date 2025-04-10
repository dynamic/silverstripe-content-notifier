<?php

namespace SilverStripe\ContentNotifier\Extensions;

use RuntimeException;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Core\Config\Config;
use SilverStripe\ContentNotifier\ContentNotifier;
use SilverStripe\ContentNotifier\Model\ContentNotifierEmail;
use SilverStripe\ContentNotifier\Model\ContentNotifierQueue;
use SilverStripe\Control\Director;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Security\Permission;
use UncleCheese\BetterButtons\Actions\BetterButtonCustomAction;


class ContentNotifierExtension extends Extension
{
    /**
     * @var array|string[]
     */
    private static array $db = [
        'ContentNotifierApproved' => 'Boolean',
    ];

    /**
     * @var array|string[]
     */
    private static array $better_buttons_actions = [
        'approve',
        'deny',
    ];

    /**
     * @var bool
     */
    protected static bool $filter_unapproved = true;

    /**
     * @param $class
     * @param $extension
     * @param $args
     * @return void
     */
    public static function get_extra_config($class, $extension, $args): void
    {
        $singleton = $class::singleton();

        if(!$singleton->hasMethod('getContentNotifierExcerpt')){
            throw new RuntimeException("$class must implement getContentNotifierExcerpt to be used by the ContentNotifierExtension");
        }

        if(!$singleton->hasMethod('getContentNotifierLink')){
            throw new RuntimeException("$class must implement getContentNotifierLink to be used by the ContentNotifierExtension");
        }

        if(!$singleton->hasMethod('getContentNotifierHeadLine')){
            throw new RuntimeException("$class must implement getContentNotifierHeadLine to be used by the ContentNotifierExtension");
        }
    }

    /**
     * @return void
     */
    public static function enable_filtering(): void
    {
        self::$filter_unapproved = true;
    }

    /**
     * @return void
     */
    public static function disable_filtering(): void
    {
        self::$filter_unapproved = false;
    }

    /**
     * @param $actions
     * @return void
     */
    public function updateBetterButtonsActions($actions): void
    {
        /*if ($this->getOwner()->ContentNotifierApproved) {
            $actions->push(new BetterButtonCustomAction(
                'deny',
                'Deny'
            ));
        } else {
            $actions->push(new BetterButtonCustomAction(
                'approve',
                'Approve'
            ));
        }*/
    }

    /**
     * @param $approved
     * @return void
     */
    protected function resolve($approved): void
    {
        $this->getOwner()->ContentNotifierApproved = $approved;
        $this->getOwner()->write();

        if ($this->getSetting('delete_on_resolve')) {
            if ($object = $this->getQueue()) {
                $object->delete();
            }
        }
    }

    /**
     * Returns the ContentNotifier setting (note: not fully qualified)
     *
     * @param string $setting
     * @return string|false
     */
    protected function getSetting($setting): string|bool
    {
        $config = Config::inst()->get(get_class($this->owner), ContentNotifier::class);

        return $config[$setting] ?? false;
    }

    /**
     * @param $type
     * @return bool
     */
    protected function shouldAutoApprove($type): bool
    {
        $autoApprove = $this->getSetting('auto_approve');
        if ($autoApprove) {
            return ($autoApprove == "*") || (strtolower($autoApprove) == strtolower($type));
        }

        return false;
    }

    /**
     * @return void
     */
    public function approve(): void
    {
        $this->resolve(true);
    }

    /**
     * @return void
     */
    public function deny(): void
    {
        $this->resolve(false);
    }

    /**
     * @return DBHTMLText
     */
    public function EmailSummary(): DBHTMLText
    {
        $template = $this->getSetting('email_notifier_template') ?: Config::inst()
            ->get(ContentNotifier::class, 'item_template');

        return $this->getOwner()->renderWith($template);
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->getOwner()->ContentNotifierApproved
            ? _t('ContentNotifier.APPROVED', 'APPROVED')
            : _t('ContentNotifier.UNAPPROVED', 'UNAPPROVED');
    }

    /**
     * @return void
     */
    public function onBeforeWrite(): void
    {
        // Prevent CMS actions or updates being overridden
        if ($this->checkPermission()) {
            $this->getOwner()->ContentNotifierApproved = true;
        }

        // If creating a dataobject for the first time, auto-approve if allowed
        if (!$this->getOwner()->isInDB()) {
            $this->getOwner()->isCreating = true;

            // New records can approve themselves
            if ($this->shouldAutoApprove('CREATED')) {
                $this->getOwner()->ContentNotifierApproved = true;
            }

            return;
        }

        // If editing a record, allow auto unapproval
        if (!$this->getOwner()->isChanged('ContentNotifierApproved')) {
            // Adjust approvel only if not changed explicitly
            $this->getOwner()->ContentNotifierApproved = $this->shouldAutoApprove('UPDATED');
        }
    }

    /**
     * @return void
     * @throws ValidationException
     */
    public function onAfterWrite(): void
    {
        // Trigger events after approval state changes.
        if ($this->getOwner()->isChanged('ContentNotifierApproved', DataObject::CHANGE_VALUE)) {
            if ($this->getOwner()->ContentNotifierApproved) {
                $this->getOwner()->invokeWithExtensions('onAfterContentNotifierApprove');
            } else {
                $this->getOwner()->invokeWithExtensions('onAfterContentNotifierUnapprove');
            }
        }

        // Note: this has an effect that privileged user's showcase submissions will not show up in the queue.
        if ($this->checkPermission()) {
            return;
        }

        if ($this->getOwner()->isCreating) {
            $this->createQueue('CREATED');
        } elseif ($this->getOwner()->isChanged()) {
            // Clear any existing entry
            if ($queue = $this->getQueue('UPDATED')) {
                $queue->delete();
            }
            $this->createQueue('UPDATED');
        }

        if (!$this->getSetting('batch_email')) {
            $email = ContentNotifierEmail::create();
            $email->setRecords(ContentNotifierQueue::get_unnotified());
            $email->send();
        }
    }

    /**
     * @return void
     */
    public function onAfterDelete(): void
    {
        ContentNotifierQueue::get()->filter([
            'RecordClass' => get_class($this->owner),
            'RecordID' => $this->getOwner()->ID ?: 0,
        ])->removeAll();
    }

    /**
     * @param SQLSelect $query
     * @param DataQuery|null $dataQuery
     * @return void
     */
    public function augmentSQL(SQLSelect $query, DataQuery $dataQuery = null): void
    {
        if (!$this->checkPermission() && self::$filter_unapproved) {
            $query->addWhere("ContentNotifierApproved = 1");
        }
    }

    /**
     * @param $event
     * @return int
     * @throws ValidationException
     */
    protected function createQueue($event): int
    {
        return ContentNotifierQueue::create([
            'RecordClass' => get_class($this->owner),
            'Event' => $event,
            'RecordID' => $this->getOwner()->ID,
        ])->write();
    }

    /**
     * @param $event
     * @return ContentNotifierQueue|DataObject|null
     */
    public function getQueue($event = null): ContentNotifierQueue|DataObject|null
    {
        $list = ContentNotifierQueue::get()->filter([
            'RecordClass' => get_class($this->owner),
            'RecordID' => $this->getOwner()->ID ?: 0,
        ]);

        if ($event) {
            $list = $list->filter('Event', $event);
        }
        return $list->first();
    }

    /**
     * @return bool
     */
    protected function checkPermission(): bool
    {
        if (Director::is_cli()) {
            return false;
        }

        $perm = Config::inst()->get(__CLASS__, 'admin_permission');
        $cms = is_subclass_of(get_class(Controller::curr()), LeftAndMain::class);

        return Permission::check($perm) || $cms;
    }
}
